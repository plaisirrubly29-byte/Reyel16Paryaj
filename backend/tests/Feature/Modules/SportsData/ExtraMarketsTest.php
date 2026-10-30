<?php

namespace Tests\Feature\Modules\SportsData;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtraMarketsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sports_data.max_odds_age_minutes' => 300, 'sports_data.theodds.regions' => 'eu']);
        $this->travelTo('2026-10-20 10:00:00'); // fake-1 le 21 à 15 h (dans 29 h), fake-2 le 22 à 17 h 30 (dans 55 h 30)
        $this->app->instance(SportsDataProvider::class, new FakeSportsDataProvider);
        app(CatalogueSyncService::class)->sync('soccer_epl');     // fake-1 (demain), fake-2 (après-demain)
        app(CatalogueSyncService::class)->sync('basketball_nba'); // fake-3 : pas de football, jamais interrogé
    }

    private function event(string $externalId): Event
    {
        return Event::where('external_id', $externalId)->firstOrFail();
    }

    public function test_dry_run_announces_the_cost_without_loading_anything(): void
    {
        $this->artisan('sports-data:extra-markets', ['--dry-run' => true])
            ->expectsOutputToContain('2 match(s), 3 crédit(s) par match, coût estimé : 6 crédit(s)')
            ->assertSuccessful();

        $this->assertSame(0, Market::where('key', 'correct_score')->count());
    }

    public function test_correct_score_is_loaded_match_by_match_and_shown_in_the_catalogue(): void
    {
        $this->artisan('sports-data:extra-markets', ['--hours' => 30])->assertSuccessful(); // fake-1 seulement

        $this->assertSame(1, Market::where('key', 'correct_score')->count());
        $this->assertSame(0, $this->event('fake-2')->markets()->where('key', 'correct_score')->count());

        $market = collect($this->getJson("/api/v1/events/{$this->event('fake-1')->id}/markets")->assertOk()->json('data.markets'))
            ->firstWhere('key', 'correct_score');
        $this->assertSame('Score exact', $market['name']);
        $this->assertSame(
            [['1-0', '1 - 0', '7.50'], ['1-1', '1 - 1', '6.50'], ['2-1', '2 - 1', '9.00'], ['0-1', '0 - 1', '11.00']],
            array_map(fn ($s) => [$s['label'], $s['name'], $s['odds']], $market['selections']),
        );
        $this->assertTrue($market['bettable']);

        // La synchro par compétition suivante ne touche pas aux marchés qu'elle ne fournit pas.
        app(CatalogueSyncService::class)->sync('soccer_epl');
        $this->assertSame(4, $this->event('fake-1')->markets()->where('key', 'correct_score')->sole()->selections()->count());
    }
}
