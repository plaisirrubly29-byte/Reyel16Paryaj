<?php

namespace Tests\Feature\Modules\Settlement;

use App\Models\User;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Enums\BetType;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Settlement\Contracts\MatchStatisticsProvider;
use App\Modules\Settlement\Data\FixtureData;
use App\Modules\Settlement\Data\StatisticsData;
use App\Modules\Settlement\Enums\ResultStatus;
use App\Modules\Settlement\Models\MatchStatistics;
use App\Modules\Settlement\Providers\FakeMatchStatisticsProvider;
use App\Modules\Settlement\Services\ResultRecorder;
use App\Modules\Settlement\Services\StatisticsImportService;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Models\Market;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Modules\Betting\BuildsBetTickets;
use Tests\TestCase;

/** Paris réglés avec les statistiques du match : 1re mi-temps, corners, cartons (V2-D09). */
class StatisticsMarketsTest extends TestCase
{
    use BuildsBetTickets;
    use RefreshDatabase;

    private User $player;

    private FakeMatchStatisticsProvider $stats;

    protected function setUp(): void
    {
        parent::setUp();

        config(['wallet.simulated' => true, 'sports_data.statistics.provider' => 'fake']);
        $this->stats = new FakeMatchStatisticsProvider;
        $this->app->instance(MatchStatisticsProvider::class, $this->stats);

        $this->player = User::factory()->create();
        app(LedgerService::class)->creditSimulated($this->wallet(), Money::ofMinor(1000_00, 'HTG'), 'bonus:test');
    }

    private function wallet(): Wallet
    {
        return Wallet::firstOrCreate(['user_id' => $this->player->id, 'currency' => 'HTG']);
    }

    private function bet(Market $market, string $key, int $stake = 100_00): BetTicket
    {
        $odds = (string) $market->selections()->where('key', $key)->value('odds');
        $ticket = $this->ticket($this->player, [
            'type' => BetType::Single,
            'stake_minor' => $stake,
            'total_odds' => $odds,
            'potential_payout_minor' => (int) bcmul((string) $stake, $odds, 0),
        ]);
        $this->select($ticket, $market, $key);
        app(LedgerService::class)->reserveStake($this->wallet(), $ticket->stake(), "bet:{$ticket->id}:stake", (string) $ticket->id);

        return $ticket;
    }

    /** Score final enregistré 2 h 30 après le coup d'envoi (règlement lancé par le job, file synchrone en test). */
    private function finish(Market $market, string $home, string $away): void
    {
        $this->travelTo($market->event->starts_at->addMinutes(150));
        app(ResultRecorder::class)->record($market->event->fresh(), ResultStatus::Final, $home, $away, source: 'theodds');
    }

    /** Match chez le fournisseur de statistiques, à la même heure que celui du catalogue. */
    private function fixture(Market $market, string $id, string $home, string $away, int $goalsHome, int $goalsAway, int $htHome = 0, int $htAway = 0): FixtureData
    {
        $fixture = new FixtureData($id, $home, $away, CarbonImmutable::parse($market->event->starts_at)->utc(), true, $goalsHome, $goalsAway, $htHome, $htAway);
        $this->stats->fixtures[] = $fixture;

        return $fixture;
    }

    public function test_a_corners_bet_waits_for_the_statistics_then_is_settled_on_the_number_of_corners(): void
    {
        $market = $this->market(key: 'alternate_totals_corners', odds: ['over_9.5' => '1.90', 'under_9.5' => '1.90']);
        $ticket = $this->bet($market, 'over_9.5');

        $this->finish($market, '2', '1');
        $this->assertSame(BetTicketStatus::Pending, $ticket->fresh()->status, 'Sans corners connus, le pari attend.');
        $this->artisan('settlement:run')->assertSuccessful(); // en attente : pas une erreur

        $this->fixture($market, '9001', 'Everton FC', 'Chelsea FC', 2, 1);
        $this->stats->statistics['9001'] = new StatisticsData('9001', 1, 0, 6, 5, 2, 1);
        $this->artisan('settlement:import-statistics')->assertSuccessful();

        $this->assertSame(BetTicketStatus::Won, $ticket->fresh()->status); // 6 + 5 = 11 corners > 9,5
        $this->assertSame(190_00, $ticket->fresh()->payout_minor);
        $this->assertSame(['over_9.5' => 'won', 'under_9.5' => 'lost'], $market->selections()->orderBy('key')->pluck('result', 'key')->all());
    }

    public function test_first_half_cards_and_halftime_fulltime_are_settled_on_their_own_figures(): void
    {
        $half = $this->market(key: 'h2h_h1', odds: ['home' => '3.10', 'draw' => '2.10', 'away' => '3.60']);
        $htft = $this->market(key: 'halftime_fulltime', odds: ['draw/home' => '4.50', 'home/home' => '2.80']);
        $cards = $this->market(key: 'alternate_spreads_cards', odds: ['home_+1.5' => '1.70', 'away_-1.5' => '2.10']);
        $teamCorners = $this->market(key: 'alternate_team_totals_corners', odds: ['away_over_4.5' => '1.80', 'away_under_4.5' => '1.95']);
        $tickets = [
            $this->bet($half, 'draw'),        // 0-0 à la mi-temps : gagné
            $this->bet($htft, 'draw/home'),   // nul à la mi-temps, victoire à domicile : gagné
            $this->bet($cards, 'away_-1.5'),  // cartons 3-2 : l'extérieur -1,5 → 0,5 < 3 : perdu
            $this->bet($teamCorners, 'away_over_4.5'), // 4 corners pour l'extérieur : perdu
        ];

        $this->finish($half, '1', '0');
        $this->fixture($half, '9002', 'Everton', 'Chelsea', 1, 0, 0, 0);
        $this->stats->statistics['9002'] = new StatisticsData('9002', 0, 0, 7, 4, 3, 2);
        app(StatisticsImportService::class)->import();

        $this->assertSame(
            [BetTicketStatus::Won, BetTicketStatus::Won, BetTicketStatus::Lost, BetTicketStatus::Lost],
            array_map(fn (BetTicket $t) => $t->fresh()->status, $tickets),
        );
    }

    public function test_a_bet_whose_statistics_never_arrive_is_void_after_the_deadline(): void
    {
        $market = $this->market(key: 'alternate_totals_cards', odds: ['over_3.5' => '2.00', 'under_3.5' => '1.75']);
        $ticket = $this->bet($market, 'over_3.5');

        $this->finish($market, '0', '0');
        $this->travelTo($market->event->starts_at->addHours(49));
        $this->artisan('settlement:run')->assertSuccessful();

        $this->assertSame(BetTicketStatus::Void, $ticket->fresh()->status);
        $this->assertSame(1000_00, app(LedgerService::class)->balance($this->wallet())->minor, 'Mise remboursée.');
    }

    public function test_a_fixture_is_recognised_only_with_close_kickoff_similar_teams_and_the_same_score(): void
    {
        $market = $this->market(key: 'totals_h1', line: '0.5', odds: ['over' => '1.40', 'under' => '2.90']);
        $ticket = $this->bet($market, 'over');
        $this->finish($market, '2', '1');

        // Autre score, autres équipes, autre heure : aucun ne convient.
        $this->fixture($market, 'x1', 'Everton', 'Chelsea', 1, 1);
        $this->fixture($market, 'x2', 'Arsenal', 'Liverpool', 2, 1);
        $this->stats->fixtures[] = new FixtureData('x3', 'Everton', 'Chelsea', CarbonImmutable::parse($market->event->starts_at)->addHours(3), true, 2, 1, 1, 0);

        $report = app(StatisticsImportService::class)->import();
        $this->assertSame(1, $report['not_found']);
        $this->assertSame(0, MatchStatistics::count());
        $this->assertSame(BetTicketStatus::Pending, $ticket->fresh()->status);

        // Le bon match, noms écrits autrement.
        $this->fixture($market, 'ok', 'Everton F.C.', 'Chelsea FC', 2, 1, 1, 0);
        app(StatisticsImportService::class)->import();
        $this->assertSame('ok', MatchStatistics::sole()->external_id);
        $this->assertSame(BetTicketStatus::Won, $ticket->fresh()->status);
    }

    public function test_only_matches_with_pending_statistics_bets_are_requested(): void
    {
        $goals = $this->market(externalId: 'goals-only', key: 'totals', line: '2.5', odds: ['over' => '1.90', 'under' => '1.90']);
        $this->bet($goals, 'over');
        $this->finish($goals, '3', '0');

        $report = app(StatisticsImportService::class)->import();

        $this->assertSame(0, $report['candidates']);
        $this->assertSame(0, $this->stats->calls);
    }

    public function test_a_provider_failure_records_nothing_and_is_reported(): void
    {
        $market = $this->market(key: 'btts_h1', odds: ['yes' => '4.50', 'no' => '1.20']);
        $ticket = $this->bet($market, 'no');
        $this->finish($market, '1', '1');
        $this->stats->failure = new SportsDataException(SportsDataException::QUOTA_EXHAUSTED, 'Limite du jour atteinte.');

        $this->artisan('settlement:import-statistics')->assertFailed();

        $this->assertSame(0, MatchStatistics::count());
        $this->assertSame(BetTicketStatus::Pending, $ticket->fresh()->status);
    }

    public function test_team_names_are_compared_without_accents_punctuation_or_club_words(): void
    {
        $import = app(StatisticsImportService::class);

        $this->assertSame(1.0, $import->similarity('Paris Saint Germain', 'Paris Saint-Germain FC'));
        $this->assertSame(1.0, $import->similarity('Atlético Madrid', 'Atletico Madrid'));
        $this->assertGreaterThanOrEqual(0.6, $import->similarity('Wolverhampton Wanderers', 'Wolves Wanderers'));
        $this->assertLessThan(0.6, $import->similarity('Everton', 'Liverpool'));
    }

    public function test_statistics_import_is_scheduled_only_with_a_provider(): void
    {
        $events = fn () => collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'settlement:import-statistics'));

        $this->assertCount(1, $events());
        $this->assertTrue($events()->first()->filtersPass($this->app));

        config(['sports_data.statistics.provider' => '']);
        $this->assertFalse($events()->first()->filtersPass($this->app));
    }
}
