<?php

namespace Tests\Feature\Acceptance;

use App\Models\User;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Settlement\Enums\ResultCorrectionStatus;
use App\Modules\Settlement\Enums\ResultStatus;
use App\Modules\Settlement\Models\Result;
use App\Modules\Settlement\Models\ResultCorrection;
use App\Modules\Settlement\Services\ResultCorrectionService;
use App\Modules\Settlement\Services\ResultImportService;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\OddsSnapshot;
use App\Modules\SportsData\Models\Selection;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Http\ApiException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * P4-07 — Tests d'acceptation des tickets : critères §11 du document d'architecture liés aux tickets.
 *
 * Chaque test suit le parcours réel par l'API (synchronisation du faux fournisseur → POST /bets → import du
 * résultat → règlement → GET /wallet, GET /bets) et vérifie l'effet sur le ledger, seule source du solde.
 * Correspondance critères ↔ tests : docs/qa/P4-07-tests-acceptation-tickets.md.
 */
class TicketAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    /** fake-1 Arsenal–Chelsea commence le lendemain à 15 h ; Arsenal (domicile) est coté 2.10. */
    private const NOW = '2026-10-20 10:00:00';

    private const BALANCE = 1000_00;

    private const STAKE = 100_00;

    private FakeSportsDataProvider $fake;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sports_data.max_odds_age_minutes' => 300, 'sports_data.results_delay_minutes' => 105, 'wallet.simulated' => true]);
        $this->fake = new FakeSportsDataProvider;
        // Heure du match fixe : le faux fournisseur la calcule sinon à partir de « maintenant ».
        $this->fake->setStartsAt('fake-1', CarbonImmutable::parse('2026-10-21 15:00:00'));
        $this->app->instance(SportsDataProvider::class, $this->fake);

        $this->travelTo(self::NOW);
        $this->sync();

        $this->player = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($this->player), Money::ofMinor(self::BALANCE, 'HTG'), 'test:'.Str::uuid());
    }

    private function sync(): void
    {
        app(CatalogueSyncService::class)->sync('soccer_epl');
    }

    private function selection(string $key = 'home'): Selection
    {
        return Event::where('external_id', 'fake-1')->firstOrFail()
            ->markets()->where('key', 'h2h')->firstOrFail()
            ->selections()->where('key', $key)->firstOrFail();
    }

    /** Pari par l'API, avec la cote affichée au joueur (par défaut celle du catalogue). */
    private function bet(?string $odds = null, ?string $key = null, array $extra = [], ?Selection $selection = null): TestResponse
    {
        $selection ??= $this->selection();

        return $this->actingAs($this->player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => self::STAKE, 'currency' => 'HTG'],
            'selections' => [['selection_id' => $selection->id, 'odds' => $odds ?? (string) $selection->odds]],
        ] + $extra, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /** Solde lu par l'API, calculé par le serveur à partir du ledger. */
    private function balance(): int
    {
        return $this->actingAs($this->player)->getJson('/api/v1/wallet')->assertOk()->json('data.balance.minor');
    }

    /** Fin du match fake-1 et publication du score par le fournisseur, puis import (règlement inclus). */
    private function finishMatch(string $home, string $away): void
    {
        $this->travelTo('2026-10-21 17:30:00');
        $this->fake->setResult('fake-1', $home, $away);
        $this->assertTrue(app(ResultImportService::class)->import('soccer_epl')->succeeded());
    }

    private function assertNothingAccepted(): void
    {
        $this->assertSame(0, BetTicket::count());
        $this->assertSame(0, LedgerEntry::where('type', LedgerEntryType::Stake)->count());
        $this->assertSame(self::BALANCE, $this->balance());
    }

    // §11.1 — Un marché fermé ou une cote périmée ne peut pas être accepté.

    public function test_c1_a_closed_market_is_never_accepted(): void
    {
        $this->finishMatch('2', '1'); // le résultat ferme puis règle tous les marchés du match

        $this->assertSame(MarketStatus::Settled, $this->selection()->market->status);
        $this->bet()->assertStatus(422)->assertJsonPath('error.code', 'selection_unavailable');
        $this->assertNothingAccepted();
    }

    public function test_c1_a_stale_odds_is_never_accepted(): void
    {
        $this->travel(301)->minutes(); // au-delà de la règle de fraîcheur (300 min)

        $this->bet()->assertStatus(422)->assertJsonPath('error.code', 'selection_unavailable');
        $this->assertNothingAccepted();
    }

    public function test_c1_a_match_that_has_started_is_never_accepted(): void
    {
        $this->travelTo('2026-10-21 14:30:00');
        $this->sync();
        $this->travelTo('2026-10-21 15:01:00'); // cote encore fraîche, mais le match a commencé

        $this->assertTrue($this->selection()->market->odds_updated_at->gt(now()->subMinutes(300)));
        $this->bet()->assertStatus(422)->assertJsonPath('error.code', 'selection_unavailable');
        $this->assertNothingAccepted();
    }

    // §11.2 — Une répétition de la même demande avec la même clé d'idempotence ne crée pas un deuxième ticket.

    public function test_c2_the_same_request_with_the_same_key_creates_one_ticket_and_one_stake(): void
    {
        $key = (string) Str::uuid();

        $first = $this->bet(key: $key)->assertCreated();
        $this->bet(key: $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, BetTicket::count());
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntryType::Stake)->count());
        $this->assertSame(self::BALANCE - self::STAKE, $this->balance());
        $this->actingAs($this->player)->getJson('/api/v1/bets')->assertJsonCount(1, 'data');
    }

    public function test_c2_the_same_key_reused_for_another_bet_is_refused(): void
    {
        $key = (string) Str::uuid();
        $this->bet(key: $key)->assertCreated();

        $this->bet(key: $key, selection: $this->selection('away'))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_key_reused');
        $this->assertSame(1, BetTicket::count());
    }

    // §11.3 — Une erreur du fournisseur ne doit pas transformer un pari refusé en pari accepté.

    public function test_c3_a_provider_outage_never_refreshes_odds_so_bets_on_stale_odds_stay_refused(): void
    {
        $this->fake->failWith(SportsDataException::UNAVAILABLE);
        $before = $this->selection()->market->only(['odds_version', 'odds_updated_at', 'status']);

        $this->travel(301)->minutes();
        $this->sync(); // la synchronisation échoue : rien n'est écrit, la cote vieillit

        $this->assertEquals($before, $this->selection()->market->only(['odds_version', 'odds_updated_at', 'status']));

        $key = (string) Str::uuid();
        $this->bet(key: $key)->assertStatus(422)->assertJsonPath('error.code', 'selection_unavailable');
        // Nouvel essai pendant la panne, avec la même clé : toujours refusé, rien n'a été mémorisé comme accepté.
        $this->bet(key: $key)->assertStatus(422);
        $this->assertNothingAccepted();
    }

    public function test_c3_a_provider_outage_on_results_never_settles_or_pays(): void
    {
        $this->bet()->assertCreated();

        $this->travelTo('2026-10-21 17:30:00');
        $this->fake->setResult('fake-1', '2', '1')->failWith(SportsDataException::UNAVAILABLE);
        $this->assertFalse(app(ResultImportService::class)->import('soccer_epl')->succeeded());
        Artisan::call('settlement:run');

        $this->assertSame('pending', BetTicket::sole()->status->value);
        $this->assertSame(0, LedgerEntry::where('type', LedgerEntryType::Payout)->count());
        $this->assertSame(self::BALANCE - self::STAKE, $this->balance());

        // Le fournisseur revient : le ticket est réglé une seule fois, avec la cote acceptée.
        $this->fake->failWith(null);
        $this->assertTrue(app(ResultImportService::class)->import('soccer_epl')->succeeded());
        $this->assertSame(self::BALANCE - self::STAKE + 210_00, $this->balance());
    }

    public function test_c3_a_new_odds_from_the_provider_refuses_the_bet_until_the_player_confirms(): void
    {
        $shown = (string) $this->selection()->odds; // 2.10 affichée au joueur
        $this->travel(5)->minutes();
        $this->fake->setOdds('fake-1', 'h2h', ['Arsenal' => '1.95', 'Chelsea' => '3.60', 'Draw' => '3.30']);
        $this->sync();

        $this->bet(odds: $shown)->assertStatus(409)
            ->assertJsonPath('error.code', 'odds_changed')
            ->assertJsonPath('error.details.selections.0.odds', '1.95');
        $this->assertNothingAccepted();

        $this->bet(odds: '1.95')->assertCreated()->assertJsonPath('data.total_odds', '1.95');
    }

    // §11.4 — Le solde et les gains proviennent du ledger serveur, pas d'une valeur envoyée par le frontend.

    public function test_c4_values_sent_by_the_frontend_never_set_the_odds_the_payout_or_the_balance(): void
    {
        $this->bet(extra: [
            'total_odds' => '500.00',
            'potential_payout' => ['minor' => 99_999_999, 'currency' => 'HTG'],
            'payout' => ['minor' => 99_999_999, 'currency' => 'HTG'],
            'balance' => ['minor' => 99_999_999, 'currency' => 'HTG'],
        ])->assertCreated()
            ->assertJsonPath('data.total_odds', '2.10')
            ->assertJsonPath('data.potential_payout.minor', 210_00);

        $this->assertSame(self::BALANCE - self::STAKE, $this->balance());
    }

    public function test_c4_the_payout_and_the_balance_come_from_the_ledger(): void
    {
        $id = $this->bet()->assertCreated()->json('data.id');
        $this->finishMatch('2', '1');

        $payout = LedgerEntry::where('type', LedgerEntryType::Payout)->sole();
        $this->assertSame(210_00, $payout->amount_minor);

        $this->actingAs($this->player)->getJson("/api/v1/bets/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'won')
            ->assertJsonPath('data.payout.minor', 210_00);
        $this->assertSame(
            (int) LedgerEntry::where('wallet_id', $payout->wallet_id)->sum('amount_minor'),
            $this->balance(),
        );
        $this->assertSame(self::BALANCE - self::STAKE + 210_00, $this->balance());
    }

    // §11.5 — Un même résultat traité plusieurs fois ne crée aucun double crédit.

    public function test_c5_a_result_processed_several_times_pays_once(): void
    {
        $this->bet()->assertCreated();
        $this->finishMatch('2', '1');

        $this->assertTrue(app(ResultImportService::class)->import('soccer_epl')->succeeded());
        Artisan::call('settlement:run');
        Artisan::call('settlement:run');

        $this->assertSame(1, Result::count());
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntryType::Payout)->count());
        $this->assertSame(self::BALANCE - self::STAKE + 210_00, $this->balance());
    }

    public function test_c5_a_corrected_result_after_payment_never_pays_again_and_is_traced(): void
    {
        $this->bet()->assertCreated();
        $this->finishMatch('2', '1');

        // Le fournisseur corrige le score (Chelsea gagne) après le paiement : demande de correction en attente
        // (P5-06), rien ne change tant qu'un administrateur ne l'a pas validée.
        $this->fake->setResult('fake-1', '1', '2');
        $this->assertTrue(app(ResultImportService::class)->import('soccer_epl')->succeeded());
        Artisan::call('settlement:run');

        $this->assertSame(1, Result::count());
        $correction = ResultCorrection::sole();
        $this->assertSame(ResultCorrectionStatus::Pending, $correction->status);
        $this->assertNull($correction->requested_by);
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntryType::Payout)->count());
        $this->assertSame(self::BALANCE - self::STAKE + 210_00, $this->balance());

        // Validée : nouvelle version tracée, gain repris par écriture compensatoire, une seule fois.
        $approver = User::factory()->create();
        app(ResultCorrectionService::class)->approve($approver, $correction, 'Score officiel confirmé par la ligue');
        try {
            app(ResultCorrectionService::class)->approve($approver, $correction, 'Rejeu');
            $this->fail('Une correction a pu être validée deux fois.');
        } catch (ApiException $e) {
            $this->assertSame('correction_not_pending', $e->errorCode);
        }
        Artisan::call('settlement:run');

        $results = Result::orderBy('version')->get();
        $this->assertSame([1, 2], $results->pluck('version')->all());
        $this->assertSame($results[0]->id, $results[1]->supersedes_id);
        $this->assertNotNull($results[1]->correction_reason);
        $this->assertSame(ResultStatus::Final, $results[1]->status);

        $this->assertSame('lost', BetTicket::sole()->status->value);
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntryType::Payout)->count());
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntryType::Reversal)->count());
        $this->assertSame(self::BALANCE - self::STAKE, $this->balance());
    }

    // §11.6 — Permissions au niveau API, y compris l'accès direct par identifiant.

    public function test_c6_a_ticket_is_only_reachable_by_its_owner(): void
    {
        $id = $this->bet()->assertCreated()->json('data.id');
        $other = User::factory()->create();

        $this->app['auth']->forgetGuards(); // invité
        $this->getJson("/api/v1/bets/{$id}")->assertUnauthorized();
        $this->actingAs($other)->getJson("/api/v1/bets/{$id}")->assertNotFound();
        $this->actingAs($other)->getJson('/api/v1/bets')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other)->getJson('/api/v1/bets/'.($id + 1))->assertNotFound();

        $this->actingAs($this->player)->getJson("/api/v1/bets/{$id}")->assertOk();
    }

    public function test_c6_a_ticket_cannot_be_changed_through_the_api(): void
    {
        $id = $this->bet()->assertCreated()->json('data.id');

        foreach (['putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->actingAs($this->player)->{$method}("/api/v1/bets/{$id}", ['status' => 'won'])->assertStatus(405);
        }
        $this->assertSame('pending', BetTicket::sole()->status->value);
    }

    // §11.7 — Les changements de cotes et de résultats sont traçables.

    public function test_c7_every_odds_change_is_versioned_and_the_ticket_keeps_the_accepted_one(): void
    {
        $id = $this->bet()->assertCreated()->json('data.id');
        $version = $this->selection()->market->odds_version;

        $this->travel(5)->minutes();
        $this->fake->setOdds('fake-1', 'h2h', ['Arsenal' => '1.95', 'Chelsea' => '3.60', 'Draw' => '3.30']);
        $this->sync();

        $this->assertSame($version + 1, $this->selection()->market->odds_version);
        $this->assertEqualsCanonicalizing(
            ['2.100', '1.950'],
            OddsSnapshot::where('selection_id', $this->selection()->id)->pluck('odds')->map(fn ($o) => (string) $o)->all(),
        );

        $this->actingAs($this->player)->getJson("/api/v1/bets/{$id}")
            ->assertJsonPath('data.total_odds', '2.10')
            ->assertJsonPath('data.selections.0.odds', '2.10');
        $this->assertSame($version, BetTicket::sole()->selections()->sole()->odds_version);
    }

    public function test_c7_the_stake_and_the_payout_are_ledger_entries_tied_to_the_ticket(): void
    {
        $this->bet()->assertCreated();
        $this->finishMatch('2', '1');
        $ticket = BetTicket::sole();

        $entries = LedgerEntry::whereIn('type', [LedgerEntryType::Stake, LedgerEntryType::Payout])->orderBy('id')->get();
        $this->assertSame([LedgerEntryType::Stake, LedgerEntryType::Payout], $entries->pluck('type')->all());
        $this->assertSame([-self::STAKE, 210_00], $entries->pluck('amount_minor')->all());
        $this->assertSame("bet:{$ticket->id}:stake", $entries[0]->reference);
        $this->assertTrue($entries->every(fn (LedgerEntry $e) => $e->related_id === (string) $ticket->id));
    }
}
