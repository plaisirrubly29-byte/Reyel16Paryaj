<?php

namespace Tests\Feature\Modules\Compliance;

use App\Models\User;
use App\Modules\Compliance\Enums\ResponsiblePlayEventType;
use App\Modules\Compliance\Models\ResponsiblePlayEvent;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionLifetime;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Selection;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** V2-E06 : limite de pertes nettes dans POST /bets, rappel de durée de session, historique des dépenses. */
class LossLimitAndHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $player;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        // Date fixe (15 h en Haïti) : l'historique est découpé en jours et en mois du fuseau d'affichage.
        $this->travelTo(CarbonImmutable::parse('2026-11-05 15:00', 'America/Port-au-Prince'));
        $this->freezeSecond(); // dates comparées à la seconde près : l'horloge ne bouge que par travel()

        // Cotes « fraîches » pendant 60 jours : les tests avancent dans le temps sans resynchroniser.
        config(['sports_data.max_odds_age_minutes' => 60 * 24 * 60, 'wallet.simulated' => true, 'sports_data.horizon_days' => 90]);
        $fake = new FakeSportsDataProvider;
        $fake->setStartsAt('fake-1', now()->toImmutable()->addDays(45));
        $this->app->instance(SportsDataProvider::class, $fake);
        app(CatalogueSyncService::class)->sync('soccer_epl');

        $this->player = User::factory()->create();
        $ledger = app(LedgerService::class);
        $this->wallet = $ledger->walletFor($this->player);
        $ledger->creditSimulated($this->wallet, Money::ofMinor(10_000_00, 'HTG'), 'test:'.Str::uuid());
    }

    private function bet(int $stake): TestResponse
    {
        $selection = Event::where('external_id', 'fake-1')->firstOrFail()
            ->markets()->where('key', 'h2h')->firstOrFail()
            ->selections()->where('key', 'home')->firstOrFail();
        assert($selection instanceof Selection);

        return $this->actingAs($this->player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => $stake, 'currency' => 'HTG'],
            'selections' => [['selection_id' => $selection->id, 'odds' => (string) $selection->odds]],
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /** @param  array<string, mixed>  $body */
    private function change(string $path, array $body): TestResponse
    {
        return $this->actingAs($this->player)->putJson("/api/v1/responsible-gaming/{$path}", $body, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    // Limite de pertes

    public function test_a_bet_that_could_exceed_the_loss_limit_is_refused_and_logged(): void
    {
        $this->change('loss-limits/day', ['amount_minor' => 300_00])->assertOk()
            ->assertJsonPath('data.loss_limits.day.amount.minor', 300_00)
            ->assertJsonPath('data.loss_limits.day.used.minor', 0);

        $this->bet(200_00)->assertCreated();
        $this->bet(150_00)->assertStatus(422)
            ->assertJsonPath('error.code', 'loss_limit_exceeded')
            ->assertJsonPath('error.details.period', 'day')
            ->assertJsonPath('error.details.remaining_minor', 100_00);
        $this->bet(100_00)->assertCreated(); // exactement la limite

        $this->assertSame(1, ResponsiblePlayEvent::where('type', ResponsiblePlayEventType::BetRefusedLossLimit)->count());
    }

    public function test_winnings_and_refunds_free_room_under_the_loss_limit(): void
    {
        $this->change('loss-limits/week', ['amount_minor' => 300_00])->assertOk();
        $this->bet(300_00)->assertCreated();
        $this->bet(10_00)->assertStatus(422)->assertJsonPath('error.code', 'loss_limit_exceeded');

        // Un gain de 250 et un remboursement de 50 : la perte nette retombe à 0.
        $ledger = app(LedgerService::class);
        $ledger->payout($this->wallet, Money::ofMinor(250_00, 'HTG'), 'test:'.Str::uuid());
        $ledger->refund($this->wallet, Money::ofMinor(50_00, 'HTG'), 'test:'.Str::uuid());

        $this->actingAs($this->player)->getJson('/api/v1/responsible-gaming')
            ->assertJsonPath('data.loss_limits.week.used.minor', 0)
            ->assertJsonPath('data.loss_limits.week.remaining.minor', 300_00);
        $this->bet(300_00)->assertCreated();
    }

    public function test_raising_the_loss_limit_waits_for_the_delay(): void
    {
        $this->change('loss-limits/month', ['amount_minor' => 100_00])->assertOk();
        $this->change('loss-limits/month', ['amount_minor' => 1_000_00])->assertOk()
            ->assertJsonPath('data.loss_limits.month.amount.minor', 100_00)
            ->assertJsonPath('data.loss_limits.month.pending.amount.minor', 1_000_00);
        $this->bet(200_00)->assertStatus(422)->assertJsonPath('error.code', 'loss_limit_exceeded');

        $this->travel((int) config('compliance.limits.increase_delay_days'))->days();
        $this->bet(200_00)->assertCreated();
        $this->assertSame(1, ResponsiblePlayEvent::where('type', ResponsiblePlayEventType::LossLimitChangeApplied)->count());
    }

    // Rappel de durée de session

    public function test_the_session_reminder_is_chosen_by_the_player_and_applies_at_once(): void
    {
        $this->actingAs($this->player)->getJson('/api/v1/responsible-gaming')
            ->assertJsonPath('data.session.reminder_minutes', null)
            ->assertJsonPath('data.options.session_reminder_minutes', [15, 30, 60]);

        $this->change('session-reminder', ['minutes' => 30])->assertOk()->assertJsonPath('data.session.reminder_minutes', 30);
        $this->change('session-reminder', ['minutes' => null])->assertOk()->assertJsonPath('data.session.reminder_minutes', null);
        $this->change('session-reminder', ['minutes' => 45])->assertStatus(422)->assertJsonValidationErrors('minutes', 'error.details');

        $this->assertSame(2, ResponsiblePlayEvent::where('type', ResponsiblePlayEventType::SessionReminderChanged)->count());
    }

    public function test_the_session_start_comes_from_the_login(): void
    {
        // Heure de connexion posée en session par la connexion (EnforceSessionLifetime, V2-C06).
        $loggedInAt = now()->subMinutes(40);

        $this->actingAs($this->player)
            ->withHeader('Origin', config('app.frontend_url'))
            ->withSession([EnforceSessionLifetime::STARTED_AT => $loggedInAt->getTimestamp()])
            ->getJson('/api/v1/responsible-gaming')->assertOk()
            ->assertJsonPath('data.session.started_at', $loggedInAt->toIso8601String());
    }

    // Historique des dépenses

    public function test_the_history_groups_money_movements_by_local_day_and_month(): void
    {
        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->withRole(RoleSlug::SuperAdmin)->create();
        $ledger = app(LedgerService::class);
        $ledger->deposit($this->wallet, Money::ofMinor(1_000_00, 'HTG'), 'test:'.Str::uuid(), 'Dépôt en agence', $admin);
        $this->bet(200_00)->assertCreated();
        $ledger->payout($this->wallet, Money::ofMinor(50_00, 'HTG'), 'test:'.Str::uuid());

        // 22 h 30 en Haïti le 4 novembre = 3 h 30 UTC le 5 : la mise compte pour le 4.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 22:30', 'America/Port-au-Prince'));
        $this->bet(100_00)->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'America/Port-au-Prince'));
        $ledger->withdraw($this->wallet, Money::ofMinor(300_00, 'HTG'), 'test:'.Str::uuid(), 'Retrait en agence', $admin);

        $this->travelTo(CarbonImmutable::parse('2026-11-05 16:00', 'America/Port-au-Prince'));
        $data = $this->actingAs($this->player)->getJson('/api/v1/responsible-gaming/history')->assertOk()->json('data');

        $this->assertSame('America/Port-au-Prince', $data['timezone']);
        $this->assertCount(30, $data['days']);
        $this->assertCount(12, $data['months']);

        $today = $data['days'][0];
        $this->assertSame('2026-11-05', $today['date']);
        $this->assertSame([1_000_00, 0, 200_00, 50_00, 0, -150_00], array_map(fn ($m) => $m['minor'], [
            $today['deposits'], $today['withdrawals'], $today['stakes'], $today['payouts'], $today['refunds'], $today['net'],
        ]));
        $this->assertSame(100_00, $data['days'][1]['stakes']['minor']);
        $this->assertSame('2026-11-04', $data['days'][1]['date']);

        $this->assertSame(['2026-11', 300_00, -250_00], [$data['months'][0]['month'], $data['months'][0]['stakes']['minor'], $data['months'][0]['net']['minor']]);
        $this->assertSame(['2026-10', 300_00], [$data['months'][1]['month'], $data['months'][1]['withdrawals']['minor']]);
        $this->assertSame('2025-12', $data['months'][11]['month']);
    }

    public function test_compliance_sees_the_loss_limits_and_the_reminder(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->change('loss-limits/day', ['amount_minor' => 500_00])->assertOk();
        $this->change('session-reminder', ['minutes' => 60])->assertOk();

        $this->actingAsVerifiedAdmin(User::factory()->withRole(RoleSlug::Compliance)->create())
            ->getJson("/api/v1/admin/users/{$this->player->id}/responsible-gaming")->assertOk()
            ->assertJsonPath('data.loss_limits.day.amount.minor', 500_00)
            ->assertJsonPath('data.session', ['reminder_minutes' => 60, 'started_at' => null]);
    }
}
