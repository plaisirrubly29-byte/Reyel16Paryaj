<?php

namespace Tests\Feature\Modules\Payments;

use App\Models\User;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\WebhookOutcome;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\WebhookEvent;
use App\Modules\Payments\Providers\FakePaymentProvider;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Http\ApiException;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

/** V2-B01 : webhooks signés et idempotents, journal des transactions, avec le faux prestataire. */
class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'secret-de-test';

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();

        config(['payments.providers.fake.enabled' => true, 'payments.providers.fake.secret' => self::SECRET]);
        $this->player = User::factory()->create();
    }

    private function startDeposit(int $amountMinor = 1_500_00): PaymentTransaction
    {
        return app(PaymentService::class)->startDeposit($this->player, Money::ofMinor($amountMinor, 'HTG'), 'fake');
    }

    /** @param  array<string, mixed>  $overrides */
    private function webhook(PaymentTransaction $transaction, array $overrides = [], ?string $signature = null): TestResponse
    {
        $body = json_encode($overrides + [
            'id' => 'evt_'.Str::random(10),
            'reference' => $transaction->reference,
            'provider_reference' => $transaction->provider_reference,
            'status' => 'succeeded',
            'amount_minor' => $transaction->amount_minor,
            'currency' => 'HTG',
        ]);

        return $this->call('POST', '/api/v1/payments/webhooks/fake', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FAKE_SIGNATURE' => $signature ?? FakePaymentProvider::signatureHeader($body, self::SECRET),
        ], content: $body);
    }

    private function balance(): int
    {
        $ledger = app(LedgerService::class);

        return $ledger->balance($ledger->walletFor($this->player))->minor;
    }

    public function test_a_deposit_is_opened_pending_with_the_provider_reference(): void
    {
        $transaction = $this->startDeposit();

        $this->assertSame(PaymentStatus::Pending, $transaction->status);
        $this->assertStringStartsWith('pay_', $transaction->reference);
        $this->assertStringStartsWith('fake_', $transaction->provider_reference);
        $this->assertTrue($transaction->expires_at->eq(now()->addMinutes(30)));
        $this->assertSame(0, $this->balance()); // aucun effet financier avant le webhook
    }

    public function test_a_signed_success_credits_the_wallet_once_even_when_replayed(): void
    {
        $transaction = $this->startDeposit();

        $this->webhook($transaction, ['id' => 'evt_1'])->assertOk()->assertJsonPath('data.outcome', 'applied');
        $this->webhook($transaction, ['id' => 'evt_1'])->assertOk()->assertJsonPath('data.outcome', 'applied'); // rejeu
        $this->webhook($transaction, ['id' => 'evt_2'])->assertOk()->assertJsonPath('data.outcome', 'ignored'); // nouvel envoi

        $this->assertSame(1_500_00, $this->balance());
        $entry = LedgerEntry::where('type', LedgerEntryType::Deposit)->sole();
        $this->assertSame("payment:{$transaction->reference}", $entry->reference);
        $this->assertSame(['payment', (string) $transaction->id], [$entry->related_type, $entry->related_id]);
        $this->assertSame('Dépôt Paiement test', $entry->description);

        $transaction->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $transaction->status);
        $this->assertSame($entry->id, $transaction->ledger_entry_id);
        $this->assertSame(2, WebhookEvent::count()); // le rejeu exact n'est pas réenregistré
    }

    public function test_a_bad_or_stale_signature_is_refused_and_logged(): void
    {
        $transaction = $this->startDeposit();

        $this->webhook($transaction, ['id' => 'evt_1'], signature: 't='.now()->getTimestamp().',v1='.str_repeat('0', 64))
            ->assertStatus(401)->assertJsonPath('error.code', 'invalid_signature');
        $this->webhook($transaction, ['id' => 'evt_1'], signature: 'absente')->assertStatus(401);

        // Corps signé il y a 10 minutes : rejeu d'une requête capturée.
        $body = json_encode(['id' => 'evt_1', 'reference' => $transaction->reference, 'status' => 'succeeded', 'amount_minor' => 1_500_00, 'currency' => 'HTG']);
        $this->webhook($transaction, ['id' => 'evt_1'], signature: FakePaymentProvider::signatureHeader($body, self::SECRET, now()->subMinutes(10)->getTimestamp()))
            ->assertStatus(401);

        $this->assertSame(0, $this->balance());
        $this->assertSame(3, WebhookEvent::where('signature_valid', false)->whereNull('event_id')->count());

        // L'identifiant d'un faux webhook ne bloque pas le vrai événement.
        $this->webhook($transaction, ['id' => 'evt_1'])->assertOk()->assertJsonPath('data.outcome', 'applied');
        $this->assertSame(1_500_00, $this->balance());
    }

    public function test_a_different_amount_or_currency_puts_the_transaction_in_review_without_credit(): void
    {
        $transaction = $this->startDeposit();

        $this->webhook($transaction, ['amount_minor' => 15_000_00])->assertOk()->assertJsonPath('data.outcome', 'review');

        $transaction->refresh();
        $this->assertSame(PaymentStatus::Review, $transaction->status);
        $this->assertSame('amount_mismatch', $transaction->review_reason);
        $this->assertSame(['amount_minor' => 15_000_00, 'currency' => 'HTG'], $transaction->metadata['reported']);
        $this->webhook($transaction)->assertOk()->assertJsonPath('data.outcome', 'ignored'); // revue : plus rien d'automatique
        $this->assertSame(0, $this->balance());
    }

    public function test_a_failure_is_recorded_without_credit(): void
    {
        $transaction = $this->startDeposit();

        $this->webhook($transaction, ['status' => 'pending'])->assertOk()->assertJsonPath('data.outcome', 'ignored');
        $this->webhook($transaction, ['status' => 'failed'])->assertOk()->assertJsonPath('data.outcome', 'applied');

        $this->assertSame(PaymentStatus::Failed, $transaction->refresh()->status);
        $this->webhook($transaction)->assertOk()->assertJsonPath('data.outcome', 'ignored');
        $this->assertSame(0, $this->balance());
    }

    public function test_a_success_after_expiry_is_still_credited_and_flagged(): void
    {
        $transaction = $this->startDeposit();
        $this->travel(31)->minutes();
        $this->artisan('payments:expire')->assertSuccessful();
        $this->assertSame(PaymentStatus::Expired, $transaction->refresh()->status);

        $this->webhook($transaction)->assertOk()->assertJsonPath('data.outcome', 'applied_late');

        $this->assertSame(1_500_00, $this->balance());
        $this->assertTrue($transaction->refresh()->metadata['late']);
    }

    public function test_unknown_transactions_payloads_and_providers(): void
    {
        $transaction = $this->startDeposit();

        $this->webhook($transaction, ['reference' => 'pay_inconnue'])->assertOk()->assertJsonPath('data.outcome', 'unknown_transaction');
        $this->webhook($transaction, ['status' => 'refunded'])->assertStatus(400)->assertJsonPath('error.code', 'invalid_payload');
        $this->postJson('/api/v1/payments/webhooks/moncash', [])->assertNotFound();

        config(['payments.providers.fake.enabled' => false]);
        $this->webhook($transaction)->assertNotFound();
        $this->assertSame(0, $this->balance());
    }

    public function test_a_disabled_provider_cannot_open_a_deposit(): void
    {
        config(['payments.providers.fake.enabled' => false]);

        try {
            $this->startDeposit();
            $this->fail('Dépôt ouvert avec un prestataire désactivé.');
        } catch (ApiException $e) {
            $this->assertSame('payment_provider_unavailable', $e->errorCode);
        }
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_the_webhook_journal_is_append_only(): void
    {
        $this->webhook($this->startDeposit())->assertOk();

        $this->expectException(LogicException::class);
        WebhookEvent::firstOrFail()->delete();
    }

    public function test_finance_reads_the_transactions_and_their_webhooks(): void
    {
        $this->seed(PermissionSeeder::class);
        $transaction = $this->startDeposit();
        $this->webhook($transaction, ['amount_minor' => 1_00])->assertOk();
        $this->startDeposit(200_00);
        $finance = User::factory()->withRole(RoleSlug::Finance)->create();

        $this->actingAsVerifiedAdmin($finance)->getJson('/api/v1/admin/payments?status=review')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $transaction->reference)
            ->assertJsonPath('data.0.review_reason', 'amount_mismatch')
            ->assertJsonMissingPath('data.0.webhooks');
        $this->actingAsVerifiedAdmin($finance)->getJson("/api/v1/admin/payments?reference={$transaction->provider_reference}")
            ->assertJsonCount(1, 'data');

        $this->actingAsVerifiedAdmin($finance)->getJson("/api/v1/admin/payments/{$transaction->id}")->assertOk()
            ->assertJsonPath('data.status', 'review')
            ->assertJsonPath('data.webhooks.0.outcome', WebhookOutcome::Review->value)
            ->assertJsonPath('data.webhooks.0.signature_valid', true);
    }
}
