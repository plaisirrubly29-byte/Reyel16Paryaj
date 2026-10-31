<?php

namespace Tests\Feature\Modules\SportsData;

use App\Models\User;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Matchs de scénario du faux fournisseur (P8-01) : le parcours des tests de bout en bout, côté serveur.
 * Création → synchronisation → pari → fin du match → import du résultat → règlement, par les commandes réelles.
 */
class FakeScenarioMatchTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{id: string, home: string, away: string, starts_at: string} */
    private function createMatch(): array
    {
        $this->assertSame(0, Artisan::call('sports-data:fake-match', ['action' => 'create']));

        return json_decode(trim(Artisan::output()), true);
    }

    public function test_a_scenario_match_goes_through_sync_bet_result_and_settlement(): void
    {
        $match = $this->createMatch();
        $this->assertMatchesRegularExpression('/^e2e-[a-z0-9]{6}$/', $match['id']);

        $this->artisan('sports-data:sync', ['--competition' => ['soccer_epl']])->assertSuccessful();
        $event = Event::where('external_id', $match['id'])->firstOrFail();
        $this->assertSame($match['home'], $event->homeTeam->name);
        $selection = $event->markets()->where('key', 'h2h')->firstOrFail()->selections()->where('key', 'home')->firstOrFail();
        $this->assertSame('2.000', (string) $selection->odds);

        $player = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($player), Money::ofMinor(500_00, 'HTG'), 'test:e2e');
        $ticketId = $this->actingAs($player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => 100_00, 'currency' => 'HTG'],
            'selections' => [['selection_id' => $selection->id, 'odds' => (string) $selection->odds]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');

        $this->artisan('sports-data:fake-match', ['action' => 'finish', 'id' => $match['id'], 'score' => '2-1'])->assertSuccessful();
        $this->artisan('settlement:import-results', ['--competition' => ['soccer_epl']])->assertSuccessful();
        $this->artisan('settlement:run')->assertSuccessful();

        $this->assertSame(EventStatus::Finished, $event->fresh()->status);
        $ticket = BetTicket::findOrFail($ticketId);
        $this->assertSame(BetTicketStatus::Won, $ticket->status);
        $this->assertSame(200_00, $ticket->payout_minor);
        $this->assertSame(500_00 - 100_00 + 200_00, $ledger->balance($ledger->walletFor($player))->minor);
    }

    public function test_each_scenario_match_is_unique_and_the_fixed_catalogue_is_unchanged(): void
    {
        $first = $this->createMatch();
        $second = $this->createMatch();

        $this->assertNotSame($first['id'], $second['id']);
        $ids = array_map(fn ($e) => $e->externalId, app(SportsDataProvider::class)->events('soccer_epl'));
        $this->assertSame(['fake-1', 'fake-2', $first['id'], $second['id']], $ids);
        // Sans dépôt (tests existants) : le catalogue fixe seulement.
        $this->assertSame(['fake-1', 'fake-2'], array_map(fn ($e) => $e->externalId, (new FakeSportsDataProvider)->events('soccer_epl')));
    }

    public function test_finish_rejects_an_unknown_match_or_an_invalid_score(): void
    {
        $match = $this->createMatch();

        $this->artisan('sports-data:fake-match', ['action' => 'finish', 'id' => 'e2e-inconnu', 'score' => '1-0'])->assertExitCode(2);
        $this->artisan('sports-data:fake-match', ['action' => 'finish', 'id' => $match['id'], 'score' => 'deux-un'])->assertExitCode(2);
        $this->artisan('sports-data:fake-match', ['action' => 'publish'])->assertExitCode(2);
    }

    public function test_refused_in_production_and_with_a_real_provider(): void
    {
        $this->app->instance(SportsDataProvider::class, $this->createMock(SportsDataProvider::class));
        $this->artisan('sports-data:fake-match', ['action' => 'create'])->assertFailed();

        $this->app->instance(SportsDataProvider::class, new FakeSportsDataProvider(app('cache.store')));
        $this->app['env'] = 'production';
        $this->artisan('sports-data:fake-match', ['action' => 'create'])->assertFailed();
    }
}
