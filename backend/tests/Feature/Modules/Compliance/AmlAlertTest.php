<?php

namespace Tests\Feature\Modules\Compliance;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Compliance\Enums\AmlAlertStatus;
use App\Modules\Compliance\Enums\AmlMovement;
use App\Modules\Compliance\Enums\AmlRule;
use App\Modules\Compliance\Models\AmlAlert;
use App\Modules\Compliance\Services\AmlMonitor;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\Modules\Betting\BuildsBetTickets;
use Tests\TestCase;

/** V2-C04 : règles AML sur les dépôts et retraits, file de revue de la Conformité. */
class AmlAlertTest extends TestCase
{
    use BuildsBetTickets;
    use RefreshDatabase;

    private User $player;

    private User $superAdmin;

    private User $compliance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();

        $this->seed(PermissionSeeder::class);
        $this->player = User::factory()->create();
        $this->superAdmin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();
        $this->compliance = User::factory()->withRole(RoleSlug::Compliance)->create();
    }

    private function deposit(int $minor, ?User $user = null): LedgerEntry
    {
        $ledger = app(LedgerService::class);

        return $ledger->deposit($ledger->walletFor($user ?? $this->player), Money::ofMinor($minor, 'HTG'), 'test:'.Str::uuid(), 'Dépôt reçu', $this->superAdmin);
    }

    private function withdraw(int $minor): LedgerEntry
    {
        $ledger = app(LedgerService::class);

        return $ledger->withdraw($ledger->walletFor($this->player), Money::ofMinor($minor, 'HTG'), 'test:'.Str::uuid(), 'Retrait remis', $this->superAdmin);
    }

    private function stake(int $minor): void
    {
        $this->ticket($this->player, ['stake_minor' => $minor, 'placed_at' => now()]);
    }

    /** @return list<string> */
    private function rules(): array
    {
        return AmlAlert::orderBy('id')->get()->map(fn (AmlAlert $alert) => $alert->rule->value)->all();
    }

    /** @param array<string, mixed> $data */
    private function review(User $admin, AmlAlert $alert, array $data): TestResponse
    {
        return $this->actingAsVerifiedAdmin($admin)
            ->postJson("/api/v1/admin/aml/alerts/{$alert->id}/review", $data, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_a_large_deposit_raises_an_alert_without_blocking_the_money(): void
    {
        $this->deposit(49_999_99);
        $this->assertSame([], $this->rules());

        // Par la route du Super Admin : l'écriture passe, l'alerte suit.
        $this->actingAsVerifiedAdmin($this->superAdmin)->postJson("/api/v1/admin/users/{$this->player->id}/wallet/adjustments",
            ['type' => 'deposit', 'amount_minor' => 50_000_00, 'reason' => 'Versement reçu en agence'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $alert = AmlAlert::sole();
        $this->assertSame(AmlRule::LargeDeposit, $alert->rule);
        $this->assertSame(AmlAlertStatus::Open, $alert->status);
        $this->assertSame([$this->player->id, 50_000_00, 'HTG'], [$alert->user_id, $alert->amount_minor, $alert->currency]);
        $this->assertSame(LedgerEntry::latest('id')->value('id'), $alert->ledger_entry_id);
        $this->assertSame(['threshold_minor' => 50_000_00], $alert->details);
    }

    public function test_cumulative_deposits_raise_one_alert_when_the_threshold_is_crossed(): void
    {
        foreach ([40_000_00, 40_000_00, 40_000_00] as $amount) {
            $this->deposit($amount);
        }
        $this->assertSame([], $this->rules());

        $crossing = $this->deposit(40_000_00); // 160 000 HTG sur 7 jours
        $this->deposit(10_000_00); // déjà au-dessus : pas de nouvelle alerte

        $alert = AmlAlert::sole();
        $this->assertSame(AmlRule::CumulativeDeposits, $alert->rule);
        $this->assertSame($crossing->id, $alert->ledger_entry_id);
        $this->assertSame(['threshold_minor' => 150_000_00, 'window_days' => 7, 'deposited_minor' => 160_000_00], $alert->details);
    }

    public function test_the_cumulative_window_is_rolling(): void
    {
        foreach ([40_000_00, 40_000_00, 40_000_00] as $amount) {
            $this->deposit($amount);
        }
        $this->travel(8)->days();
        $this->deposit(40_000_00);

        $this->assertSame([], $this->rules());
    }

    public function test_a_withdrawal_after_deposits_barely_played_raises_an_alert(): void
    {
        $this->deposit(40_000_00);
        $this->stake(3_999_00); // moins de 10 % du dépôt
        $withdrawal = $this->withdraw(30_000_00);

        $alert = AmlAlert::sole();
        $this->assertSame(AmlRule::WithdrawalWithoutPlay, $alert->rule);
        $this->assertSame($withdrawal->id, $alert->ledger_entry_id);
        $this->assertSame(30_000_00, $alert->amount_minor);
        $this->assertSame(['window_hours' => 72, 'min_stake_percent' => 10, 'deposited_minor' => 40_000_00, 'staked_minor' => 3_999_00], $alert->details);
    }

    public function test_a_withdrawal_is_not_flagged_when_the_deposits_were_played_or_are_old(): void
    {
        $this->deposit(40_000_00);
        $this->stake(4_000_00); // 10 % : joué
        $this->withdraw(10_000_00);

        $this->travel(73)->hours();
        $this->withdraw(10_000_00); // plus aucun dépôt sur 72 h

        $this->assertSame([], $this->rules());
    }

    public function test_a_rolled_back_deposit_is_not_checked_and_a_replayed_check_raises_nothing_new(): void
    {
        try {
            DB::transaction(function () {
                $this->deposit(80_000_00);
                throw new RuntimeException('échec après le dépôt');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame([], $this->rules());

        $entry = $this->deposit(80_000_00);
        $this->assertSame([AmlRule::LargeDeposit->value], $this->rules());

        // Job rejoué : même écriture, même alerte.
        app(AmlMonitor::class)->check($this->player, $entry->id, AmlMovement::Deposit, 80_000_00, 'HTG', $entry->created_at);
        $this->assertSame(1, AmlAlert::count());
    }

    public function test_compliance_lists_and_reviews_alerts_with_a_reason_and_an_audit_entry(): void
    {
        $this->deposit(60_000_00);
        $other = User::factory()->create();
        $this->deposit(70_000_00, $other);
        [$first, $second] = AmlAlert::orderBy('id')->get()->all();

        $this->actingAsVerifiedAdmin($this->compliance)->getJson("/api/v1/admin/aml/alerts?status=open&user_id={$this->player->id}")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $first->id)
            ->assertJsonPath('data.0.rule', 'large_deposit')
            ->assertJsonPath('data.0.user.name', $this->player->name)
            ->assertJsonPath('data.0.amount', ['minor' => 60_000_00, 'currency' => 'HTG']);

        $this->review($this->compliance, $first, ['decision' => 'dismissed', 'reason' => 'Vente de terrain, acte notarié vu'])->assertOk()
            ->assertJsonPath('data.status', 'dismissed')
            ->assertJsonPath('data.reviewed_by.id', (string) $this->compliance->id)
            ->assertJsonPath('data.review_reason', 'Vente de terrain, acte notarié vu');
        $this->review($this->compliance, $second, ['decision' => 'reported', 'reason' => 'Origine des fonds non justifiée'])->assertOk()
            ->assertJsonPath('data.status', 'reported');

        $audit = AuditLog::where('action', AuditAction::AmlAlertReviewed)->orderBy('id')->firstOrFail();
        $this->assertSame([(string) $first->id, $this->compliance->id, 'Vente de terrain, acte notarié vu'], [$audit->subject_id, $audit->actor_id, $audit->reason]);
        $this->assertSame(['status' => 'dismissed'], $audit->after);

        $this->actingAsVerifiedAdmin($this->compliance)->getJson('/api/v1/admin/aml/alerts?status=open')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsVerifiedAdmin($this->compliance)->getJson("/api/v1/admin/aml/alerts/{$second->id}")->assertOk()
            ->assertJsonPath('data.status', 'reported');
    }

    public function test_a_review_needs_a_decision_and_a_reason_and_happens_once(): void
    {
        $this->deposit(60_000_00);
        $alert = AmlAlert::sole();

        $this->review($this->compliance, $alert, ['decision' => 'open', 'reason' => 'Je rouvre'])->assertUnprocessable()
            ->assertJsonValidationErrors('decision', 'error.details');
        $this->review($this->compliance, $alert, ['decision' => 'dismissed'])->assertUnprocessable()
            ->assertJsonValidationErrors('reason', 'error.details');
        $this->assertSame(AmlAlertStatus::Open, $alert->fresh()->status);

        $this->review($this->compliance, $alert, ['decision' => 'dismissed', 'reason' => 'Salaire versé en une fois'])->assertOk();
        $this->review($this->compliance, $alert, ['decision' => 'reported', 'reason' => 'Changement d\'avis'])->assertStatus(409)
            ->assertJsonPath('error.code', 'alert_not_open');
        $this->assertSame(AmlAlertStatus::Dismissed, $alert->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', AuditAction::AmlAlertReviewed)->count());
    }

    public function test_an_alert_on_the_reviewers_own_account_goes_to_someone_else(): void
    {
        $this->deposit(60_000_00, $this->compliance);
        $alert = AmlAlert::sole();
        $this->assertSame($this->compliance->id, $alert->user_id);

        $this->review($this->compliance, $alert, ['decision' => 'dismissed', 'reason' => 'Mon propre dépôt'])->assertForbidden()
            ->assertJsonPath('error.code', 'self_review_forbidden');
        $this->assertSame(AmlAlertStatus::Open, $alert->fresh()->status);
    }

    public function test_open_alerts_are_shown_on_the_dashboard_of_the_roles_that_see_them(): void
    {
        $this->deposit(60_000_00);
        $support = User::factory()->withRole(RoleSlug::Support)->create();

        $keys = fn (User $admin) => collect($this->actingAsVerifiedAdmin($admin)->getJson('/api/v1/admin/alerts')->assertOk()->json('data'))->pluck('key');

        $this->assertContains('aml_alerts', $keys($this->compliance));
        $this->assertNotContains('aml_alerts', $keys($support));
    }
}
