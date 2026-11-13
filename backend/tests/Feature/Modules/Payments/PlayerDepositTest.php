<?php

namespace Tests\Feature\Modules\Payments;

use App\Models\User;
use App\Modules\Compliance\Enums\ResponsiblePlayEventType;
use App\Modules\Compliance\Models\ResponsiblePlayEvent;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\WebhookEvent;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\Wallet;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * V2-B02 / V2-B05 : le joueur ouvre un dépôt, confirme sur la page du prestataire (simulé), et seul le webhook
 * signé qui en résulte crédite son solde, une seule fois.
 */
class PlayerDepositTest extends TestCase
{
    use RefreshDatabase;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();

        config(['payments.providers.fake.enabled' => true, 'payments.providers.fake.secret' => 'secret-de-test', 'app.frontend_url' => 'http://localhost:5173/']);
        $this->player = User::factory()->create();
    }

    private function open(int $amountMinor = 1_500_00, string $provider = 'fake', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->player)->postJson('/api/v1/payments/deposits',
            ['provider' => $provider, 'amount_minor' => $amountMinor], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function decide(string $reference, string $decision): TestResponse
    {
        return $this->post("/api/v1/payments/fake/checkout/{$reference}", ['decision' => $decision]);
    }

    private function balance(): int
    {
        return $this->actingAs($this->player)->getJson('/api/v1/wallet')->json('data.balance.minor');
    }

    public function test_the_player_sees_the_enabled_methods_and_the_accepted_amounts(): void
    {
        $this->actingAs($this->player)->getJson('/api/v1/payments/methods')->assertOk()
            ->assertJsonPath('data.methods', [['provider' => 'fake', 'label' => 'Paiement test']])
            ->assertJsonPath('data.min_amount', ['minor' => 100_00, 'currency' => 'HTG'])
            ->assertJsonPath('data.max_amount', ['minor' => 100_000_00, 'currency' => 'HTG']);

        config(['payments.providers.fake.enabled' => false]);
        $this->actingAs($this->player)->getJson('/api/v1/payments/methods')->assertJsonPath('data.methods', []);
    }

    public function test_a_confirmed_deposit_is_credited_once_through_the_signed_webhook(): void
    {
        $response = $this->open()->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.provider_label', 'Paiement test')
            ->assertJsonPath('data.amount', ['minor' => 1_500_00, 'currency' => 'HTG']);
        $reference = $response->json('data.reference');
        $this->assertSame("/api/v1/payments/fake/checkout/{$reference}", $response->json('data.redirect_url'));
        $this->assertSame(0, $this->balance()); // ouvrir un dépôt ne crédite rien

        // Page du prestataire : montant et boutons, CSP propre avec nonce.
        $page = $this->get($response->json('data.redirect_url'))->assertOk()->assertSee('Payer')->assertSee($reference);
        $this->assertMatchesRegularExpression("/style-src 'nonce-[A-Za-z0-9]+'/", $page->headers->get('Content-Security-Policy'));

        $this->decide($reference, 'approve')->assertStatus(303)->assertHeader('Location', "http://localhost:5173/wallet?depot={$reference}");

        $this->assertSame(1_500_00, $this->balance());
        $this->actingAs($this->player)->getJson("/api/v1/payments/deposits/{$reference}")->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.redirect_url', null);
        $entry = LedgerEntry::where('type', LedgerEntryType::Deposit)->sole();
        $this->assertSame("payment:{$reference}", $entry->reference);
        $this->assertSame('Dépôt Paiement test', $entry->description);
        $this->assertTrue(WebhookEvent::sole()->signature_valid);

        // Second envoi du formulaire (double clic, retour arrière) : aucun second crédit.
        $this->decide($reference, 'approve')->assertStatus(303);
        $this->get("/api/v1/payments/fake/checkout/{$reference}")->assertSee('déjà été effectué');
        $this->assertSame(1_500_00, $this->balance());
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_a_declined_deposit_credits_nothing(): void
    {
        $reference = $this->open()->json('data.reference');

        $this->decide($reference, 'decline')->assertStatus(303);

        $this->assertSame(PaymentStatus::Failed, PaymentTransaction::sole()->status);
        $this->assertSame(0, $this->balance());
        $this->actingAs($this->player)->getJson('/api/v1/payments/deposits')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'failed');
    }

    public function test_the_amount_and_the_provider_are_validated(): void
    {
        $this->open(99_99)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['amount_minor']]]);
        $this->open(100_000_01)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['amount_minor']]]);
        $this->open(provider: 'moncash')->assertUnprocessable()->assertJsonPath('error.code', 'payment_provider_unavailable');

        config(['payments.providers.fake.enabled' => false]);
        $this->open()->assertUnprocessable()->assertJsonPath('error.code', 'payment_provider_unavailable');
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_the_deposit_limit_counts_pending_deposits_and_the_refusal_is_logged(): void
    {
        $this->actingAs($this->player)->putJson('/api/v1/responsible-gaming/deposit-limits/day',
            ['amount_minor' => 1_000_00], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        $this->open(600_00)->assertCreated(); // en attente : compte dans la limite
        $this->open(500_00)->assertUnprocessable()
            ->assertJsonPath('error.code', 'deposit_limit_exceeded')
            ->assertJsonPath('error.details.remaining_minor', 400_00);
        $this->open(400_00)->assertCreated();

        $this->assertSame(2, PaymentTransaction::count());
        $this->assertSame(1, ResponsiblePlayEvent::where('type', ResponsiblePlayEventType::DepositRefusedLimit)->count());
    }

    public function test_a_frozen_wallet_or_an_admin_account_cannot_deposit(): void
    {
        Wallet::create(['user_id' => $this->player->id, 'currency' => 'HTG'])->forceFill(['status' => Wallet::STATUS_FROZEN])->save();
        $this->open()->assertStatus(423)->assertJsonPath('error.code', 'wallet_frozen');

        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();
        $this->open(as: $admin)->assertUnprocessable()->assertJsonPath('error.code', 'not_a_player');

        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_a_player_only_follows_their_own_deposits(): void
    {
        $reference = $this->open()->json('data.reference');
        $other = User::factory()->create();

        $this->actingAs($other)->getJson("/api/v1/payments/deposits/{$reference}")->assertNotFound();
        $this->actingAs($other)->getJson('/api/v1/payments/deposits')->assertJsonCount(0, 'data');
    }

    public function test_deposits_require_a_signed_in_player(): void
    {
        $this->getJson('/api/v1/payments/methods')->assertUnauthorized();
        $this->postJson('/api/v1/payments/deposits', ['provider' => 'fake', 'amount_minor' => 1_000_00])->assertUnauthorized();
    }

    public function test_the_simulated_checkout_does_not_exist_when_the_fake_provider_is_disabled(): void
    {
        $reference = $this->open()->json('data.reference');
        config(['payments.providers.fake.enabled' => false]);

        $this->get("/api/v1/payments/fake/checkout/{$reference}")->assertNotFound();
        $this->decide($reference, 'approve')->assertNotFound();
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_opening_a_deposit_requires_an_idempotency_key(): void
    {
        $this->actingAs($this->player)->postJson('/api/v1/payments/deposits', ['provider' => 'fake', 'amount_minor' => 1_000_00])
            ->assertStatus(428);
    }
}
