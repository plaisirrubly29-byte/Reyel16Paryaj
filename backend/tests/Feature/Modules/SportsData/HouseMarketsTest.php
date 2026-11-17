<?php

namespace Tests\Feature\Modules\SportsData;

use App\Models\User;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Settlement\Enums\ResultStatus;
use App\Modules\Settlement\Services\ResultRecorder;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Data\EventData;
use App\Modules\SportsData\Data\MarketData;
use App\Modules\SportsData\Data\SelectionData;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Selection;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Modules\SportsData\Services\HouseMarkets;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Marchés maison (V2-D10) : créés à la synchro, mise plafonnée, réglés sur le score final. */
class HouseMarketsTest extends TestCase
{
    use RefreshDatabase;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sports_data.max_odds_age_minutes' => 300, 'wallet.simulated' => true]);
        $this->player = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($this->player), Money::ofMinor(5000_00, 'HTG'), 'test:'.Str::uuid());
    }

    /** Fournisseur fictif avec un second bookmaker sur le 1X2 et le plus/moins (cotes un peu différentes). */
    private function withTwoBookmakers(): void
    {
        $this->app->instance(SportsDataProvider::class, new class extends FakeSportsDataProvider
        {
            public function events(string $sportKey): array
            {
                return array_map(function (EventData $e) {
                    $extra = [];
                    foreach ($e->markets as $m) {
                        if (in_array($m->key, ['h2h', 'totals'], true)) {
                            $extra[] = new MarketData($m->key, 'otherbook', $m->updatedAt, array_map(
                                fn (SelectionData $s) => new SelectionData($s->name, bcmul($s->odds, '1.03', 3), $s->point, $s->description),
                                $m->selections,
                            ));
                        }
                    }

                    return new EventData($e->externalId, $e->sportKey, $e->competition, $e->homeTeam, $e->awayTeam, $e->startsAt, [...$e->markets, ...$extra]);
                }, parent::events($sportKey));
            }
        });
    }

    private function sync(): void
    {
        app(CatalogueSyncService::class)->sync('soccer_epl');
    }

    private function selection(string $market, string $key, string $event = 'fake-1'): Selection
    {
        return Event::where('external_id', $event)->sole()->markets()->where('key', $market)->sole()
            ->selections()->where('key', $key)->sole();
    }

    private function bet(Selection $selection, int $stake): TestResponse
    {
        return $this->actingAs($this->player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => $stake, 'currency' => 'HTG'],
            'selections' => [['selection_id' => $selection->id, 'odds' => (string) $selection->odds]],
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_house_markets_are_created_for_football_with_two_bookmakers(): void
    {
        $this->withTwoBookmakers();
        $this->sync();

        $keys = Event::where('external_id', 'fake-1')->sole()->markets()->pluck('key')->all();
        foreach (HouseMarkets::KEYS as $key) {
            $this->assertContains($key, $keys);
        }
        $market = Event::where('external_id', 'fake-1')->sole()->markets()->where('key', HouseMarkets::WINNING_MARGIN)->sole();
        $this->assertSame('reyel16', $market->source_bookmaker);
        $this->assertNull($market->line);

        $this->getJson('/api/v1/events/'.$market->event_id.'/markets')
            ->assertOk()
            ->assertJsonFragment(['key' => HouseMarkets::WINNING_MARGIN, 'name' => 'Écart de victoire']);
    }

    public function test_a_house_outcome_never_pays_more_than_the_same_outcome_of_our_published_result_market(): void
    {
        config(['wallet.simulated' => false]); // marge de l'opérateur et plafonds appliqués au 1X2
        $this->withTwoBookmakers();
        $this->sync();

        foreach (Event::all() as $event) {
            $odds = fn (string $market, string $key) => (float) $event->markets()->where('key', $market)->sole()->selections()->where('key', $key)->value('odds');
            $this->assertLessThanOrEqual($odds('h2h', 'draw'), $odds(HouseMarkets::WINNING_MARGIN, 'draw'), "Match {$event->external_id}");

            $home = array_sum(array_map(fn ($k) => 1 / $odds(HouseMarkets::WINNING_MARGIN, $k), ['home_by_1', 'home_by_2', 'home_by_3plus']));
            $this->assertGreaterThanOrEqual(1 / $odds('h2h', 'home') - 0.005, $home, "Victoire domicile, match {$event->external_id}");
        }
    }

    public function test_the_match_list_flags_matches_with_house_markets(): void
    {
        $this->withTwoBookmakers();
        $this->sync();
        Event::where('external_id', 'fake-2')->sole()->markets()->where('key', 'like', 'r16_%')->update(['status' => MarketStatus::Suspended]);

        $flags = collect($this->getJson('/api/v1/events')->assertOk()->json('data'))->pluck('has_house_markets', 'home_team');

        $this->assertSame(['Arsenal' => true, 'Liverpool' => false], $flags->only(['Arsenal', 'Liverpool'])->all());
    }

    public function test_no_house_market_with_a_single_bookmaker_or_when_disabled(): void
    {
        $this->app->instance(SportsDataProvider::class, new FakeSportsDataProvider);
        $this->sync();
        $this->assertSame(0, Event::where('external_id', 'fake-1')->sole()->markets()->where('key', 'like', 'r16_%')->count());

        config(['sports_data.house_markets.enabled' => false]);
        $this->withTwoBookmakers();
        $this->sync();
        $this->assertSame(0, Event::where('external_id', 'fake-1')->sole()->markets()->where('key', 'like', 'r16_%')->count());
    }

    public function test_the_stake_on_a_house_market_is_capped_lower(): void
    {
        $this->withTwoBookmakers();
        $this->sync();
        $selection = $this->selection(HouseMarkets::GOAL_RANGE, 'range_2_3');

        $this->bet($selection, 1000_01)->assertUnprocessable()
            ->assertJsonPath('error.code', 'stake_out_of_range')
            ->assertJsonPath('error.details.max.minor', 1000_00);

        $this->bet($selection, 1000_00)->assertCreated();
        // Les autres marchés gardent la limite générale.
        $this->bet($this->selection('h2h', 'home', 'fake-2'), 2000_00)->assertCreated();
    }

    public function test_a_house_market_bet_is_settled_on_the_final_score(): void
    {
        $this->withTwoBookmakers();
        $this->sync();
        $this->bet($this->selection(HouseMarkets::WIN_TO_NIL, 'home_clean'), 100_00)->assertCreated();
        $this->bet($this->selection(HouseMarkets::EXACT_GOALS, 'goals_3', 'fake-2'), 100_00)->assertCreated();

        foreach (['fake-1' => ['2', '0'], 'fake-2' => ['1', '1']] as $id => [$home, $away]) {
            $event = Event::where('external_id', $id)->sole();
            $this->travelTo($event->starts_at->addHours(2));
            app(ResultRecorder::class)->record($event->fresh(), ResultStatus::Final, $home, $away, source: 'fake');
        }

        $this->assertSame([BetTicketStatus::Won, BetTicketStatus::Lost], BetTicket::orderBy('id')->get()->pluck('status')->all());
    }
}
