<?php

namespace Tests\Feature\Modules\Settlement;

use App\Models\User;
use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Enums\BetType;
use App\Modules\Betting\Models\BetSelection;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Settlement\Enums\ResultStatus;
use App\Modules\Settlement\Jobs\SettleEventJob;
use App\Modules\Settlement\Models\Result;
use App\Modules\Settlement\Models\Settlement;
use App\Modules\Settlement\Services\ResultRecorder;
use App\Modules\Settlement\Services\SettlementService;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Modules\Betting\BuildsBetTickets;
use Tests\TestCase;

class SettlementServiceTest extends TestCase
{
    use BuildsBetTickets;
    use RefreshDatabase;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        config(['wallet.simulated' => true]);
        $this->player = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($this->wallet(), Money::ofMinor(1000_00, 'HTG'), 'bonus:test');
    }

    private function wallet(): Wallet
    {
        return Wallet::firstOrCreate(['user_id' => $this->player->id, 'currency' => 'HTG']);
    }

    private function balance(): int
    {
        return app(LedgerService::class)->balance($this->wallet())->minor;
    }

    /**
     * Ticket accepté (mise réservée sur le portefeuille), une sélection par marché donné.
     *
     * @param  array<int, array{0: Market, 1: string}>  $picks  [marché, clé de sélection]
     */
    private function bet(array $picks, int $stake = 100_00, array $attributes = []): BetTicket
    {
        $odds = array_reduce($picks, fn (string $c, $p) => bcmul($c, (string) $p[0]->selections()->where('key', $p[1])->value('odds'), 3), '1');
        $ticket = $this->ticket($this->player, [
            'type' => count($picks) > 1 ? BetType::Multiple : BetType::Single,
            'stake_minor' => $stake,
            'total_odds' => $odds,
            'potential_payout_minor' => (int) bcmul((string) $stake, $odds, 0),
            ...$attributes,
        ]);
        foreach ($picks as [$market, $key]) {
            $this->select($ticket, $market, $key);
        }
        app(LedgerService::class)->reserveStake($this->wallet(), $ticket->stake(), "bet:{$ticket->id}:stake", (string) $ticket->id);

        return $ticket;
    }

    /** Résultat enregistré après le match (le règlement part par le job, file synchrone en test). */
    private function finish(Market|Event $target, ?string $home, ?string $away, ResultStatus $status = ResultStatus::Final): Result
    {
        $event = $target instanceof Market ? $target->event : $target;
        $this->travelTo($event->starts_at->addHours(2));

        return app(ResultRecorder::class)->record($event->fresh(), $status, $home, $away, source: 'theodds');
    }

    private function payouts(): int
    {
        return LedgerEntry::whereIn('type', [LedgerEntryType::Payout, LedgerEntryType::Refund])->count();
    }

    public function test_a_winning_single_is_paid_once_with_the_accepted_odds(): void
    {
        $market = $this->market();
        $ticket = $this->bet([[$market, 'home']]);
        $this->assertSame(900_00, $this->balance());

        $result = $this->finish($market, '2', '1');

        $ticket->refresh();
        $this->assertSame(BetTicketStatus::Won, $ticket->status);
        $this->assertSame(205_00, $ticket->payout_minor);
        $this->assertNotNull($ticket->settled_at);
        $this->assertSame(1105_00, $this->balance());

        $selection = $ticket->selections()->first();
        $this->assertSame(BetSelectionStatus::Won, $selection->status);
        $this->assertSame($result->id, $selection->result_id);

        $settlement = Settlement::where('bet_ticket_id', $ticket->id)->sole();
        $this->assertSame(BetTicketStatus::Won, $settlement->status);
        $this->assertSame(205_00, $settlement->payout_minor);
        $this->assertSame([(string) $market->event_id => $result->id], $settlement->results);
        $entry = $settlement->ledgerEntry;
        $this->assertSame(LedgerEntryType::Payout, $entry->type);
        $this->assertSame("settlement:{$ticket->id}:r1:payout", $entry->reference);
        $this->assertSame((string) $ticket->id, $entry->related_id);

        // Catalogue : issue de chaque sélection, marché réglé.
        $this->assertSame(['away' => 'lost', 'home' => 'won'], $market->selections()->orderBy('key')->pluck('result', 'key')->all());
        $this->assertSame(MarketStatus::Settled, $market->fresh()->status);
    }

    public function test_replaying_the_settlement_never_pays_twice(): void
    {
        $market = $this->market();
        $ticket = $this->bet([[$market, 'home']]);
        $this->finish($market, '2', '1');

        $service = app(SettlementService::class);
        $service->settleEvent($market->event);
        $service->settleTicket($ticket);
        $service->sweep();
        SettleEventJob::dispatchSync($market->event_id);
        $this->artisan('settlement:run', ['--event' => [$market->event_id]])->assertSuccessful();

        $this->assertSame(1, $this->payouts());
        $this->assertSame(1, Settlement::count());
        $this->assertSame(1105_00, $this->balance());
    }

    public function test_a_losing_ticket_is_settled_without_ledger_entry(): void
    {
        $market = $this->market();
        $ticket = $this->bet([[$market, 'home']]);

        $this->finish($market, '0', '1');

        $this->assertSame(BetTicketStatus::Lost, $ticket->fresh()->status);
        $this->assertSame(0, $ticket->fresh()->payout_minor);
        $this->assertNull(Settlement::sole()->ledger_entry_id);
        $this->assertSame(0, $this->payouts());
        $this->assertSame(900_00, $this->balance());
    }

    public function test_a_cancelled_match_refunds_the_stake(): void
    {
        $market = $this->market();
        $ticket = $this->bet([[$market, 'home']]);

        $this->finish($market, null, null, ResultStatus::Cancelled);

        $this->assertSame(BetTicketStatus::Void, $ticket->fresh()->status);
        $this->assertSame(100_00, $ticket->fresh()->payout_minor);
        $this->assertSame(LedgerEntryType::Refund, Settlement::sole()->ledgerEntry->type);
        $this->assertSame(1000_00, $this->balance());
    }

    public function test_a_multiple_is_paid_only_when_every_match_has_a_result(): void
    {
        $first = $this->market('m1');
        $second = $this->market('m2', odds: ['home' => '1.50', 'away' => '2.60']);
        Event::whereKey($second->event_id)->update(['starts_at' => now()->addDays(2)]);
        $ticket = $this->bet([[$first, 'home'], [$second, 'away']]); // 2.05 × 2.60 = 5.33

        $this->finish($first, '1', '0');
        $this->assertSame(BetTicketStatus::Pending, $ticket->fresh()->status);
        $this->assertSame(0, Settlement::count());

        $this->finish($second->fresh(), '0', '2');
        $this->assertSame(BetTicketStatus::Won, $ticket->fresh()->status);
        $this->assertSame(533_00, $ticket->fresh()->payout_minor);
    }

    public function test_one_losing_selection_settles_a_multiple_as_lost_at_once(): void
    {
        $first = $this->market('m1');
        $second = $this->market('m2');
        Event::whereKey($second->event_id)->update(['starts_at' => now()->addDays(2)]);
        $ticket = $this->bet([[$first, 'home'], [$second, 'home']]);

        $this->finish($first, '0', '0');

        $this->assertSame(BetTicketStatus::Lost, $ticket->fresh()->status);

        // Le second match se termine plus tard : sa sélection est réglée, le ticket ne bouge plus.
        $this->finish($second->fresh(), '3', '0');
        $this->assertSame(BetSelectionStatus::Won, $ticket->selections()->where('market_id', $second->id)->value('status'));
        $this->assertSame(1, Settlement::count());
        $this->assertSame(0, $this->payouts());
    }

    public function test_a_void_selection_counts_as_odds_one_in_a_multiple(): void
    {
        $first = $this->market('m1', odds: ['home' => '2.333', 'away' => '3.40']);
        $second = $this->market('m2');
        Event::whereKey($second->event_id)->update(['starts_at' => now()->addDays(2)]);
        $ticket = $this->bet([[$first, 'home'], [$second, 'home']], stake: 10_01);

        $this->finish($first, '2', '0');
        $this->finish($second->fresh(), null, null, ResultStatus::Cancelled);

        // 10,01 × 2,333 = 23,35333 → 23,35 (arrondi à l'unité mineure inférieure).
        $this->assertSame(BetTicketStatus::Won, $ticket->fresh()->status);
        $this->assertSame(23_35, $ticket->fresh()->payout_minor);
    }

    public function test_totals_are_settled_with_the_line_frozen_on_the_ticket(): void
    {
        $market = $this->market(key: 'totals', line: '2.5', odds: ['over' => '1.80', 'under' => '2.00']);
        $ticket = $this->bet([[$market, 'over']]);
        $market->update(['line' => '3.5']); // la ligne du catalogue a bougé après le pari

        $this->finish($market, '2', '1');

        $this->assertSame(BetTicketStatus::Won, $ticket->fresh()->status);
    }

    public function test_totals_on_a_whole_line_reached_exactly_are_void(): void
    {
        $market = $this->market(key: 'totals', line: '3', odds: ['over' => '1.80', 'under' => '2.00']);
        $ticket = $this->bet([[$market, 'under']]);

        $this->finish($market, '2', '1');

        $this->assertSame(BetTicketStatus::Void, $ticket->fresh()->status);
        $this->assertSame(1000_00, $this->balance());
    }

    public function test_double_chance_and_both_teams_to_score(): void
    {
        $double = $this->market(key: 'double_chance', odds: ['home_draw' => '1.30', 'home_away' => '1.25', 'draw_away' => '1.70']);
        $btts = $this->market(key: 'btts', odds: ['yes' => '1.70', 'no' => '2.05']);
        $draw = $this->bet([[$double, 'draw_away']]);
        $yes = $this->bet([[$btts, 'yes']]);

        $this->finish($double, '1', '1');

        $this->assertSame(BetTicketStatus::Won, $draw->fresh()->status);
        $this->assertSame(BetTicketStatus::Won, $yes->fresh()->status);
        $this->assertSame(
            ['draw_away' => 'won', 'home_away' => 'lost', 'home_draw' => 'won'],
            $double->selections()->orderBy('key')->pluck('result', 'key')->all(),
        );
    }

    public function test_correct_score_is_won_only_on_the_exact_score(): void
    {
        $market = $this->market(key: 'correct_score', odds: ['2-1' => '9.00', '1-1' => '6.50']);
        $exact = $this->bet([[$market, '2-1']]);
        $other = $this->bet([[$market, '1-1']]);

        $this->finish($market, '2', '1');

        $this->assertSame(BetTicketStatus::Won, $exact->fresh()->status);
        $this->assertSame(900_00, $exact->fresh()->payout_minor);
        $this->assertSame(BetTicketStatus::Lost, $other->fresh()->status);
    }

    public function test_an_unknown_market_is_left_pending_and_reported(): void
    {
        $market = $this->market(key: 'exotic', odds: ['a' => '1.50', 'b' => '2.50']);
        $ticket = $this->bet([[$market, 'a']]);

        $this->finish($market, '1', '0');

        $this->assertSame(BetTicketStatus::Pending, $ticket->fresh()->status);
        $this->artisan('settlement:run')->assertFailed();
    }

    public function test_sweep_settles_tickets_left_pending_by_an_interrupted_run(): void
    {
        Queue::fake(); // le job ne part pas : rien n'est réglé à l'enregistrement du résultat
        $market = $this->market();
        $ticket = $this->bet([[$market, 'home']]);
        $this->finish($market, '2', '1');
        Queue::assertPushed(SettleEventJob::class, fn ($job) => $job->eventId === $market->event_id);
        $this->assertSame(BetTicketStatus::Pending, $ticket->fresh()->status);

        // Sélection réglée mais ticket resté "pending" (arrêt entre les deux étapes).
        BetSelection::where('bet_ticket_id', $ticket->id)->update(['status' => BetSelectionStatus::Won]);

        $this->artisan('settlement:run')->assertSuccessful();

        $this->assertSame(BetTicketStatus::Won, $ticket->fresh()->status);
        $this->assertSame(1, $this->payouts());
    }

    public function test_a_match_postponed_beyond_48_hours_is_void_once_the_deadline_passes(): void
    {
        $market = $this->market();
        $ticket = $this->bet([[$market, 'home']]);
        $planned = $market->event->starts_at;
        Event::whereKey($market->event_id)->update(['starts_at' => $planned->copy()->addDays(4)]); // décalé de 4 jours

        $this->travelTo($planned->copy()->addHours(47));
        $this->artisan('settlement:run')->assertSuccessful();
        $this->assertSame(BetTicketStatus::Pending, $ticket->fresh()->status);

        $this->travelTo($planned->copy()->addHours(49));
        $this->artisan('settlement:run')->assertSuccessful();

        $this->assertSame(BetTicketStatus::Void, $ticket->fresh()->status);
        $this->assertSame(1000_00, $this->balance());
        $selection = $ticket->selections()->sole();
        $this->assertSame(BetSelectionStatus::Void, $selection->status);
        $this->assertNull($selection->result_id);

        // Le match est finalement joué : le ticket ne bouge plus.
        $this->finish($market->fresh(), '2', '1');
        $this->assertSame(BetTicketStatus::Void, $ticket->fresh()->status);
        $this->assertSame(1, $this->payouts());
    }

    public function test_a_match_moved_within_48_hours_is_settled_on_its_score(): void
    {
        $market = $this->market();
        $ticket = $this->bet([[$market, 'home']]);
        Event::whereKey($market->event_id)->update(['starts_at' => $market->event->starts_at->copy()->addHours(19)]);

        $this->finish($market->fresh(), '2', '1');

        $this->assertSame(BetTicketStatus::Won, $ticket->fresh()->status);
    }

    public function test_a_match_played_late_beyond_48_hours_is_void_whatever_the_score(): void
    {
        $first = $this->market('m1');
        $second = $this->market('m2');
        $ticket = $this->bet([[$first, 'home'], [$second, 'home']]); // 2,05 × 2,05
        Event::whereKey($second->event_id)->update(['starts_at' => $second->event->starts_at->copy()->addDays(3)]);

        $this->finish($first, '1', '0');
        $this->finish($second->fresh(), '0', '1'); // résultat arrivé avant le rattrapage : annulé quand même

        $this->assertSame(BetTicketStatus::Won, $ticket->fresh()->status);
        $this->assertSame(205_00, $ticket->fresh()->payout_minor); // 100 × 2,05 × 1
    }

    public function test_a_match_played_on_time_without_score_waits_for_its_result(): void
    {
        $market = $this->market();
        $ticket = $this->bet([[$market, 'home']]);

        $this->travelTo($market->event->starts_at->copy()->addDays(3));
        $this->artisan('settlement:run')->assertSuccessful();

        $this->assertSame(BetTicketStatus::Pending, $ticket->fresh()->status);
    }

    public function test_the_postponement_delay_frozen_on_the_ticket_applies(): void
    {
        $market = $this->market();
        $ticket = $this->bet([[$market, 'home']], attributes: ['rules_snapshot' => ['postponed_void_hours' => 24]]);
        Event::whereKey($market->event_id)->update(['starts_at' => $market->event->starts_at->copy()->addDays(2)]);

        $this->travelTo($market->event->starts_at->copy()->addHours(25));
        $this->artisan('settlement:run')->assertSuccessful();

        $this->assertSame(BetTicketStatus::Void, $ticket->fresh()->status);
    }

    public function test_settlement_catch_up_is_scheduled(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'settlement:run'));

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
    }
}
