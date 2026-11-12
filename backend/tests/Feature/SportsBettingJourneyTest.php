<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Selection;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Parcours complet d'un joueur, uniquement par l'API publique et les commandes planifiées :
 * dépôt chez le prestataire (simulé) → solde → pari simple et pari multiple → débit des mises →
 * résultats importés → règlement → gains crédités, chacun une seule fois.
 */
class SportsBettingJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const SYNCED_AT = '2026-10-20 10:00:00';

    private FakeSportsDataProvider $fake;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.providers.fake.enabled' => true,
            'payments.providers.fake.secret' => 'secret-de-test',
            'sports_data.max_odds_age_minutes' => 300,
            'sports_data.results_delay_minutes' => 105,
        ]);
        $this->fake = new FakeSportsDataProvider;
        $this->app->instance(SportsDataProvider::class, $this->fake);

        // fake-1 Arsenal–Chelsea le 21 à 15 h, fake-2 Liverpool–Everton le 22 à 17 h 30.
        $this->travelTo(self::SYNCED_AT);
        app(CatalogueSyncService::class)->sync('soccer_epl');

        $this->player = User::factory()->create();
    }

    private function selection(string $event, string $key): Selection
    {
        return Event::where('external_id', $event)->firstOrFail()
            ->markets()->where('key', 'h2h')->firstOrFail()
            ->selections()->where('key', $key)->firstOrFail();
    }

    /** @param  list<Selection>  $selections */
    private function bet(array $selections, int $stakeMinor): TestResponse
    {
        return $this->actingAs($this->player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => $stakeMinor, 'currency' => 'HTG'],
            'selections' => array_map(fn (Selection $s) => ['selection_id' => $s->id, 'odds' => (string) $s->odds], $selections),
        ], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function balance(): int
    {
        return $this->actingAs($this->player)->getJson('/api/v1/wallet')->assertOk()->json('data.balance.minor');
    }

    public function test_a_player_deposits_bets_and_is_paid_once(): void
    {
        // 1. Dépôt : ouverture, confirmation chez le prestataire, crédit par le webhook signé.
        $deposit = $this->actingAs($this->player)->postJson('/api/v1/payments/deposits',
            ['provider' => 'fake', 'amount_minor' => 1_500_00], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame(0, $this->balance());
        $this->post($deposit->json('data.redirect_url'), ['decision' => 'approve'])->assertStatus(303);
        $this->assertSame(1_500_00, $this->balance());

        // 2. Paris : un simple gagnant, un multiple gagnant, un simple perdant.
        $single = $this->bet([$this->selection('fake-1', 'home')], 100_00)->assertCreated()
            ->assertJsonPath('data.type', 'single')
            ->assertJsonPath('data.potential_payout.minor', 210_00);
        $multiple = $this->bet([$this->selection('fake-1', 'home'), $this->selection('fake-2', 'home')], 200_00)->assertCreated()
            ->assertJsonPath('data.type', 'multiple')
            ->assertJsonPath('data.total_odds', '3.255')
            ->assertJsonPath('data.potential_payout.minor', 651_00);
        $losing = $this->bet([$this->selection('fake-2', 'away')], 50_00)->assertCreated();
        $this->assertSame(1_150_00, $this->balance());

        // Mise supérieure au solde : refusée, rien n'est débité.
        $this->bet([$this->selection('fake-1', 'home')], 2_000_00)
            ->assertUnprocessable()->assertJsonPath('error.code', 'insufficient_funds');
        $this->assertSame(1_150_00, $this->balance());

        // 3. Matchs joués : résultats importés (commande planifiée), règlement, gains crédités.
        $this->travel(3)->days();
        $this->fake->setResult('fake-1', '2', '1')->setResult('fake-2', '3', '0');
        $this->artisan('settlement:import-results')->assertSuccessful();

        $this->actingAs($this->player)->getJson('/api/v1/bets/'.$single->json('data.id'))
            ->assertJsonPath('data.status', 'won')->assertJsonPath('data.payout.minor', 210_00);
        $this->actingAs($this->player)->getJson('/api/v1/bets/'.$multiple->json('data.id'))
            ->assertJsonPath('data.status', 'won')->assertJsonPath('data.payout.minor', 651_00);
        $this->actingAs($this->player)->getJson('/api/v1/bets/'.$losing->json('data.id'))
            ->assertJsonPath('data.status', 'lost');
        $this->assertSame(1_150_00 + 210_00 + 651_00, $this->balance());

        // Rejouer l'import et le règlement ne paie pas deux fois.
        $this->artisan('settlement:import-results')->assertSuccessful();
        $this->artisan('settlement:run')->assertSuccessful();
        $this->assertSame(2_011_00, $this->balance());

        // Historique du joueur : dépôt, trois mises, deux gains (du plus récent au plus ancien).
        $this->actingAs($this->player)->getJson('/api/v1/wallet/transactions')->assertOk()
            ->assertJsonPath('data.*.type', ['payout', 'payout', 'stake', 'stake', 'stake', 'deposit']);
    }
}
