<?php

namespace Tests\Feature\Modules\Betting;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Settlement\Services\ResultImportService;
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
use Tests\TestCase;

/**
 * V2-D05 : plafonds d'engagement par match et par marché, combinés dans le pire cas, mise maximale par joueur.
 * Cotes du faux catalogue : fake-1 Arsenal 2.10 / Nul 3.25 / Chelsea 3.40, plus 1.80, LDEM oui 1.70 ;
 * fake-2 Liverpool 1.55 / Nul 4.20 (fake-2 commence un jour après fake-1).
 */
class RiskLimitsTest extends TestCase
{
    use RefreshDatabase;

    private FakeSportsDataProvider $fake;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sports_data.max_odds_age_minutes' => 100_000, 'wallet.simulated' => true]);
        $this->fake = new FakeSportsDataProvider;
        $this->app->instance(SportsDataProvider::class, $this->fake);
        app(CatalogueSyncService::class)->sync('soccer_epl');

        $this->player = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($this->player), Money::ofMinor(10_000_00, 'HTG'), 'test:'.Str::uuid());
    }

    private function selection(string $event = 'fake-1', string $market = 'h2h', string $key = 'home'): Selection
    {
        return Event::where('external_id', $event)->firstOrFail()
            ->markets()->where('key', $market)->firstOrFail()
            ->selections()->where('key', $key)->firstOrFail();
    }

    /** @param  list<Selection>  $selections */
    private function bet(array $selections, int $stake = 100_00): TestResponse
    {
        return $this->actingAs($this->player->refresh())->postJson('/api/v1/bets', [
            'stake' => ['minor' => $stake, 'currency' => 'HTG'],
            'selections' => array_map(fn (Selection $s) => ['selection_id' => $s->id, 'odds' => (string) $s->odds], $selections),
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function limits(int $market, int $event): void
    {
        config(['betting.max_market_liability_minor' => $market, 'betting.max_event_liability_minor' => $event]);
    }

    public function test_a_bet_beyond_the_market_limit_is_refused_and_nothing_is_debited(): void
    {
        $this->limits(market: 300_00, event: 1_000_00);

        // Arsenal 100 → pire cas 210 − 100 = 110.
        $this->bet([$this->selection()])->assertCreated();

        // Arsenal 200 de plus → gains 630 − mises 300 = 330 > 300.
        $this->bet([$this->selection()], 200_00)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'risk_limit_exceeded')
            ->assertJsonPath('error.details.scope', 'market')
            ->assertJsonPath('error.details.event_id', (string) $this->selection()->market->event_id)
            ->assertJsonMissingPath('error.details.remaining_minor');

        $this->assertSame(1, BetTicket::count());
        $ledger = app(LedgerService::class);
        $this->assertSame(9_900_00, $ledger->balance($ledger->walletFor($this->player))->minor);

        // Une mise plus petite passe : gains 420 − mises 200 = 220.
        $this->bet([$this->selection()])->assertCreated();
    }

    public function test_the_event_limit_adds_up_the_markets_of_the_match(): void
    {
        $this->limits(market: 1_000_00, event: 200_00);

        $this->bet([$this->selection()])->assertCreated();                       // 1X2 : 110
        $this->bet([$this->selection(market: 'totals', key: 'over')])->assertCreated(); // plus/moins : 80 → 190

        $this->bet([$this->selection(market: 'btts', key: 'yes')]) // LDEM : 70 → 260 > 200
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'risk_limit_exceeded')
            ->assertJsonPath('error.details.scope', 'event');

        // L'autre match n'est pas concerné.
        $this->bet([$this->selection('fake-2')])->assertCreated();
    }

    public function test_a_bet_that_lowers_the_risk_stays_accepted_after_the_limit_was_lowered(): void
    {
        $this->limits(market: 1_000_00, event: 1_000_00);
        $this->bet([$this->selection()], 200_00)->assertCreated(); // pire cas 420 − 200 = 220

        $this->limits(market: 100_00, event: 1_000_00); // plafond baissé sous l'exposition actuelle

        // Nul 10 : pire cas 420 − 210 = 210, en baisse → accepté.
        $this->bet([$this->selection(key: 'draw')], 10_00)->assertCreated();
        // Arsenal 10 : pire cas en hausse → refusé.
        $this->bet([$this->selection()], 10_00)->assertStatus(422)->assertJsonPath('error.code', 'risk_limit_exceeded');
    }

    public function test_a_multiple_counts_on_a_match_only_once_its_other_matches_are_won(): void
    {
        $this->limits(market: 300_00, event: 1_000_00);

        // Combiné Arsenal × Liverpool : 100 × 2.10 × 1.55 = 325,50 ; simple Liverpool 100 : pire cas 155 − 100 = 55.
        $this->bet([$this->selection(), $this->selection('fake-2')])->assertCreated();
        $this->bet([$this->selection('fake-2')])->assertCreated();

        // Fake-1 pas encore joué : le combiné ne compte pas sur fake-2, Liverpool 100 de plus passe (310 − 200 = 110).
        $this->bet([$this->selection('fake-2')])->assertCreated();

        // Arsenal gagne 2-1 : le combiné ne dépend plus que de Liverpool → 310 + 325,50 − 200 = 435,50.
        $event = Event::where('external_id', 'fake-1')->firstOrFail();
        $this->travelTo($event->starts_at->addHours(3));
        $this->fake->setResult('fake-1', '2', '1');
        $this->assertTrue(app(ResultImportService::class)->import('soccer_epl')->succeeded());

        $this->bet([$this->selection('fake-2')], 10_00)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'risk_limit_exceeded')
            ->assertJsonPath('error.details.scope', 'market');
        // Un pari sur le nul réduit le pire cas (Liverpool) : accepté.
        $this->bet([$this->selection('fake-2', key: 'draw')], 10_00)->assertCreated();
    }

    public function test_an_admin_sets_a_player_stake_limit_with_a_reason_and_it_is_enforced(): void
    {
        $this->seed(PermissionSeeder::class);
        $support = User::factory()->withRole(RoleSlug::Support)->create();
        $uri = "/api/v1/admin/users/{$this->player->id}/stake-limit";
        $put = fn (array $body) => $this->actingAsVerifiedAdmin($support)->putJson($uri, $body, ['Idempotency-Key' => (string) Str::uuid()]);

        $put(['max_stake_minor' => 50_00])->assertUnprocessable()->assertJsonValidationErrors('reason', 'error.details');
        $put(['max_stake_minor' => 5_00, 'reason' => 'Mises suspectes'])->assertUnprocessable()->assertJsonValidationErrors('max_stake_minor', 'error.details');

        $put(['max_stake_minor' => 50_00, 'reason' => 'Mises suspectes sur un même match'])
            ->assertOk()
            ->assertJsonPath('data.max_stake_minor', 50_00);

        $audit = AuditLog::where('action', 'user.stake_limit_changed')->sole();
        $this->assertSame($support->id, $audit->actor_id);
        $this->assertSame(['max_stake_minor' => null], $audit->before);
        $this->assertSame(['max_stake_minor' => 50_00], $audit->after);
        $this->assertSame('Mises suspectes sur un même match', $audit->reason);

        $this->bet([$this->selection()], 60_00)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'stake_out_of_range')
            ->assertJsonPath('error.details.max.minor', 50_00);
        $this->bet([$this->selection()], 50_00)->assertCreated();

        // Limite retirée : la limite générale s'applique de nouveau.
        $put(['max_stake_minor' => null, 'reason' => 'Vérification terminée'])->assertOk()->assertJsonPath('data.max_stake_minor', null);
        $this->bet([$this->selection()], 60_00)->assertCreated();

        // Jamais sur un compte administrateur.
        $this->actingAsVerifiedAdmin($support)
            ->putJson("/api/v1/admin/users/{$support->id}/stake-limit", ['max_stake_minor' => 50_00, 'reason' => 'Essai sur un admin'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'not_a_player');
    }
}
