<?php

namespace Tests\Feature\Modules\Catalogue;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cache court des réponses du catalogue public (P8-03, CATALOGUE_CACHE_SECONDS). */
class CatalogueCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SportsDataProvider::class, new FakeSportsDataProvider);
        app(CatalogueSyncService::class)->sync('soccer_epl'); // 2 matchs
    }

    private function suspendAll(): void
    {
        Market::query()->update(['status' => MarketStatus::Suspended]);
    }

    public function test_disabled_by_default_every_request_is_computed(): void
    {
        $this->getJson('/api/v1/events')->assertOk()->assertJsonCount(2, 'data')->assertHeaderMissing('X-Catalogue-Cache');

        $this->suspendAll();
        $this->getJson('/api/v1/events')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_response_is_served_from_the_cache_for_the_configured_duration(): void
    {
        config(['sports_data.catalogue_cache_seconds' => 10]);

        $first = $this->getJson('/api/v1/events')->assertOk()->assertHeader('X-Catalogue-Cache', 'miss')->assertJsonCount(2, 'data');
        $this->suspendAll();

        $second = $this->getJson('/api/v1/events')->assertOk()->assertHeader('X-Catalogue-Cache', 'hit');
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertStringStartsWith('application/json', $second->headers->get('Content-Type'));

        // Après la durée : recalculée (marchés suspendus, plus rien à afficher).
        $this->travel(11)->seconds();
        $this->getJson('/api/v1/events')->assertOk()->assertHeader('X-Catalogue-Cache', 'miss')->assertJsonCount(0, 'data');
    }

    public function test_each_url_has_its_own_entry_and_parameter_order_does_not_matter(): void
    {
        config(['sports_data.catalogue_cache_seconds' => 10]);

        $this->getJson('/api/v1/events?market=totals&per_page=5')->assertHeader('X-Catalogue-Cache', 'miss');
        $this->getJson('/api/v1/events?per_page=5&market=totals')->assertHeader('X-Catalogue-Cache', 'hit');
        $this->getJson('/api/v1/events?market=btts&per_page=5')->assertHeader('X-Catalogue-Cache', 'miss');
        $this->getJson('/api/v1/sports')->assertHeader('X-Catalogue-Cache', 'miss');
    }

    public function test_errors_are_never_cached(): void
    {
        config(['sports_data.catalogue_cache_seconds' => 10]);
        $id = Event::query()->value('id');

        $this->getJson('/api/v1/events/999999/markets')->assertNotFound();
        $this->getJson('/api/v1/events/999999/markets')->assertNotFound()->assertHeader('X-Catalogue-Cache', 'miss');
        $this->getJson('/api/v1/events?market=inconnu')->assertUnprocessable();
        $this->getJson("/api/v1/events/{$id}/markets")->assertOk()->assertHeader('X-Catalogue-Cache', 'miss');
    }
}
