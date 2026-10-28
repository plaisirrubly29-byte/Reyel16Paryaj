<?php

namespace Tests\Feature\Modules\Betting;

use App\Models\IdempotencyKey;
use App\Models\User;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Selection;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PlaceBetTest extends TestCase
{
    use RefreshDatabase;

    private FakeSportsDataProvider $fake;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sports_data.max_odds_age_minutes' => 300, 'wallet.simulated' => true]);
        $this->fake = new FakeSportsDataProvider;
        $this->app->instance(SportsDataProvider::class, $this->fake);
        app(CatalogueSyncService::class)->sync('soccer_epl'); // fake-1 Arsenal–Chelsea, fake-2 Liverpool–Everton

        $this->player = User::factory()->create();
        $this->credit($this->player, 1000_00);
    }

    private function credit(User $user, int $minor): void
    {
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($user), Money::ofMinor($minor, 'HTG'), 'test:'.Str::uuid());
    }

    private function balance(?User $user = null): int
    {
        $ledger = app(LedgerService::class);

        return $ledger->balance($ledger->walletFor($user ?? $this->player))->minor;
    }

    /** Sélection du catalogue : match (fake-1…), marché et clé (home, draw, away, over…). */
    private function selection(string $event = 'fake-1', string $market = 'h2h', string $key = 'home'): Selection
    {
        return Event::where('external_id', $event)->firstOrFail()
            ->markets()->where('key', $market)->firstOrFail()
            ->selections()->where('key', $key)->firstOrFail();
    }

    /** @param  list<array{0: Selection, 1?: string}>  $picks  [sélection, cote vue (par défaut celle du catalogue)] */
    private function bet(array $picks, int $stake = 100_00, ?string $key = null, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => $stake, 'currency' => 'HTG'],
            'selections' => array_map(fn ($p) => ['selection_id' => $p[0]->id, 'odds' => $p[1] ?? (string) $p[0]->odds], $picks),
        ], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_a_single_bet_is_accepted_and_the_stake_debited(): void
    {
        $response = $this->bet([[$this->selection()]])->assertCreated();

        $response->assertJsonPath('data.type', 'single')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.stake', ['minor' => 100_00, 'currency' => 'HTG'])
            ->assertJsonPath('data.total_odds', '2.10')
            ->assertJsonPath('data.potential_payout', ['minor' => 210_00, 'currency' => 'HTG'])
            ->assertJsonPath('data.selections.0.event.name', 'Arsenal – Chelsea')
            ->assertJsonPath('data.selections.0.selection.key', 'home')
            ->assertJsonPath('data.selections.0.odds', '2.10');
        $this->assertMatchesRegularExpression('/^R16-[2-9A-Z]{10}$/', $response->json('data.reference'));

        $this->assertSame(900_00, $this->balance());
        $ticket = BetTicket::sole();
        $stake = LedgerEntry::where('type', LedgerEntryType::Stake)->sole();
        $this->assertSame("bet:{$ticket->id}:stake", $stake->reference);
        $this->assertSame(-100_00, $stake->amount_minor);
        $this->assertSame('provisoire-2026-10', $ticket->rules_version);
        $this->assertSame('reject', $ticket->rules_snapshot['odds_change_policy']);
        $this->assertSame(1, $ticket->selections()->value('odds_version'));
    }

    public function test_a_multiple_uses_the_product_of_the_server_odds(): void
    {
        // 2.10 × 1.55 = 3.255 → gain 100,00 × 3.255 = 325,50
        $this->bet([[$this->selection()], [$this->selection('fake-2')]])
            ->assertCreated()
            ->assertJsonPath('data.type', 'multiple')
            ->assertJsonPath('data.total_odds', '3.255')
            ->assertJsonPath('data.potential_payout.minor', 325_50);
    }

    public function test_the_potential_payout_is_capped(): void
    {
        config(['betting.max_payout_minor' => 150_00]);

        $this->bet([[$this->selection()]])->assertCreated()->assertJsonPath('data.potential_payout.minor', 150_00);
    }

    public function test_a_changed_odds_is_refused_with_the_new_one_and_nothing_is_created(): void
    {
        $seen = (string) $this->selection()->odds;
        $this->fake->setOdds('fake-1', 'h2h', ['Arsenal' => '1.95', 'Chelsea' => '3.60', 'Draw' => '3.30']);
        app(CatalogueSyncService::class)->sync('soccer_epl');

        $this->bet([[$this->selection(), $seen]])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'odds_changed')
            ->assertJsonPath('error.details.selections.0.selection_id', (string) $this->selection()->id)
            ->assertJsonPath('error.details.selections.0.odds', '1.95')
            ->assertJsonPath('error.details.selections.0.odds_version', 2);

        $this->assertSame(0, BetTicket::count());
        $this->assertSame(1000_00, $this->balance());

        // Le joueur confirme avec la nouvelle cote : accepté, à 1.95.
        $this->bet([[$this->selection(), '1.95']])->assertCreated()->assertJsonPath('data.total_odds', '1.95');
    }

    public function test_the_client_odds_is_never_the_authority(): void
    {
        $this->bet([[$this->selection(), '50.00']])->assertStatus(409);
        $this->assertSame(0, BetTicket::count());
    }

    public function test_a_closed_started_or_stale_selection_is_unavailable(): void
    {
        $selection = $this->selection();
        $selection->market->update(['status' => MarketStatus::Suspended]);
        $this->bet([[$selection]])->assertUnprocessable()
            ->assertJsonPath('error.code', 'selection_unavailable')
            ->assertJsonPath('error.details.selection_ids', [(string) $selection->id]);

        $started = $this->selection('fake-2');
        Event::where('external_id', 'fake-2')->update(['starts_at' => now()->subMinute()]);
        $this->bet([[$started]])->assertUnprocessable()->assertJsonPath('error.code', 'selection_unavailable');

        Event::where('external_id', 'fake-2')->update(['starts_at' => now()->addDay(), 'status' => EventStatus::Finished]);
        $this->bet([[$started]])->assertUnprocessable()->assertJsonPath('error.code', 'selection_unavailable');

        $this->assertSame(0, BetTicket::count());
    }

    public function test_stale_odds_cannot_be_bet(): void
    {
        $selection = $this->selection();
        $this->travel(301)->minutes();

        $this->bet([[$selection]])->assertUnprocessable()->assertJsonPath('error.code', 'selection_unavailable');
    }

    public function test_an_unknown_selection_is_unavailable(): void
    {
        $this->actingAs($this->player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => 100_00],
            'selections' => [['selection_id' => 999999, 'odds' => '2.00']],
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.selection_ids', ['999999']);
    }

    public function test_two_selections_of_the_same_match_are_refused(): void
    {
        $this->bet([[$this->selection()], [$this->selection('fake-1', 'totals', 'over')]])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'same_event_selections');
    }

    public function test_stake_limits_are_enforced(): void
    {
        $this->bet([[$this->selection()]], stake: 9_99)->assertUnprocessable()
            ->assertJsonPath('error.code', 'stake_out_of_range')
            ->assertJsonPath('error.details.min.minor', 10_00);

        config(['betting.max_stake_minor' => 500_00]);
        $this->bet([[$this->selection()]], stake: 500_01)->assertUnprocessable()->assertJsonPath('error.code', 'stake_out_of_range');
    }

    public function test_multiples_can_be_disabled(): void
    {
        config(['betting.allow_multiple' => false]);

        $this->bet([[$this->selection()], [$this->selection('fake-2')]])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'multiple_not_allowed');
    }

    public function test_insufficient_funds_create_nothing(): void
    {
        $this->bet([[$this->selection()]], stake: 1000_01)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'insufficient_funds');

        $this->assertSame(0, BetTicket::count());
        $this->assertSame(1000_00, $this->balance());
    }

    public function test_a_replayed_request_never_bets_twice(): void
    {
        $key = (string) Str::uuid();
        $first = $this->bet([[$this->selection()]], key: $key)->assertCreated();
        $replay = $this->bet([[$this->selection()]], key: $key)->assertCreated();

        $this->assertSame($first->json('data.reference'), $replay->json('data.reference'));
        $this->assertSame(1, BetTicket::count());
        $this->assertSame(900_00, $this->balance());
    }

    public function test_the_idempotency_key_is_required(): void
    {
        $this->actingAs($this->player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => 100_00],
            'selections' => [['selection_id' => $this->selection()->id, 'odds' => '2.10']],
        ])->assertStatus(428);
    }

    /**
     * Revue P8-02, M4 : le middleware libère sa clé si la réponse n'a pas pu être enregistrée (erreur après le
     * commit, processus tué). Simulé en vidant idempotency_keys : le rejeu ne doit ni créer ni débiter.
     */
    public function test_a_replay_after_a_crash_returns_the_same_ticket_without_a_second_stake(): void
    {
        $key = (string) Str::uuid();
        $first = $this->bet([[$this->selection()]], key: $key)->assertCreated();
        IdempotencyKey::query()->delete();

        // Même la cote a bougé entre-temps : le pari déjà accepté est renvoyé tel quel, sans revalidation.
        $this->fake->setOdds('fake-1', 'h2h', ['Arsenal' => '1.95', 'Chelsea' => '3.60', 'Draw' => '3.30']);
        app(CatalogueSyncService::class)->sync('soccer_epl');

        $replay = $this->bet([[$this->selection(), '2.10']], key: $key)->assertCreated();

        $this->assertSame($first->json('data.reference'), $replay->json('data.reference'));
        $this->assertSame('2.10', $replay->json('data.total_odds'));
        $this->assertSame(1, BetTicket::count());
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntryType::Stake)->count());
        $this->assertSame(900_00, $this->balance());
    }

    public function test_a_key_already_used_for_another_bet_is_refused_after_a_crash(): void
    {
        $key = (string) Str::uuid();
        $this->bet([[$this->selection()]], key: $key)->assertCreated();
        IdempotencyKey::query()->delete();

        $this->bet([[$this->selection()]], stake: 200_00, key: $key)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_key_reused');
        $this->assertSame(900_00, $this->balance());
    }

    public function test_the_same_key_is_unique_per_player_in_the_database(): void
    {
        $key = (string) Str::uuid();
        $this->bet([[$this->selection()]], key: $key)->assertCreated();

        // Un autre joueur peut utiliser la même clé (clés propres à chaque joueur).
        $other = User::factory()->create();
        $this->credit($other, 100_00);
        $this->bet([[$this->selection()]], key: $key, as: $other)->assertCreated();

        $this->expectException(UniqueConstraintViolationException::class);
        BetTicket::create([
            ...BetTicket::where('user_id', $this->player->id)->firstOrFail()->only(['user_id', 'wallet_id', 'type', 'stake_minor', 'currency', 'total_odds', 'potential_payout_minor', 'rules_version', 'rules_snapshot']),
            'placement_key' => $key,
        ]);
    }

    public function test_invalid_payloads_are_rejected(): void
    {
        $this->actingAs($this->player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => 0],
            'selections' => [],
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_only_an_active_signed_in_player_can_bet(): void
    {
        $this->postJson('/api/v1/bets', [], ['Idempotency-Key' => (string) Str::uuid()])->assertUnauthorized();

        $suspended = User::factory()->withStatus(AccountStatus::Suspended)->create();
        $this->bet([[$this->selection()]], as: $suspended)->assertStatus(423);
    }

    public function test_the_ticket_is_then_listed_in_my_bets(): void
    {
        $reference = $this->bet([[$this->selection()]])->json('data.reference');

        $this->actingAs($this->player)->getJson('/api/v1/bets')->assertOk()->assertJsonPath('data.0.reference', $reference);
    }
}
