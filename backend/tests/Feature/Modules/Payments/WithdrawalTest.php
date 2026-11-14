<?php

namespace Tests\Feature\Modules\Payments;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Payments\Contracts\KycVerifier;
use App\Modules\Payments\Enums\PaymentDirection;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * V2-B03 / V2-B05 : retraits du joueur (contrat de l'API), versés automatiquement — réservation, annulation,
 * versement (prestataire simulé), montant rendu par une écriture reversal si refus, audit.
 */
class WithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();

        config([
            'wallet.simulated' => true,
            'payments.providers.fake.enabled' => true,
            'payments.providers.fake.secret' => 'secret-de-test',
            'payments.withdrawals.enabled' => true,
            'payments.withdrawals.provider' => 'fake',
            'payments.withdrawals.require_kyc' => false,
        ]);
        $this->player = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($this->player), Money::ofMinor(1_000_00, 'HTG'), 'test:gains');
    }

    private function withdraw(int $amountMinor = 400_00, string $recipient = '34123456', string $method = 'moncash', ?User $as = null, string $provider = 'fake'): TestResponse
    {
        return $this->actingAs($as ?? $this->player)->postJson('/api/v1/payments/withdrawals',
            ['provider' => $provider, 'method' => $method, 'recipient' => $recipient, 'amount_minor' => $amountMinor],
            ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function cancel(string $reference, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->player)->postJson("/api/v1/payments/withdrawals/{$reference}/cancel", [],
            ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function balance(): int
    {
        return $this->actingAs($this->player)->getJson('/api/v1/wallet')->json('data.balance.minor');
    }

    public function test_the_player_sees_the_withdrawal_methods_and_amounts(): void
    {
        $this->actingAs($this->player)->getJson('/api/v1/payments/methods')
            ->assertJsonPath('data.withdrawal.methods', [
                ['provider' => 'fake', 'method' => 'moncash', 'label' => 'MonCash'],
                ['provider' => 'fake', 'method' => 'natcash', 'label' => 'NatCash'],
            ])
            ->assertJsonPath('data.withdrawal.min_amount.minor', 100_00)
            ->assertJsonPath('data.withdrawal.max_amount.minor', 50_000_00);

        config(['payments.withdrawals.enabled' => false]);
        $this->actingAs($this->player)->getJson('/api/v1/payments/methods')->assertJsonPath('data.withdrawal.methods', []);
        $this->withdraw()->assertUnprocessable()->assertJsonPath('error.code', 'payment_provider_unavailable');
    }

    public function test_a_request_reserves_the_amount_then_the_payout_confirms_it(): void
    {
        $response = $this->withdraw()->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.provider', 'fake')
            ->assertJsonPath('data.amount', ['minor' => 400_00, 'currency' => 'HTG'])
            ->assertJsonPath('data.method_label', 'MonCash')
            ->assertJsonPath('data.recipient', '****3456')
            ->assertJsonPath('data.cancellable', true);
        $reference = $response->json('data.reference');
        $this->assertStringStartsWith('pay_', $reference);
        $this->assertSame('50934123456', PaymentTransaction::sole()->metadata['recipient']);

        // Montant réservé dès la demande.
        $this->assertSame(600_00, $this->balance());
        $entry = LedgerEntry::where('type', LedgerEntryType::Withdrawal)->sole();
        $this->assertSame("payout:{$reference}", $entry->reference);
        $this->assertSame('Retrait MonCash', $entry->description);
        $this->assertSame(-400_00, $entry->amount_minor);

        $this->artisan('payments:payouts')->expectsOutputToContain('1 retrait(s) terminé(s).')->assertSuccessful();

        $this->actingAs($this->player)->getJson("/api/v1/payments/withdrawals/{$reference}")
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.cancellable', false);
        $this->assertSame(600_00, $this->balance()); // versé : rien n'est rendu
        $this->assertStringStartsWith('fake_wd_', PaymentTransaction::sole()->metadata['provider_transaction_id']);
        $this->assertSame(['withdrawal.paid', 'withdrawal.requested'], AuditLog::orderByDesc('id')->pluck('action')->map->value->all());

        // Une nouvelle passe ne verse rien de plus.
        $this->artisan('payments:payouts')->expectsOutputToContain('0 retrait(s) terminé(s).')->assertSuccessful();
    }

    public function test_a_payout_refused_by_the_provider_gives_the_money_back_by_a_reversal(): void
    {
        $reference = $this->withdraw(recipient: '34120000')->json('data.reference');
        $this->assertSame(600_00, $this->balance());

        $this->artisan('payments:payouts')->assertSuccessful();

        $this->actingAs($this->player)->getJson("/api/v1/payments/withdrawals/{$reference}")->assertJsonPath('data.status', 'failed');
        $this->assertSame(1_000_00, $this->balance());
        $reversal = LedgerEntry::where('type', LedgerEntryType::Reversal)->sole();
        $this->assertSame("payout:{$reference}:reversal", $reversal->reference);
        $this->assertSame(LedgerEntry::where('type', LedgerEntryType::Withdrawal)->value('id'), $reversal->reversal_of_id);
        $this->assertSame('withdrawal_failed', $reversal->metadata['reason']);
        $this->assertSame(1, AuditLog::where('action', 'withdrawal.failed')->count());
    }

    public function test_the_player_can_cancel_before_the_payout(): void
    {
        $reference = $this->withdraw()->json('data.reference');

        $this->cancel($reference)->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellable', false);
        $this->assertSame(1_000_00, $this->balance());
        $this->assertSame('withdrawal_cancelled', LedgerEntry::where('type', LedgerEntryType::Reversal)->sole()->metadata['reason']);
        $this->assertSame(1, AuditLog::where('action', 'withdrawal.cancelled')->count());

        $this->cancel($reference)->assertStatus(409)->assertJsonPath('error.code', 'withdrawal_not_cancellable');
        $this->artisan('payments:payouts')->assertSuccessful();
        $this->assertSame(PaymentStatus::Cancelled, PaymentTransaction::sole()->status); // jamais versé
        $this->assertSame(1_000_00, $this->balance());
    }

    public function test_a_paid_withdrawal_cannot_be_cancelled(): void
    {
        $reference = $this->withdraw()->json('data.reference');
        $this->artisan('payments:payouts')->assertSuccessful();

        $this->cancel($reference)->assertStatus(409);
        $this->assertSame(600_00, $this->balance());
    }

    public function test_only_one_withdrawal_at_a_time(): void
    {
        $first = $this->withdraw(100_00)->json('data.reference');

        $this->withdraw(100_00)->assertStatus(409)
            ->assertJsonPath('error.code', 'withdrawal_already_pending')
            ->assertJsonPath('error.details.reference', $first);

        $this->artisan('payments:payouts')->assertSuccessful(); // le premier est versé
        $this->withdraw(100_00, '34123456', 'natcash')->assertCreated()->assertJsonPath('data.method_label', 'NatCash');
        $this->assertSame(800_00, $this->balance());
    }

    public function test_the_balance_the_amount_and_the_wallet_number_are_checked(): void
    {
        $this->withdraw(1_000_01)->assertUnprocessable()->assertJsonPath('error.code', 'insufficient_funds');
        $this->withdraw(99_99)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['amount_minor']]]);
        $this->withdraw(50_000_01)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['amount_minor']]]);
        $this->withdraw(recipient: '1234')->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['recipient']]]);
        $this->travel(61)->seconds(); // 5 demandes par minute
        $this->withdraw(recipient: '50934123456')->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['recipient']]]);
        $this->withdraw(method: 'kashpaw')->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['method']]]);
        $this->withdraw(provider: 'plopplop')->assertUnprocessable()->assertJsonPath('error.code', 'payment_provider_unavailable');

        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame(1_000_00, $this->balance());
    }

    public function test_withdrawals_are_capped_over_24_hours(): void
    {
        config(['payments.withdrawals.daily_max_minor' => 500_00]);

        $this->withdraw(300_00)->assertCreated();
        $this->artisan('payments:payouts')->assertSuccessful();
        $this->withdraw(300_00)->assertUnprocessable()
            ->assertJsonPath('error.code', 'withdrawal_daily_limit_exceeded')
            ->assertJsonPath('error.details.remaining_minor', 200_00);

        $this->travel(25)->hours();
        $this->withdraw(300_00)->assertCreated();
    }

    public function test_once_enabled_kyc_blocks_withdrawals_until_the_kyc_module_is_plugged(): void
    {
        config(['payments.withdrawals.require_kyc' => true]);

        $this->withdraw()->assertUnprocessable()->assertJsonPath('error.code', 'kyc_required');
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_a_verified_player_withdraws_once_kyc_is_required(): void
    {
        config(['payments.withdrawals.require_kyc' => true]);
        // Le module KYC (V2-C03) fournira cette vérification.
        $this->app->instance(KycVerifier::class, new class implements KycVerifier
        {
            public function isVerified(User $user): bool
            {
                return true;
            }
        });

        $this->withdraw()->assertCreated();
    }

    public function test_only_a_player_withdraws_and_only_sees_their_withdrawals(): void
    {
        $this->getJson('/api/v1/payments/withdrawals')->assertUnauthorized();
        $reference = $this->withdraw()->json('data.reference');
        $other = User::factory()->create();

        $this->actingAs($other)->getJson('/api/v1/payments/withdrawals')->assertJsonCount(0, 'data');
        $this->actingAs($other)->getJson("/api/v1/payments/withdrawals/{$reference}")->assertNotFound();
        $this->cancel($reference, $other)->assertNotFound();

        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();
        $this->withdraw(as: $admin)->assertUnprocessable()->assertJsonPath('error.code', 'not_a_player');

        $this->actingAs($this->player)->postJson('/api/v1/payments/withdrawals', ['provider' => 'fake', 'method' => 'moncash', 'recipient' => '34123456', 'amount_minor' => 100_00])
            ->assertStatus(428);
    }

    public function test_deposit_jobs_never_touch_a_withdrawal(): void
    {
        $this->withdraw()->assertCreated();
        config(['payments.withdrawals.enabled' => false]); // pas de versement pendant ce test

        $this->travel(2)->hours();
        $this->artisan('payments:expire')->assertSuccessful();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, PaymentTransaction::where('direction', PaymentDirection::Payout)->sole()->status);
    }
}
