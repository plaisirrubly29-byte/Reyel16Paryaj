<?php

namespace Tests\Feature\Modules\Betting;

use App\Models\User;
use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Betting\Services\LiveAcceptanceService;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Models\Selection;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Modules\SportsData\Services\CreditBudget;
use App\Modules\SportsData\Services\LiveOddsService;
use App\Modules\SportsData\Services\LiveUpdateService;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Paris en direct (décision D-20, option « Modèle + The Odds ») : cotes du modèle recalculées à chaque lecture,
 * suspension automatique, délai d'acceptation, mise maximale du direct et coupe-circuit.
 */
class LiveBettingTest extends TestCase
{
    use RefreshDatabase;

    private FakeSportsDataProvider $fake;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wallet.simulated' => true,
            'betting.allow_live' => true,
            'sports_data.max_odds_age_minutes' => 300,
            'sports_data.live_window_minutes' => 180,
            'sports_data.live_daily_credits' => 1000,
            'sports_data.theodds.markets' => 'h2h,totals',
        ]);
        $this->fake = new FakeSportsDataProvider;
        $this->app->instance(SportsDataProvider::class, $this->fake);

        // Avant le match : force des équipes tirée des cotes (fake-1 Arsenal – Chelsea : 2.10 / 3.25 / 3.40, +2,5 à 1.80).
        app(CatalogueSyncService::class)->sync('soccer_epl');
        $kickoff = CarbonImmutable::parse($this->event()->starts_at);
        $this->travelTo($kickoff->addMinutes(30));
        $this->fake->setStartsAt('fake-1', $kickoff)->setResult('fake-1', '0', '0', completed: false);
        // En direct : score 0-0 (relu toutes les 30 s), puis cotes du fournisseur.
        app(LiveUpdateService::class)->runScores();
        app(LiveUpdateService::class)->run();

        $this->player = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($this->player), Money::ofMinor(50_000_00, 'HTG'), 'test:'.Str::uuid());
    }

    private function event(string $externalId = 'fake-1'): Event
    {
        return Event::where('external_id', $externalId)->firstOrFail();
    }

    private function market(string $key = 'h2h'): Market
    {
        return $this->event()->markets()->where('key', $key)->firstOrFail();
    }

    private function selection(string $market = 'h2h', string $key = 'home'): Selection
    {
        return $this->market($market)->selections()->where('key', $key)->firstOrFail();
    }

    private function liveOdds(string $market = 'h2h', string $key = 'home'): ?string
    {
        return app(LiveOddsService::class)->quote($this->market($market))->odds[$key] ?? null;
    }

    private function bet(Selection $selection, ?string $odds = null, int $stake = 100_00, array $more = []): TestResponse
    {
        $picks = [['selection_id' => $selection->id, 'odds' => $odds ?? $this->liveOdds($selection->market->key, $selection->key)], ...$more];

        return $this->actingAs($this->player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => $stake, 'currency' => 'HTG'],
            'selections' => $picks,
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function balance(): int
    {
        $ledger = app(LedgerService::class);

        return $ledger->balance($ledger->walletFor($this->player))->minor;
    }

    private function score(string $home, string $away): void
    {
        $this->fake->setResult('fake-1', $home, $away, completed: false);
        app(LiveUpdateService::class)->runScores();
    }

    public function test_the_pre_match_odds_give_each_team_its_attacking_strength(): void
    {
        // Match à venir : buts attendus sur le match tirés du plus/moins 2,5 (1.65 / 2.20) et du 1X2.
        $upcoming = $this->event('fake-2');
        $this->assertGreaterThan($upcoming->live_away_rate, $upcoming->live_home_rate, 'Liverpool est favori.');
        $this->assertEqualsWithDelta(3.0, $upcoming->live_home_rate + $upcoming->live_away_rate, 0.5);
        $this->assertGreaterThan(1.0, $upcoming->live_draw_boost);

        // Match en cours : recalé sur les cotes en direct du fournisseur. Les mêmes cotes qu'avant le match à la
        // 30e minute (0-0) disent que les équipes attaquent plus que prévu.
        $event = $this->event();
        $this->assertSame(EventStatus::Live, $event->status);
        $this->assertGreaterThan($event->live_away_rate, $event->live_home_rate, 'Arsenal est favori.');
        $this->assertGreaterThan(3.0, $event->live_home_rate + $event->live_away_rate);
        $this->assertTrue($event->live_model_at->gt($event->starts_at));
        $this->assertNotNull($event->score_checked_at);
    }

    public function test_live_odds_are_recomputed_every_second_with_the_margin_and_never_above_the_provider(): void
    {
        $first = $this->liveOdds();
        $this->assertNotNull($first);
        // Jamais mieux payé que la dernière cote en direct de The Odds API (2.10 pour Arsenal).
        $this->assertLessThanOrEqual(2.10, (float) $first);

        // Le temps passe à 0-0 : le nul devient plus probable, donc moins bien payé.
        $draw = (float) $this->liveOdds('h2h', 'draw');
        $over = (float) $this->liveOdds('totals', 'over');
        $this->travel(20)->minutes();
        $this->score('0', '0');
        $this->assertLessThan($draw, (float) $this->liveOdds('h2h', 'draw'));
        $this->assertGreaterThan($over, (float) $this->liveOdds('totals', 'over'), 'Moins de temps pour marquer 3 buts.');
    }

    public function test_a_goal_suspends_the_markets_then_reprices_them_with_the_new_score(): void
    {
        $before = (float) $this->liveOdds('h2h', 'away');

        $this->score('0', '1');
        $event = $this->event();
        $this->assertNotNull($event->score_changed_at);
        $quote = app(LiveOddsService::class)->quote($this->market());
        $this->assertFalse($quote->open);
        $this->assertSame(LiveOddsService::SCORE_CHANGED, $quote->reason);

        // Après le délai de vérification : rouvert, Chelsea qui mène est bien moins payé ; la cote du fournisseur
        // d'avant le but ne plafonne plus rien.
        $this->travel(31)->seconds();
        $this->score('0', '1');
        $after = (float) $this->liveOdds('h2h', 'away');
        $this->assertLessThan($before / 1.5, $after);
        $this->assertGreaterThan(3.40, (float) $this->liveOdds('h2h', 'home'));
    }

    public function test_markets_are_suspended_when_the_score_is_too_old(): void
    {
        $this->travel(91)->seconds();

        $quote = app(LiveOddsService::class)->quote($this->market());
        $this->assertFalse($quote->open);
        $this->assertSame(LiveOddsService::SCORE_STALE, $quote->reason);
        $this->assertFalse($this->market()->isBettable());
        $this->bet($this->selection(), '2.00')->assertUnprocessable()->assertJsonPath('error.code', 'selection_unavailable');
    }

    /** Signalé par Rubly : une suspension après une action ne doit pas durer plus d'une dizaine de secondes. */
    public function test_a_goal_suspension_lifts_by_itself_after_ten_seconds_without_a_new_score(): void
    {
        $this->score('1', '0');
        $live = app(LiveOddsService::class);
        $this->assertSame(LiveOddsService::SCORE_CHANGED, $live->quote($this->market())->reason);

        $this->travel(9)->seconds();
        $this->assertSame(LiveOddsService::SCORE_CHANGED, $live->quote($this->market())->reason);
        $this->assertFalse($this->market()->isBettable());

        // Aucun nouveau score relu : la suspension est quand même levée, au bout de 10 s.
        $this->travel(2)->seconds();
        $this->assertTrue($live->quote($this->market())->open);
        $this->assertTrue($live->quote($this->market('totals'))->open);
        $this->assertTrue($this->market()->isBettable());
        $this->getJson("/api/v1/live/odds?event_ids={$this->event()->id}")
            ->assertJsonPath('data.0.markets.0.bettable', true)
            ->assertJsonPath('data.0.markets.0.suspended_reason', null);
        $this->bet($this->selection())->assertAccepted(); // pari en direct : en attente du délai d'acceptation
    }

    public function test_the_action_suspension_length_comes_from_the_config(): void
    {
        config(['sports_data.live_model.action_suspension_seconds' => 5]);
        $this->score('0', '1');
        $live = app(LiveOddsService::class);

        $this->travel(4)->seconds();
        $this->assertSame(LiveOddsService::SCORE_CHANGED, $live->quote($this->market())->reason);
        $this->travel(2)->seconds();
        $this->assertTrue($live->quote($this->market())->open);
    }

    public function test_a_match_whose_score_stops_coming_is_closed_instead_of_staying_suspended(): void
    {
        $live = app(LiveOddsService::class);

        // Un score en retard : suspendu un moment, le temps du prochain passage.
        $this->travel(91)->seconds();
        $this->assertSame(LiveOddsService::SCORE_STALE, $live->quote($this->market())->reason);

        // Plus aucun score depuis 5 min (match arrêté, ou plus suivi par le fournisseur) : direct fermé.
        $this->travel(210)->seconds();
        $this->assertSame(LiveOddsService::SCORE_UNAVAILABLE, $live->quote($this->market())->reason);
        $this->assertFalse($this->market()->isBettable());
        $this->getJson("/api/v1/live/odds?event_ids={$this->event()->id}")
            ->assertJsonPath('data.0.markets.0.bettable', false)
            ->assertJsonPath('data.0.markets.0.suspended_reason', LiveOddsService::SCORE_UNAVAILABLE);

        // Le score revient (match repris) : rouvert de lui-même.
        $this->score('0', '0');
        $this->assertTrue($live->quote($this->market())->open);
    }

    public function test_a_match_never_scored_by_the_provider_is_closed_after_a_few_minutes(): void
    {
        // The Odds API ne donne pas le score en direct de toutes les compétitions : score jamais reçu.
        $this->event()->update(['home_score' => null, 'away_score' => null, 'score_checked_at' => null,
            'score_changed_at' => null, 'starts_at' => now()->subMinutes(2)]);
        $live = app(LiveOddsService::class);
        $this->assertSame(LiveOddsService::SCORE_STALE, $live->quote($this->market())->reason);

        $this->travel(4)->minutes();
        $this->assertSame(LiveOddsService::SCORE_UNAVAILABLE, $live->quote($this->market())->reason);
    }

    public function test_a_match_past_the_cutoff_minute_is_closed_even_when_its_score_is_no_longer_read(): void
    {
        // Fin de match : le score n'est plus relu toutes les 30 s. Avant, le motif restait « score en cours de
        // vérification » (suspendu) jusqu'au résultat final.
        $this->travel(80)->minutes();
        $this->assertSame(LiveOddsService::CLOSING, app(LiveOddsService::class)->quote($this->market())->reason);
    }

    public function test_the_live_markets_close_after_the_cutoff_minute(): void
    {
        $this->assertTrue(app(LiveOddsService::class)->quote($this->market('totals'))->open);

        $this->travel(65)->minutes(); // 95 min après le coup d'envoi : environ la 79e minute
        $this->score('0', '0');
        $this->assertTrue(app(LiveOddsService::class)->quote($this->market())->open);
        $this->travel(7)->minutes(); // ~86e minute
        $this->score('0', '0');
        $this->assertSame(LiveOddsService::CLOSING, app(LiveOddsService::class)->quote($this->market())->reason);
    }

    public function test_a_totals_line_already_passed_is_suspended(): void
    {
        $this->score('2', '1');
        $this->travel(31)->seconds();
        $this->score('2', '1');

        $this->assertSame(LiveOddsService::DECIDED, app(LiveOddsService::class)->quote($this->market('totals'))->reason);
    }

    /** Demande de Rubly : « un match est à 2-1, je peux parier que le match peut terminer 3-1 ». */
    private function scoreTwoOne(): void
    {
        $this->score('2', '1');
        $this->travel(31)->seconds(); // fin de la vérification du but
        $this->score('2', '1');
    }

    public function test_the_final_correct_score_is_offered_live_from_the_current_score(): void
    {
        // Dès le début du match : un score exact coté par le modèle, de 0-0 à 3-3.
        $quote = app(LiveOddsService::class)->quote($this->market('correct_score'));
        $this->assertTrue($quote->open);
        $this->assertArrayHasKey('0-0', $quote->odds);
        $this->assertArrayHasKey('1-0', $quote->odds);

        $this->scoreTwoOne();
        $market = $this->market('correct_score');
        $quote = app(LiveOddsService::class)->quote($market);
        $this->assertTrue($quote->open);
        $this->assertArrayHasKey('3-1', $quote->odds);
        $this->assertArrayHasKey('2-1', $quote->odds);
        $this->assertArrayNotHasKey('1-0', $quote->odds, 'Score déjà dépassé : plus proposé.');
        $this->assertArrayNotHasKey('2-0', $quote->odds);
        $this->assertLessThan((float) $quote->odds['5-4'], (float) $quote->odds['3-1'], 'Un but de plus est plus probable que cinq.');
        $this->assertSame('reyel16', $market->source_bookmaker);

        // Catalogue, onglet Live et coupon : seulement les scores encore possibles, dans l'ordre.
        $catalogue = collect($this->getJson("/api/v1/events/{$this->event()->id}/markets")->assertOk()->json('data.markets'))
            ->firstWhere('key', 'correct_score');
        $keys = collect($catalogue['selections'])->pluck('key');
        $this->assertTrue($catalogue['bettable']);
        $this->assertSame('2-1', $keys->first());
        $this->assertContains('3-1', $keys);
        $this->assertNotContains('1-0', $keys);
        $this->assertNotContains('0-0', $keys);
        $this->assertSame('3-1', collect($catalogue['selections'])->firstWhere('key', '3-1')['label']);

        $live = collect($this->getJson("/api/v1/live/odds?event_ids={$this->event()->id}")->assertOk()->json('data.0.markets'))
            ->firstWhere('key', 'correct_score');
        $this->assertTrue($live['bettable']);
        $this->assertSame($keys->all(), collect($live['selections'])->pluck('key')->all());
        $this->assertSame('3 - 1', collect($live['selections'])->firstWhere('key', '3-1')['name']);
    }

    public function test_a_live_bet_on_the_final_correct_score_is_accepted_but_not_on_a_score_already_passed(): void
    {
        $this->scoreTwoOne();

        $this->bet($this->selection('correct_score', '1-0'), '10.00')->assertUnprocessable()
            ->assertJsonPath('error.code', 'selection_unavailable');
        // Coté par notre seul modèle : même mise maximale que les paris spéciaux REYEL 16.
        $this->bet($this->selection('correct_score', '3-1'), null, 1_500_00)->assertUnprocessable()
            ->assertJsonPath('error.code', 'stake_out_of_range');

        $this->bet($this->selection('correct_score', '3-1'))->assertStatus(202)->assertJsonPath('data.status', 'awaiting');
        $ticket = BetTicket::sole();
        $this->assertSame('2-1', $ticket->selections()->value('live_score'));

        $this->travel(8)->seconds();
        $this->score('2', '1');
        $this->actingAs($this->player)->getJson("/api/v1/bets/{$ticket->id}")->assertOk()
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_the_correct_score_bet_is_rejected_if_a_goal_is_scored_during_the_delay(): void
    {
        $this->scoreTwoOne();
        $this->bet($this->selection('correct_score', '2-1'))->assertStatus(202);

        $ticket = BetTicket::sole();

        $this->travel(5)->seconds();
        $this->score('3', '1');
        $this->travel(3)->seconds();

        $this->assertSame(BetTicketStatus::Rejected, app(LiveAcceptanceService::class)->process($ticket->id));
        $this->assertSame('score_changed', $ticket->refresh()->rejection_reason);
        $this->assertSame(50_000_00, $this->balance(), 'Mise rendue.');
        // Après le but, « 2-1 » n'est plus proposé, « 4-1 » oui.
        $this->travel(31)->seconds();
        $this->score('3', '1');
        $odds = app(LiveOddsService::class)->quote($this->market('correct_score'))->odds;
        $this->assertArrayNotHasKey('2-1', $odds);
        $this->assertArrayHasKey('4-1', $odds);
        $this->assertArrayHasKey('6-4', $odds);
    }

    public function test_a_live_bet_waits_for_the_acceptance_delay_then_is_accepted(): void
    {
        $odds = $this->liveOdds();
        $response = $this->bet($this->selection(), $odds)->assertStatus(202)
            ->assertJsonPath('data.status', 'awaiting')
            ->assertJsonPath('data.live', true);
        $this->assertNotNull($response->json('data.acceptance_due_at'));

        $ticket = BetTicket::sole();
        $this->assertSame(BetTicketStatus::Awaiting, $ticket->status);
        $this->assertSame('0-0', $ticket->selections()->value('live_score'));
        $this->assertSame(49_900_00, $this->balance(), 'Mise réservée tout de suite.');

        // Pas avant la fin du délai.
        $this->assertNull(app(LiveAcceptanceService::class)->process($ticket->id));

        $this->travel(8)->seconds();
        $this->score('0', '0');
        $this->actingAs($this->player)->getJson("/api/v1/bets/{$ticket->id}")->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.rejection_reason', null);
        $this->assertSame(49_900_00, $this->balance());
    }

    public function test_a_live_bet_is_rejected_and_refunded_if_the_score_changed_during_the_delay(): void
    {
        $this->bet($this->selection())->assertStatus(202);
        $ticket = BetTicket::sole();

        $this->travel(5)->seconds();
        $this->score('0', '1');
        $this->travel(3)->seconds();

        $this->assertSame(BetTicketStatus::Rejected, app(LiveAcceptanceService::class)->process($ticket->id));
        $ticket->refresh();
        $this->assertSame('score_changed', $ticket->rejection_reason);
        $this->assertSame(50_000_00, $this->balance(), 'Mise rendue.');
        $this->assertSame(BetSelectionStatus::Void, $ticket->selections()->first()->status);
        $refund = LedgerEntry::where('type', LedgerEntryType::Refund)->sole();
        $this->assertSame("bet:{$ticket->id}:rejected", $refund->reference);
        $this->assertSame(100_00, $refund->amount_minor);
    }

    public function test_the_kill_switch_rejects_live_bets_waiting_for_acceptance(): void
    {
        $this->bet($this->selection())->assertStatus(202);
        config(['betting.allow_live' => false]);
        $this->travel(8)->seconds();

        $this->artisan('betting:live-acceptance')->assertSuccessful();
        $this->assertSame('live_closed', BetTicket::sole()->rejection_reason);
        $this->assertSame(50_000_00, $this->balance());
        $this->bet($this->selection(), '2.00')->assertUnprocessable()->assertJsonPath('error.code', 'selection_unavailable');
    }

    public function test_a_live_bet_never_checked_in_time_is_rejected(): void
    {
        $this->bet($this->selection())->assertStatus(202);
        $this->travel(61)->seconds();
        $this->score('0', '0');

        app(LiveAcceptanceService::class)->processDue();
        $this->assertSame('timeout', BetTicket::sole()->rejection_reason);
        $this->assertSame(50_000_00, $this->balance());
    }

    public function test_a_small_odds_drift_is_accepted_at_the_lower_odds_a_large_one_is_refused(): void
    {
        $current = $this->liveOdds();
        $seen = bcmul($current, '1.02', 2); // vue 2 % plus haut il y a un instant

        $this->bet($this->selection(), $seen)->assertStatus(202)->assertJsonPath('data.total_odds', rtrim($current, '0'));

        $tooHigh = bcmul($current, '1.10', 2);
        $this->bet($this->selection(), $tooHigh)->assertStatus(409)
            ->assertJsonPath('error.code', 'odds_changed')
            ->assertJsonPath('error.details.selections.0.odds', rtrim($current, '0'));
    }

    public function test_live_bets_have_their_own_max_stake_and_are_singles_only(): void
    {
        config(['betting.live_max_stake_minor' => 1000_00]);
        $this->bet($this->selection(), stake: 1000_01)->assertUnprocessable()
            ->assertJsonPath('error.code', 'stake_out_of_range')
            ->assertJsonPath('error.details.max.minor', 1000_00);

        $upcoming = $this->event('fake-2');
        $upcoming->update(['starts_at' => now()->addDay()]);
        $upcoming->markets()->update(['odds_updated_at' => now()]);
        $other = $upcoming->markets()->where('key', 'h2h')->firstOrFail()->selections()->where('key', 'home')->firstOrFail();
        $this->bet($this->selection(), more: [['selection_id' => $other->id, 'odds' => (string) $other->odds]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'live_multiple_not_allowed');
        $this->assertSame(0, BetTicket::count());
    }

    public function test_an_admin_suspension_closes_the_live_market(): void
    {
        $this->market()->update(['status' => MarketStatus::Suspended, 'suspension_reason' => 'Contrôle']);

        $this->assertSame(LiveOddsService::SUSPENDED, app(LiveOddsService::class)->quote($this->market())->reason);
    }

    public function test_the_live_odds_endpoint_returns_score_minute_and_current_odds(): void
    {
        $event = $this->event();
        $response = $this->getJson("/api/v1/live/odds?event_ids={$event->id},".$this->event('fake-2')->id)->assertOk()
            ->assertJsonPath('live_betting.enabled', true)
            ->assertJsonPath('live_betting.acceptance_delay_seconds', 8);

        $live = collect($response->json('data'))->firstWhere('id', (string) $event->id);
        $this->assertSame('live', $live['status']);
        $this->assertSame(['home' => '0', 'away' => '0'], $live['score']);
        $this->assertSame(31, $live['minute']);
        $h2h = collect($live['markets'])->firstWhere('key', 'h2h');
        $this->assertTrue($h2h['bettable']);
        $this->assertSame(rtrim($this->liveOdds(), '0'), collect($h2h['selections'])->firstWhere('key', 'home')['odds']);
        $this->assertSame([], collect($response->json('data'))->firstWhere('id', (string) $this->event('fake-2')->id)['markets']);

        $this->travel(91)->seconds();
        $this->getJson("/api/v1/live/odds?event_ids={$event->id}")
            ->assertJsonPath('data.0.markets.0.bettable', false)
            ->assertJsonPath('data.0.markets.0.suspended_reason', 'score_stale');

        $this->getJson('/api/v1/live/odds?event_ids=abc')->assertUnprocessable();
    }

    public function test_the_catalogue_shows_the_live_model_odds(): void
    {
        $markets = collect($this->getJson("/api/v1/events/{$this->event()->id}/markets")->assertOk()->json('data.markets'));
        $h2h = $markets->firstWhere('key', 'h2h');

        $this->assertTrue($h2h['bettable']);
        $this->assertNull($h2h['suspended_reason']);
        $this->assertSame(rtrim($this->liveOdds(), '0'), collect($h2h['selections'])->firstWhere('key', 'home')['odds']);
    }

    public function test_live_scores_cost_one_credit_per_competition_and_respect_the_daily_cap(): void
    {
        $live = app(LiveUpdateService::class);
        $spent = $live->creditsSpentToday();

        $this->assertSame(['soccer_epl'], $live->runScores()['updated']);
        $this->assertSame($spent + 1, $live->creditsSpentToday());

        config(['sports_data.live_daily_credits' => $spent + 1]);
        $this->assertSame(['soccer_epl'], $live->runScores()['skipped']);
    }

    /** Signalé par Rubly : les cotes de tous les sports vidaient le plafond du jour, plus de score, direct fermé. */
    public function test_the_odds_sync_leaves_half_of_the_daily_cap_to_the_scores(): void
    {
        $live = app(LiveUpdateService::class);
        $spent = $live->creditsSpentToday();
        config(['sports_data.live_daily_credits' => 2 * $spent]);

        $this->assertSame(['soccer_epl'], $live->run()['skipped']);
        $this->assertSame(['soccer_epl'], $live->runScores()['updated']);
        $this->score('0', '0');
        $this->assertTrue(app(LiveOddsService::class)->quote($this->market())->open);
    }

    public function test_the_odds_sync_does_not_pay_again_for_scores_read_every_30_seconds(): void
    {
        $live = app(LiveUpdateService::class);
        $spent = $live->creditsSpentToday();

        $live->run();
        $this->assertSame($spent + app(CreditBudget::class)->costPerCompetition(), $live->creditsSpentToday());

        // Direct coupé : plus de scores toutes les 30 s, la synchro des cotes relit le score elle-même.
        config(['betting.allow_live' => false]);
        $spent = $live->creditsSpentToday();
        $live->run();
        $this->assertSame($spent + 1 + app(CreditBudget::class)->costPerCompetition(), $live->creditsSpentToday());
    }

    public function test_live_scores_are_only_read_while_the_match_can_still_be_bet_on(): void
    {
        // Plafond de crédits gardé pour les matchs pariables : après la fin des paris (85e minute + mi-temps + marge),
        // le score n'est plus relu toutes les 30 s ; la synchro en direct (toutes les 15 min) s'occupe de la fin du match.
        $live = app(LiveUpdateService::class);
        $this->assertSame(['soccer_epl'], $live->liveBettingCompetitions());

        $this->travel(80)->minutes(); // 110 minutes après le coup d'envoi
        $this->assertSame([], $live->liveBettingCompetitions());
        $this->assertSame([], $live->runScores()['updated']);
        $this->assertContains('soccer_epl', $live->liveCompetitions());
    }

    public function test_the_live_scores_task_only_runs_when_live_betting_is_open(): void
    {
        $this->artisan('sports-data:live-scores')->assertSuccessful();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'sports-data:live-scores'));
        $this->assertNotNull($event);
        $this->assertTrue($event->filtersPass($this->app));
        config(['betting.allow_live' => false]);
        $this->assertFalse($event->filtersPass($this->app));
    }
}
