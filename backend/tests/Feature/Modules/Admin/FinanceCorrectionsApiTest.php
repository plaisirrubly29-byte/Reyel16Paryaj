<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Admin\Enums\FinanceCorrectionStatus;
use App\Modules\Admin\Models\FinanceCorrection;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FinanceCorrectionsApiTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    private Wallet $wallet;

    private LedgerEntry $credit;

    private User $requester;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->ledger = app(LedgerService::class);
        $this->wallet = $this->ledger->walletFor(User::factory()->create());
        // Crédit attribué par erreur, à compenser.
        $this->credit = $this->ledger->creditSimulated($this->wallet, Money::ofMinor(500_00, 'HTG'), 'test:credit');
        $this->requester = User::factory()->withRole(RoleSlug::Finance)->create();
        $this->approver = User::factory()->withRole(RoleSlug::Finance)->create();
    }

    private function postAs(User $admin, string $uri, array $data = []): TestResponse
    {
        return $this->actingAsVerifiedAdmin($admin)->postJson($uri, $data, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function requestCorrection(?LedgerEntry $entry = null, ?User $admin = null): TestResponse
    {
        return $this->postAs($admin ?? $this->requester, '/api/v1/admin/finance/corrections', [
            'ledger_entry_id' => ($entry ?? $this->credit)->id,
            'reason' => 'Crédit attribué deux fois',
        ]);
    }

    private function decide(FinanceCorrection|string $correction, string $decision, User $admin): TestResponse
    {
        $id = $correction instanceof FinanceCorrection ? $correction->id : $correction;

        return $this->postAs($admin, "/api/v1/admin/finance/corrections/{$id}/{$decision}", ['reason' => 'Vérifié sur le relevé']);
    }

    private function balance(): int
    {
        return $this->ledger->balance($this->wallet)->minor;
    }

    public function test_a_request_writes_nothing_to_the_ledger_until_approved(): void
    {
        $this->requestCorrection()
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.ledger_entry.id', (string) $this->credit->id)
            ->assertJsonPath('data.requested_by.id', (string) $this->requester->id)
            ->assertJsonPath('data.decided_by', null)
            ->assertJsonPath('data.reversal_entry_id', null);

        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(500_00, $this->balance());

        $log = AuditLog::where('action', AuditAction::FinanceCorrectionRequested)->sole();
        $this->assertSame($this->requester->id, $log->actor_id);
        $this->assertSame('Crédit attribué deux fois', $log->reason);
    }

    public function test_a_correction_can_be_shown_with_its_entry(): void
    {
        $id = $this->requestCorrection()->json('data.id');
        $support = User::factory()->withRole(RoleSlug::Support)->create();

        $this->actingAsVerifiedAdmin($this->approver)->getJson("/api/v1/admin/finance/corrections/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.ledger_entry.amount.minor', 500_00)
            ->assertJsonPath('data.requested_by.name', $this->requester->name);
        $this->actingAsVerifiedAdmin($support)->getJson("/api/v1/admin/finance/corrections/{$id}")->assertForbidden();
        $this->actingAsVerifiedAdmin($this->approver)->getJson('/api/v1/admin/finance/corrections/999999')->assertNotFound();
    }

    public function test_the_requester_cannot_approve_their_own_correction(): void
    {
        $id = $this->requestCorrection()->json('data.id');

        $this->decide($id, 'approve', $this->requester)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'self_approval_forbidden');

        $this->assertSame(FinanceCorrectionStatus::Pending, FinanceCorrection::findOrFail($id)->status);
        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(0, AuditLog::where('action', AuditAction::FinanceCorrectionApproved)->count());
    }

    public function test_a_second_admin_approves_and_the_reversal_is_posted(): void
    {
        $id = $this->requestCorrection()->json('data.id');

        $response = $this->decide($id, 'approve', $this->approver)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decided_by.id', (string) $this->approver->id)
            ->assertJsonPath('data.decision_reason', 'Vérifié sur le relevé')
            ->assertJsonPath('data.ledger_entry.reversed_by_id', fn ($value) => $value !== null)
            ->assertJsonPath('data.ledger_entry.correctable', false);

        $reversal = LedgerEntry::findOrFail($response->json('data.reversal_entry_id'));
        $this->assertSame(LedgerEntryType::Reversal, $reversal->type);
        $this->assertSame(-500_00, $reversal->amount_minor);
        $this->assertSame($this->credit->id, $reversal->reversal_of_id);
        $this->assertSame($this->approver->id, $reversal->created_by);
        $this->assertSame(0, $this->balance());

        $log = AuditLog::where('action', AuditAction::FinanceCorrectionApproved)->sole();
        $this->assertSame($this->approver->id, $log->actor_id);
        $this->assertSame($this->requester->id, $log->metadata['requested_by']);

        // Déjà traitée : ni deuxième validation ni refus.
        $this->decide($id, 'approve', User::factory()->withRole(RoleSlug::SuperAdmin)->create())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'correction_not_pending');
        $this->decide($id, 'reject', $this->approver)->assertStatus(409);
        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_a_rejection_writes_nothing_and_the_requester_may_withdraw(): void
    {
        $first = $this->requestCorrection()->json('data.id');
        $this->decide($first, 'reject', $this->approver)->assertOk()->assertJsonPath('data.status', 'rejected');

        // Le demandeur peut retirer sa propre demande (refus), jamais la valider.
        $second = $this->requestCorrection()->assertCreated()->json('data.id');
        $this->decide($second, 'reject', $this->requester)->assertOk()->assertJsonPath('data.decided_by.id', (string) $this->requester->id);

        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(500_00, $this->balance());
        $this->assertSame(2, AuditLog::where('action', AuditAction::FinanceCorrectionRejected)->count());
    }

    public function test_only_one_pending_request_per_entry(): void
    {
        $id = $this->requestCorrection()->json('data.id');

        $this->requestCorrection(admin: $this->approver)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'correction_already_pending')
            ->assertJsonPath('error.details.correction_id', $id);
    }

    public function test_ticket_entries_reversals_and_reversed_entries_are_not_correctable(): void
    {
        $stake = $this->ledger->reserveStake($this->wallet, Money::ofMinor(100_00, 'HTG'), 'bet:1:stake', '1');
        $this->requestCorrection($stake)->assertUnprocessable()
            ->assertJsonPath('error.code', 'entry_not_correctable')
            ->assertJsonPath('error.details.reason', 'linked_to_ticket');

        $this->decide($this->requestCorrection()->json('data.id'), 'approve', $this->approver)->assertOk();
        $this->requestCorrection()->assertUnprocessable()->assertJsonPath('error.details.reason', 'already_reversed');

        $reversal = LedgerEntry::where('type', LedgerEntryType::Reversal)->sole();
        $this->requestCorrection($reversal)->assertUnprocessable()->assertJsonPath('error.details.reason', 'reversal_entry');
    }

    public function test_reasons_and_idempotency_key_are_required(): void
    {
        $this->postAs($this->requester, '/api/v1/admin/finance/corrections', ['ledger_entry_id' => $this->credit->id])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['reason']]]);

        $id = $this->requestCorrection()->json('data.id');
        $this->postAs($this->approver, "/api/v1/admin/finance/corrections/{$id}/approve")->assertUnprocessable();

        $this->actingAsVerifiedAdmin($this->approver)
            ->postJson("/api/v1/admin/finance/corrections/{$id}/approve", ['reason' => 'Vérifié sur le relevé'])
            ->assertStatus(428);
    }

    public function test_only_finance_and_super_admin_take_part_in_corrections(): void
    {
        foreach ([RoleSlug::Support, RoleSlug::Operations, RoleSlug::Compliance] as $role) {
            $this->requestCorrection(admin: User::factory()->withRole($role)->create())->assertForbidden();
        }

        $id = $this->requestCorrection()->json('data.id');
        $this->decide($id, 'approve', User::factory()->withRole(RoleSlug::Compliance)->create())->assertForbidden();
        $this->decide($id, 'approve', User::factory()->withRole(RoleSlug::SuperAdmin)->create())->assertOk();
    }

    public function test_corrections_are_listed_and_filtered_by_status(): void
    {
        $approved = $this->requestCorrection()->json('data.id');
        $this->decide($approved, 'approve', $this->approver);
        $other = $this->ledger->creditSimulated($this->wallet, Money::ofMinor(20_00, 'HTG'), 'test:credit-2');
        $this->requestCorrection($other);

        $compliance = $this->actingAsVerifiedAdmin(User::factory()->withRole(RoleSlug::Compliance)->create());
        $compliance->getJson('/api/v1/admin/finance/corrections')->assertOk()->assertJsonCount(2, 'data');
        $compliance->getJson('/api/v1/admin/finance/corrections?status=pending')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ledger_entry.id', (string) $other->id);
    }
}
