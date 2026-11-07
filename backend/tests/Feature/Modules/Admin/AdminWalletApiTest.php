<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\IdentityAccess\Enums\KycStatus;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Dépôts et retraits reçus hors plateforme, enregistrés par le Super Admin (wallet.adjust). */
class AdminWalletApiTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->superAdmin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();
        // Identité vérifiée : ces tests portent sur le solde ; le contrôle KYC des retraits et dépôts est dans KycTest.
        $this->player = User::factory()->create();
        $this->player->forceFill(['kyc_status' => KycStatus::Approved, 'kyc_verified_at' => now()])->save();
    }

    /** @param array<string, mixed> $body */
    private function adjust(array $body, ?User $as = null, ?string $key = null): TestResponse
    {
        return $this->actingAsVerifiedAdmin($as ?? $this->superAdmin)
            ->postJson("/api/v1/admin/users/{$this->player->id}/wallet/adjustments", $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function balance(): int
    {
        $ledger = app(LedgerService::class);

        return $ledger->balance($ledger->walletFor($this->player))->minor;
    }

    public function test_super_admin_records_a_deposit_with_reason_and_audit(): void
    {
        $this->adjust(['type' => 'deposit', 'amount_minor' => 2_500_00, 'reason' => 'Dépôt MonCash reçu'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'deposit')
            ->assertJsonPath('data.amount.minor', 2_500_00)
            ->assertJsonPath('data.balance_after.minor', 2_500_00)
            ->assertJsonPath('data.description', 'Dépôt MonCash reçu')
            ->assertJsonPath('data.created_by', (string) $this->superAdmin->id);

        $this->assertSame(2_500_00, $this->balance());
        $audit = AuditLog::where('action', 'wallet.deposited')->sole();
        $this->assertSame('Dépôt MonCash reçu', $audit->reason);
        $this->assertSame($this->superAdmin->id, $audit->actor_id);

        $this->actingAsVerifiedAdmin($this->superAdmin)->getJson("/api/v1/admin/users/{$this->player->id}/wallet")
            ->assertOk()->assertJsonPath('data.balance.minor', 2_500_00)->assertJsonPath('data.status', 'active');
    }

    public function test_the_player_sees_the_deposit_and_withdrawal_in_their_history(): void
    {
        $this->adjust(['type' => 'deposit', 'amount_minor' => 1_000_00, 'reason' => 'Dépôt en agence'])->assertCreated();
        $this->adjust(['type' => 'withdrawal', 'amount_minor' => 400_00, 'reason' => 'Retrait remis en agence'])
            ->assertCreated()->assertJsonPath('data.amount.minor', -400_00)->assertJsonPath('data.balance_after.minor', 600_00);

        $this->actingAs($this->player)->getJson('/api/v1/wallet/transactions')
            ->assertOk()->assertJsonPath('data.0.type', 'withdrawal')->assertJsonPath('data.1.type', 'deposit');
        $this->actingAs($this->player)->getJson('/api/v1/wallet')->assertJsonPath('data.balance.minor', 600_00);
        $this->assertSame(1, AuditLog::where('action', 'wallet.withdrawn')->count());
    }

    public function test_a_withdrawal_above_the_balance_is_refused(): void
    {
        $this->adjust(['type' => 'deposit', 'amount_minor' => 100_00, 'reason' => 'Dépôt en agence'])->assertCreated();

        $this->adjust(['type' => 'withdrawal', 'amount_minor' => 100_01, 'reason' => 'Retrait trop élevé'])
            ->assertStatus(422)->assertJsonPath('error.code', 'insufficient_funds');
        $this->assertSame(100_00, $this->balance());
        $this->assertSame(0, AuditLog::where('action', 'wallet.withdrawn')->count());
    }

    public function test_a_replayed_request_is_recorded_once(): void
    {
        $key = (string) Str::uuid();
        $body = ['type' => 'deposit', 'amount_minor' => 500_00, 'reason' => 'Dépôt MonCash reçu'];

        $first = $this->adjust($body, key: $key)->assertCreated()->json('data.id');
        $this->adjust($body, key: $key)->assertCreated()->assertJsonPath('data.id', $first);

        $this->assertSame(1, LedgerEntry::where('type', LedgerEntryType::Deposit)->count());
        $this->assertSame(500_00, $this->balance());
    }

    public function test_input_is_validated(): void
    {
        $this->adjust(['type' => 'simulated_credit', 'amount_minor' => 0, 'reason' => 'ok'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['type', 'amount_minor', 'reason']]]);
        $this->adjust(['type' => 'deposit', 'amount_minor' => 100_000_001, 'reason' => 'Montant trop élevé'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['amount_minor']]]);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_an_admin_account_cannot_be_credited(): void
    {
        $this->player = User::factory()->withRole(RoleSlug::Finance)->create();

        $this->adjust(['type' => 'deposit', 'amount_minor' => 100_00, 'reason' => 'Crédit interdit'])
            ->assertStatus(422)->assertJsonPath('error.code', 'not_a_player');
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_finance_cannot_record_a_deposit(): void
    {
        $this->adjust(['type' => 'deposit', 'amount_minor' => 100_00, 'reason' => 'Dépôt MonCash reçu'], User::factory()->withRole(RoleSlug::Finance)->create())
            ->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->assertSame(0, LedgerEntry::count());
    }
}
