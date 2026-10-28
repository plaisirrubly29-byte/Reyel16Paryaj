<?php

namespace Tests\Feature\Modules\SportsData;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Data\QuotaData;
use App\Modules\SportsData\Data\SportData;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\ProviderApiCall;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Providers\TheOddsApiProvider;
use App\Modules\SportsData\Services\BudgetPlan;
use App\Modules\SportsData\Services\CreditBudget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CreditBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** Faux fournisseur dont la liste des sports et le quota sont réglables. */
    private object $fake;

    protected function setUp(): void
    {
        parent::setUp();

        // 16/10/2026 à 10 h UTC : 93 synchros "0 */4 * * *" d'ici la remise à zéro du 1er novembre.
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));

        config([
            'sports_data.sports' => ['soccer_epl', 'basketball_nba', 'soccer_france_ligue_one'],
            'sports_data.sync_cron' => '0 */4 * * *',
            'sports_data.budget_enabled' => true,
            'sports_data.monthly_credits' => 500,
            'sports_data.quota_reset_day' => 1,
            'sports_data.min_remaining_requests' => 25,
            'sports_data.theodds.markets' => 'h2h',
            'sports_data.theodds.regions' => 'eu',
            'sports_data.horizon_days' => 7,
        ]);
        Cache::flush();

        $this->fake = new class extends FakeSportsDataProvider
        {
            /** @var list<SportData>|null */
            public ?array $sportsList = null;

            public bool $sportsFail = false;

            public ?QuotaData $quotaData = null;

            /** @var array<string, list<CarbonImmutable>|null> clé => heures de début (null : liste indisponible) */
            public array $starts = [];

            public function upcomingStarts(string $sportKey): array
            {
                if (array_key_exists($sportKey, $this->starts) && $this->starts[$sportKey] === null) {
                    throw new SportsDataException(SportsDataException::UNAVAILABLE, 'Panne simulée.');
                }

                return $this->starts[$sportKey] ?? [CarbonImmutable::now()->addDay()];
            }

            public function sports(): array
            {
                if ($this->sportsFail) {
                    throw new SportsDataException(SportsDataException::UNAVAILABLE, 'Panne simulée.');
                }

                return $this->sportsList ?? [
                    new SportData('soccer_epl', 'Soccer', 'EPL', true, false),
                    new SportData('basketball_nba', 'Basketball', 'NBA', true, false),
                    new SportData('soccer_france_ligue_one', 'Soccer', 'Ligue 1', true, false),
                ];
            }

            public function quota(): ?QuotaData
            {
                return $this->quotaData;
            }
        };
        $this->app->instance(SportsDataProvider::class, $this->fake);
    }

    private function budget(): CreditBudget
    {
        return app(CreditBudget::class);
    }

    /** Fixe le budget par synchro : (crédits mensuels − 25 de réserve) / 93 synchros. */
    private function perRunBudget(int $credits): void
    {
        config(['sports_data.monthly_credits' => 25 + 93 * $credits]);
    }

    private function logCall(int $cost, string $at, ?string $competition = 'soccer_epl'): void
    {
        ProviderApiCall::create([
            'provider' => 'fake',
            'endpoint' => $competition ? '/sports/{sport}/odds' : '/sports',
            'competition' => $competition,
            'cost' => $cost,
            'status' => 200,
            'called_at' => CarbonImmutable::parse($at, 'UTC'),
        ]);
    }

    public function test_remaining_credits_fall_back_to_the_journal_of_the_current_period(): void
    {
        $this->logCall(40, '2026-09-30 23:00'); // période précédente : ignoré
        $this->logCall(3, '2026-10-02 08:00');
        $this->logCall(2, '2026-10-15 08:00');

        $budget = $this->budget();

        $this->assertSame('journal', $budget->source());
        $this->assertSame(495, $budget->remaining());
        $this->assertSame(5, $budget->used());
        $this->assertSame(93, $budget->runsLeft());
        $this->assertSame(5, $budget->perRunBudget()); // (495 − 25) / 93
    }

    public function test_provider_quota_is_used_when_observed_during_the_period(): void
    {
        $this->fake->quotaData = new QuotaData(120, 380, 1, CarbonImmutable::parse('2026-10-15 20:00', 'UTC'));

        $this->assertSame('fournisseur', $this->budget()->source());
        $this->assertSame(120, $this->budget()->remaining());
        $this->assertSame(1, $this->budget()->perRunBudget()); // (120 − 25) / 93

        // Quota observé avant la remise à zéro : périmé, on repart du journal.
        $this->fake->quotaData = new QuotaData(3, 497, 1, CarbonImmutable::parse('2026-09-30 20:00', 'UTC'));

        $this->assertSame('journal', $this->budget()->source());
        $this->assertSame(500, $this->budget()->remaining());
    }

    public function test_budget_never_goes_negative_and_cost_follows_markets_and_regions(): void
    {
        $this->fake->quotaData = new QuotaData(10, 490, 1, CarbonImmutable::now());
        config(['sports_data.theodds.markets' => 'h2h,totals', 'sports_data.theodds.regions' => 'eu, uk']);

        $this->assertSame(0, $this->budget()->perRunBudget());
        $this->assertSame(4, $this->budget()->costPerCompetition());
    }

    public function test_plan_takes_the_least_recently_synced_competitions_within_budget(): void
    {
        $this->perRunBudget(2);
        $budget = $this->budget();

        foreach (['soccer_epl' => 3, 'basketball_nba' => 1, 'soccer_france_ligue_one' => 2] as $key => $hoursAgo) {
            Cache::forever("sports_data:fake:last_sync:{$key}", now()->subHours($hoursAgo)->toIso8601String());
        }

        $plan = $budget->plan(config('sports_data.sports'));

        $this->assertSame(['soccer_epl', 'soccer_france_ligue_one'], $plan->sync);
        $this->assertSame(['basketball_nba' => BudgetPlan::REASON_BUDGET], $plan->skipped);
        $this->assertSame(2, $plan->perRunBudget);
    }

    public function test_never_synced_competitions_come_first_then_configured_order(): void
    {
        $this->perRunBudget(2);
        $this->budget()->markSynced('soccer_epl');

        $plan = $this->budget()->plan(config('sports_data.sports'));

        $this->assertSame(['basketball_nba', 'soccer_france_ligue_one'], $plan->sync);
        $this->assertSame(['soccer_epl'], $plan->skippedForBudget());
    }

    public function test_last_sync_falls_back_to_events_in_database(): void
    {
        config(['sports_data.sports' => ['soccer_epl']]);
        $this->artisan('sports-data:sync')->assertSuccessful();
        Cache::flush();

        $this->assertSame(now()->toDateTimeString(), $this->budget()->lastSyncedAt('soccer_epl')?->toDateTimeString());
        $this->assertSame(1, Event::where('external_id', 'fake-1')->count());
    }

    public function test_zero_budget_skips_everything(): void
    {
        $this->perRunBudget(0);

        $plan = $this->budget()->plan(config('sports_data.sports'));

        $this->assertSame([], $plan->sync);
        $this->assertCount(3, $plan->skippedForBudget());
    }

    public function test_off_season_and_unknown_competitions_are_skipped_before_budget(): void
    {
        $this->perRunBudget(1);
        $this->fake->sportsList = [
            new SportData('soccer_epl', 'Soccer', 'EPL', false, false),
            new SportData('basketball_nba', 'Basketball', 'NBA', true, false),
        ];

        $plan = $this->budget()->plan(config('sports_data.sports'));

        $this->assertSame(['basketball_nba'], $plan->sync);
        $this->assertSame([
            'soccer_epl' => BudgetPlan::REASON_OFF_SEASON,
            'soccer_france_ligue_one' => BudgetPlan::REASON_UNKNOWN,
        ], $plan->skipped);
    }

    public function test_competitions_without_match_in_horizon_are_skipped_for_free(): void
    {
        $this->perRunBudget(3);
        $this->fake->starts = [
            'soccer_epl' => [],                                        // aucun match
            'basketball_nba' => [CarbonImmutable::now()->addDays(10)], // au-delà de l'horizon (7 j)
            'soccer_france_ligue_one' => null,                         // liste indisponible : on synchronise
        ];

        $plan = $this->budget()->plan(config('sports_data.sports'));

        $this->assertSame(['soccer_france_ligue_one'], $plan->sync);
        $this->assertSame([
            'soccer_epl' => BudgetPlan::REASON_NO_EVENTS,
            'basketball_nba' => BudgetPlan::REASON_NO_EVENTS,
        ], $plan->skipped);
    }

    public function test_sports_list_failure_does_not_skip_and_success_is_cached(): void
    {
        $this->fake->sportsFail = true;
        $this->assertSame(3, count($this->budget()->plan(config('sports_data.sports'))->sync));

        $this->fake->sportsFail = false;
        $this->fake->sportsList = [new SportData('soccer_epl', 'Soccer', 'EPL', true, false)];
        $this->assertSame(['soccer_epl'], $this->budget()->plan(config('sports_data.sports'))->sync);

        // Liste mise en cache 6 h : un changement côté fournisseur n'est vu qu'après expiration.
        $this->fake->sportsList = [];
        $this->assertSame(['soccer_epl'], $this->budget()->plan(config('sports_data.sports'))->sync);
        $this->travel(6)->hours();
        $this->travel(1)->minutes();
        $this->assertSame([], $this->budget()->plan(config('sports_data.sports'))->sync);
    }

    public function test_sync_command_skips_competitions_over_budget_and_logs_a_warning(): void
    {
        $this->perRunBudget(1);
        Log::spy();

        $this->artisan('sports-data:sync')
            ->expectsOutputToContain('Budget de cette synchro : 1 crédits')
            ->expectsOutputToContain('Compétition reportée')
            ->assertSuccessful();

        $this->assertSame(['fake-1', 'fake-2'], Event::orderBy('external_id')->pluck('external_id')->all());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'sports_data.budget_skip'
            && $context['skipped'] === ['basketball_nba', 'soccer_france_ligue_one'])->once();

        // Synchro suivante : la compétition la moins récemment synchronisée passe devant.
        $this->travel(4)->hours();
        $this->artisan('sports-data:sync')->assertSuccessful();
        $this->assertSame(1, Event::where('external_id', 'fake-3')->count());
    }

    public function test_sync_command_with_zero_budget_calls_nothing(): void
    {
        $this->perRunBudget(0);

        $this->artisan('sports-data:sync')
            ->expectsOutputToContain('Aucune compétition synchronisée')
            ->assertSuccessful();

        $this->assertSame(0, Event::count());
    }

    public function test_explicit_competition_still_respects_the_budget_unless_forced(): void
    {
        $this->perRunBudget(0);

        $this->artisan('sports-data:sync', ['--competition' => ['basketball_nba']])->assertSuccessful();
        $this->assertSame(0, Event::count());

        $this->artisan('sports-data:sync', ['--competition' => ['basketball_nba'], '--force' => true])
            ->doesntExpectOutputToContain('Budget de cette synchro')
            ->assertSuccessful();
        $this->assertSame(1, Event::count());
    }

    public function test_budget_can_be_disabled(): void
    {
        $this->perRunBudget(0);
        config(['sports_data.budget_enabled' => false]);

        $this->artisan('sports-data:sync')->assertSuccessful();

        $this->assertSame(3, Event::count()); // ligue 1 : aucun match chez le faux fournisseur
    }

    public function test_credits_command_reports_budget_and_usage_without_any_api_call(): void
    {
        config([
            'sports_data.provider' => 'theodds',
            'sports_data.theodds.key' => 'cle-de-test-secrete',
            'sports_data.sports' => ['soccer_epl', 'basketball_nba'],
            'sports_data.monthly_credits' => 500,
        ]);
        $this->app->forgetInstance(SportsDataProvider::class);
        Http::preventStrayRequests();
        Http::fake();

        ProviderApiCall::create(['provider' => 'theodds', 'endpoint' => '/sports/{sport}/odds', 'competition' => 'soccer_epl', 'cost' => 1, 'remaining' => 499, 'used' => 1, 'status' => 200, 'called_at' => now()->subHours(4)]);
        ProviderApiCall::create(['provider' => 'theodds', 'endpoint' => '/sports/{sport}/odds', 'competition' => 'soccer_epl', 'cost' => 1, 'remaining' => 498, 'used' => 2, 'status' => 200, 'called_at' => now()->subHours(2)]);
        ProviderApiCall::create(['provider' => 'theodds', 'endpoint' => '/sports', 'competition' => null, 'cost' => 0, 'remaining' => 498, 'used' => 2, 'status' => 200, 'called_at' => now()->subHour()]);
        Cache::forever(TheOddsApiProvider::QUOTA_CACHE_KEY, ['remaining' => 498, 'used' => 2, 'last_cost' => 0, 'observed_at' => now()->subHour()->toIso8601String()]);

        $this->artisan('sports-data:credits')
            ->expectsOutputToContain('Fournisseur : theodds')
            ->expectsOutputToContain('498 (source : fournisseur)')
            ->expectsOutputToContain('2026-11-01 (dans 16 j)')
            ->expectsOutputToContain('| suffisant')
            ->expectsOutputToContain('/sports/{sport}/odds')
            ->assertSuccessful();

        $this->artisan('sports-data:credits', ['--json' => true])->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_credits_json_projects_an_insufficient_budget(): void
    {
        $this->perRunBudget(2);

        $this->assertSame(0, Artisan::call('sports-data:credits', ['--json' => true]));
        $json = json_decode(Artisan::output(), true);

        $this->assertSame(2, $json['per_run_budget']);
        $this->assertSame(93, $json['runs_left']);
        $this->assertSame(3, $json['cost_per_run']);
        $this->assertFalse($json['sufficient']);
        $this->assertSame(2, $json['competitions_per_run']);
        $this->assertSame('2026-10-01T00:00:00+00:00', $json['period_start']);

        $this->artisan('sports-data:credits')
            ->expectsOutputToContain('insuffisant : 2 compétition(s) par synchro au lieu de 3')
            ->assertSuccessful();
    }
}
