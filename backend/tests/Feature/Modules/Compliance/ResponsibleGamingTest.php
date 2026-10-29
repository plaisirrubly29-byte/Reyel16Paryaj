<?php

namespace Tests\Feature\Modules\Compliance;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Compliance\Enums\ResponsiblePlayEventType;
use App\Modules\Compliance\Models\ResponsiblePlayEvent;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Selection;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

/** P7-02 : jeu responsable — limites de mise, pause, auto-exclusion, contrôle dans POST /bets, journal. */
class ResponsibleGamingTest extends TestCase
{
    use RefreshDatabase;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond(); // dates comparées à la seconde près : l'horloge ne bouge que par travel()

        // Cotes « fraîches » pendant 60 jours : les tests avancent dans le temps sans resynchroniser.
        config(['sports_data.max_odds_age_minutes' => 60 * 24 * 60, 'wallet.simulated' => true, 'sports_data.horizon_days' => 90]);
        $fake = new FakeSportsDataProvider;
        $fake->setStartsAt('fake-1', now()->toImmutable()->addDays(45));
        $this->app->instance(SportsDataProvider::class, $fake);
        app(CatalogueSyncService::class)->sync('soccer_epl');

        $this->player = User::factory()->create(['phone' => '34123456', 'password' => 'motdepasse123']);
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($this->player), Money::ofMinor(10_000_00, 'HTG'), 'test:'.Str::uuid());
    }

    private function selection(): Selection
    {
        return Event::where('external_id', 'fake-1')->firstOrFail()
            ->markets()->where('key', 'h2h')->firstOrFail()
            ->selections()->where('key', 'home')->firstOrFail();
    }

    private function bet(int $stake): TestResponse
    {
        $selection = $this->selection();

        return $this->actingAs($this->player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => $stake, 'currency' => 'HTG'],
            'selections' => [['selection_id' => $selection->id, 'odds' => (string) $selection->odds]],
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function setLimit(string $period, ?int $amount): TestResponse
    {
        return $this->actingAs($this->player)->putJson("/api/v1/responsible-gaming/limits/{$period}", ['amount_minor' => $amount], [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
    }

    private function pause(string $duration): TestResponse
    {
        return $this->actingAs($this->player)->postJson('/api/v1/responsible-gaming/pause', ['duration' => $duration], [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
    }

    private function selfExclude(string $duration = '6m', string $password = 'motdepasse123'): TestResponse
    {
        return $this->actingAs($this->player)->postJson('/api/v1/responsible-gaming/self-exclusion', [
            'duration' => $duration,
            'password' => $password,
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /** @return list<string> types des événements journalisés, dans l'ordre */
    private function events(): array
    {
        return ResponsiblePlayEvent::where('user_id', $this->player->id)->orderBy('id')->pluck('type')->map->value->all();
    }

    // État et accès

    public function test_the_player_sees_their_limits_pause_and_the_options(): void
    {
        $this->actingAs($this->player)->getJson('/api/v1/responsible-gaming')
            ->assertOk()
            ->assertJsonPath('data.limits.day', ['amount' => null, 'used' => ['minor' => 0, 'currency' => 'HTG'], 'remaining' => null, 'pending' => null])
            ->assertJsonPath('data.pause', ['active' => false, 'ends_at' => null])
            ->assertJsonPath('data.options.pause_durations', ['24h', '7d', '30d'])
            ->assertJsonPath('data.options.exclusion_durations', ['6m', '1y', '5y'])
            ->assertJsonPath('data.options.limit_increase_delay_days', 7);
    }

    public function test_guests_have_no_access_and_changes_require_an_idempotency_key(): void
    {
        $this->getJson('/api/v1/responsible-gaming')->assertUnauthorized();

        $this->actingAs($this->player)->putJson('/api/v1/responsible-gaming/limits/day', ['amount_minor' => 100_00])->assertStatus(428);
        $this->actingAs($this->player)->postJson('/api/v1/responsible-gaming/pause', ['duration' => '24h'])->assertStatus(428);
    }

    public function test_invalid_inputs_are_rejected(): void
    {
        $this->setLimit('year', 100_00)->assertStatus(422);
        $this->setLimit('day', 0)->assertStatus(422);
        $this->actingAs($this->player)->putJson('/api/v1/responsible-gaming/limits/day', [], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);
        $this->pause('1h')->assertStatus(422);
        $this->selfExclude('2w')->assertStatus(422);

        $this->assertSame([], $this->events());
    }

    // Limites de mise appliquées à POST /bets

    public function test_a_stake_limit_applies_immediately_and_refused_bets_are_logged(): void
    {
        $this->setLimit('day', 150_00)->assertOk()->assertJsonPath('data.limits.day.amount.minor', 150_00);

        $this->bet(100_00)->assertCreated();
        $this->bet(100_00)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'stake_limit_exceeded')
            ->assertJsonPath('error.details.period', 'day')
            ->assertJsonPath('error.details.remaining_minor', 50_00);
        $this->bet(50_00)->assertCreated(); // exactement la limite

        $this->assertSame(2, BetTicket::count());
        $this->actingAs($this->player)->getJson('/api/v1/responsible-gaming')
            ->assertJsonPath('data.limits.day.used.minor', 150_00)
            ->assertJsonPath('data.limits.day.remaining.minor', 0);
        $this->assertSame(['limit.lowered', 'bet.refused_limit'], $this->events());

        $refusal = ResponsiblePlayEvent::where('type', ResponsiblePlayEventType::BetRefusedLimit)->sole();
        $this->assertSame(['stake_minor' => 100_00, 'period' => 'day', 'limit_minor' => 150_00, 'remaining_minor' => 50_00], $refusal->data);
    }

    public function test_limits_use_rolling_periods(): void
    {
        $this->setLimit('day', 100_00)->assertOk();
        $this->setLimit('week', 150_00)->assertOk();

        $this->bet(100_00)->assertCreated();
        $this->travel(25)->hours(); // hors de la journée glissante, toujours dans la semaine

        $this->bet(60_00)->assertStatus(422)->assertJsonPath('error.details.period', 'week');
        $this->bet(50_00)->assertCreated();

        $this->travel(7)->days(); // les deux mises sortent de la semaine glissante
        $this->bet(100_00)->assertCreated();
    }

    public function test_raising_a_limit_waits_for_the_cooling_off_delay(): void
    {
        $this->setLimit('day', 100_00)->assertOk();

        $this->setLimit('day', 500_00)
            ->assertOk()
            ->assertJsonPath('data.limits.day.amount.minor', 100_00)
            ->assertJsonPath('data.limits.day.pending.amount.minor', 500_00)
            ->assertJsonPath('data.limits.day.pending.effective_at', now()->addDays(7)->toIso8601String());

        $this->bet(150_00)->assertStatus(422)->assertJsonPath('error.code', 'stake_limit_exceeded');

        $this->travel(7)->days();
        $this->travel(1)->minutes();
        $this->bet(150_00)->assertCreated();

        $this->assertSame(['limit.lowered', 'limit.increase_scheduled', 'bet.refused_limit', 'limit.change_applied'], $this->events());
    }

    public function test_removing_a_limit_waits_for_the_cooling_off_delay(): void
    {
        $this->setLimit('day', 100_00)->assertOk();
        $this->setLimit('day', null)->assertOk()
            ->assertJsonPath('data.limits.day.amount.minor', 100_00)
            ->assertJsonPath('data.limits.day.pending.amount', null);

        $this->bet(150_00)->assertStatus(422);

        $this->travel(8)->days();
        $this->actingAs($this->player)->getJson('/api/v1/responsible-gaming')->assertJsonPath('data.limits.day.amount', null);
        $this->bet(150_00)->assertCreated();
    }

    public function test_lowering_a_limit_cancels_a_pending_increase(): void
    {
        $this->setLimit('day', 100_00)->assertOk();
        $this->setLimit('day', 500_00)->assertOk();
        $this->setLimit('day', 80_00)->assertOk()
            ->assertJsonPath('data.limits.day.amount.minor', 80_00)
            ->assertJsonPath('data.limits.day.pending', null);

        $this->travel(8)->days();
        $this->actingAs($this->player)->getJson('/api/v1/responsible-gaming')->assertJsonPath('data.limits.day.amount.minor', 80_00);
    }

    public function test_setting_the_same_limit_again_changes_and_logs_nothing(): void
    {
        $this->setLimit('day', 100_00)->assertOk();
        $this->setLimit('day', 100_00)->assertOk();

        $this->assertSame(['limit.lowered'], $this->events());
    }

    // Pause

    public function test_a_pause_blocks_betting_but_not_the_account(): void
    {
        $this->pause('24h')->assertOk()
            ->assertJsonPath('data.pause.active', true)
            ->assertJsonPath('data.pause.ends_at', now()->addHours(24)->toIso8601String());

        $this->bet(100_00)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'betting_paused')
            ->assertJsonPath('error.details.ends_at', now()->addHours(24)->toIso8601String());
        $this->assertSame(0, BetTicket::count());

        // Le joueur reste connecté : solde et tickets consultables.
        $this->actingAs($this->player)->getJson('/api/v1/wallet')->assertOk();
        $this->actingAs($this->player)->getJson('/api/v1/bets')->assertOk();

        $this->travel(24)->hours();
        $this->travel(1)->minutes();
        $this->bet(100_00)->assertCreated();

        $this->assertSame(['pause.started', 'bet.refused_pause'], $this->events());
    }

    public function test_a_pause_can_be_extended_but_never_shortened(): void
    {
        $this->pause('7d')->assertOk();
        $longest = now()->addDays(7)->toIso8601String();

        $this->pause('24h')->assertOk()->assertJsonPath('data.pause.ends_at', $longest);
        $this->assertSame(['pause.started'], $this->events());

        $this->pause('30d')->assertOk()->assertJsonPath('data.pause.ends_at', now()->addDays(30)->toIso8601String());
        $this->assertSame(['pause.started', 'pause.started'], $this->events());
    }

    // Auto-exclusion

    public function test_self_exclusion_requires_the_password(): void
    {
        $this->selfExclude(password: 'mauvais-mdp1')->assertStatus(422)->assertJsonPath('error.code', 'invalid_password');

        $this->assertSame(AccountStatus::Active, $this->player->fresh()->status);
        $this->assertSame([], $this->events());
    }

    public function test_self_exclusion_closes_the_account_and_is_logged_and_audited(): void
    {
        $this->selfExclude('6m')
            ->assertOk()
            ->assertJsonPath('data.excluded_until', now()->addMonths(6)->toIso8601String())
            ->assertJsonPath('data.reopening', 'on_request');

        $this->assertSame(AccountStatus::Excluded, $this->player->fresh()->status);
        $this->assertSame(['self_exclusion.started'], $this->events());
        $audit = AuditLog::where('action', AuditAction::UserSelfExcluded)->sole();
        $this->assertSame($this->player->id, $audit->actor_id);
        $this->assertSame(['status' => 'excluded'], $audit->after);

        // Connexion refusée avec un message dédié.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', ['phone' => '34123456', 'password' => 'motdepasse123'])
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'account_excluded');
    }

    public function test_an_open_session_is_refused_once_the_account_is_excluded(): void
    {
        $this->selfExclude()->assertOk();

        $this->actingAs($this->player->fresh())->getJson('/api/v1/wallet')
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'account_excluded');
    }

    public function test_an_administrator_cannot_lift_a_self_exclusion(): void
    {
        $this->selfExclude()->assertOk();
        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();

        $this->actingAsVerifiedAdmin($admin)->patchJson("/api/v1/admin/users/{$this->player->id}", [
            'status' => 'active',
            'reason' => 'Le joueur demande la réouverture',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);

        $this->assertSame(AccountStatus::Excluded, $this->player->fresh()->status);
    }

    // Journal

    public function test_the_journal_is_append_only(): void
    {
        $this->setLimit('day', 100_00)->assertOk();
        $event = ResponsiblePlayEvent::sole();

        try {
            $event->update(['data' => []]);
            $this->fail('Un événement du journal a pu être modifié.');
        } catch (LogicException) {
        }

        try {
            $event->delete();
            $this->fail('Un événement du journal a pu être supprimé.');
        } catch (LogicException) {
        }

        $this->assertSame(1, ResponsiblePlayEvent::count());
    }
}
