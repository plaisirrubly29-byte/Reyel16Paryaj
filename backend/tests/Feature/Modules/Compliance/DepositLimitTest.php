<?php

namespace Tests\Feature\Modules\Compliance;

use App\Models\User;
use App\Modules\Compliance\Enums\ResponsiblePlayEventType;
use App\Modules\Compliance\Models\ResponsiblePlayEvent;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** V2-B06 : limites de dépôt du joueur — baisse immédiate, hausse après le délai, contrôle de chaque dépôt. */
class DepositLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $player;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond(); // dates comparées à la seconde près : l'horloge ne bouge que par travel()

        $this->seed(PermissionSeeder::class);
        $this->superAdmin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();
        $this->player = User::factory()->create();
    }

    private function setDepositLimit(string $period, ?int $amountMinor): TestResponse
    {
        return $this->actingAs($this->player)->putJson("/api/v1/responsible-gaming/deposit-limits/{$period}",
            ['amount_minor' => $amountMinor], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /** Dépôt reçu hors plateforme, enregistré par le Super Admin (seul canal de dépôt avant V2-B02). */
    private function deposit(int $amountMinor): TestResponse
    {
        return $this->actingAsVerifiedAdmin($this->superAdmin)->postJson(
            "/api/v1/admin/users/{$this->player->id}/wallet/adjustments",
            ['type' => 'deposit', 'amount_minor' => $amountMinor, 'reason' => 'Dépôt MonCash reçu'],
            ['Idempotency-Key' => (string) Str::uuid()],
        );
    }

    public function test_a_first_limit_applies_at_once_and_shows_the_deposits_of_the_period(): void
    {
        $this->deposit(300_00)->assertCreated();

        $this->setDepositLimit('day', 1_000_00)->assertOk()
            ->assertJsonPath('data.deposit_limits.day.amount.minor', 1_000_00)
            ->assertJsonPath('data.deposit_limits.day.used.minor', 300_00)
            ->assertJsonPath('data.deposit_limits.day.remaining.minor', 700_00)
            ->assertJsonPath('data.deposit_limits.week.amount', null)
            ->assertJsonPath('data.limits.day.amount', null); // la limite de mise n'est pas touchée

        $this->assertSame(1, ResponsiblePlayEvent::where('type', ResponsiblePlayEventType::DepositLimitLowered)->count());
    }

    public function test_a_deposit_above_the_limit_is_refused_and_logged_with_the_admin(): void
    {
        $this->setDepositLimit('week', 1_000_00)->assertOk();
        $this->deposit(800_00)->assertCreated();

        $this->deposit(300_00)->assertStatus(422)
            ->assertJsonPath('error.code', 'deposit_limit_exceeded')
            ->assertJsonPath('error.details.period', 'week')
            ->assertJsonPath('error.details.remaining_minor', 200_00);

        $this->assertSame(1, LedgerEntry::where('type', LedgerEntryType::Deposit)->count());
        $refusal = ResponsiblePlayEvent::where('type', ResponsiblePlayEventType::DepositRefusedLimit)->sole();
        $this->assertSame($this->superAdmin->id, $refusal->actor_id);
        $this->assertSame(300_00, $refusal->data['amount_minor']);

        $this->deposit(200_00)->assertCreated(); // le reste exact passe
    }

    public function test_an_increase_waits_for_the_delay_and_a_decrease_cancels_it(): void
    {
        $this->setDepositLimit('month', 500_00)->assertOk();

        $this->setDepositLimit('month', 2_000_00)->assertOk()
            ->assertJsonPath('data.deposit_limits.month.amount.minor', 500_00)
            ->assertJsonPath('data.deposit_limits.month.pending.amount.minor', 2_000_00);
        $this->deposit(600_00)->assertStatus(422)->assertJsonPath('error.code', 'deposit_limit_exceeded');

        $this->travel((int) config('compliance.limits.increase_delay_days'))->days();
        $this->deposit(600_00)->assertCreated();
        $this->actingAs($this->player)->getJson('/api/v1/responsible-gaming')
            ->assertJsonPath('data.deposit_limits.month.amount.minor', 2_000_00)
            ->assertJsonPath('data.deposit_limits.month.pending', null);
        $this->assertSame(1, ResponsiblePlayEvent::where('type', ResponsiblePlayEventType::DepositLimitChangeApplied)->count());

        // Nouvelle hausse puis baisse : la baisse s'applique tout de suite et annule la hausse.
        $this->setDepositLimit('month', 5_000_00)->assertOk();
        $this->setDepositLimit('month', 1_000_00)->assertOk()
            ->assertJsonPath('data.deposit_limits.month.amount.minor', 1_000_00)
            ->assertJsonPath('data.deposit_limits.month.pending', null);
    }

    public function test_removing_a_limit_waits_for_the_delay(): void
    {
        $this->setDepositLimit('day', 100_00)->assertOk();
        $this->setDepositLimit('day', null)->assertOk()
            ->assertJsonPath('data.deposit_limits.day.amount.minor', 100_00)
            ->assertJsonPath('data.deposit_limits.day.pending.amount', null);

        $this->travel((int) config('compliance.limits.increase_delay_days'))->days();
        $this->deposit(5_000_00)->assertCreated();
    }

    public function test_withdrawals_are_not_limited_and_input_is_validated(): void
    {
        $this->deposit(500_00)->assertCreated();
        $this->setDepositLimit('day', 100_00)->assertOk();

        $this->actingAsVerifiedAdmin($this->superAdmin)->postJson("/api/v1/admin/users/{$this->player->id}/wallet/adjustments",
            ['type' => 'withdrawal', 'amount_minor' => 400_00, 'reason' => 'Retrait remis en agence'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $this->setDepositLimit('year', 100_00)->assertStatus(422)->assertJsonValidationErrors('period', 'error.details');
        $this->setDepositLimit('day', 0)->assertStatus(422)->assertJsonValidationErrors('amount_minor', 'error.details');
    }

    public function test_compliance_sees_the_deposit_limits(): void
    {
        $this->setDepositLimit('day', 1_000_00)->assertOk();
        $compliance = User::factory()->withRole(RoleSlug::Compliance)->create();

        $this->actingAsVerifiedAdmin($compliance)->getJson("/api/v1/admin/users/{$this->player->id}/responsible-gaming")
            ->assertOk()
            ->assertJsonPath('data.deposit_limits.day.amount.minor', 1_000_00)
            ->assertJsonPath('data.events.0.type', 'deposit_limit.lowered');
    }
}
