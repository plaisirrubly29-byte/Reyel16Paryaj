<?php

namespace Tests\Feature\Modules\Lottery;

use App\Modules\Lottery\Contracts\LotteryProvider;
use App\Modules\Lottery\Providers\FakeLotteryProvider;
use App\Modules\Lottery\Services\LotterySyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LotteryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lottery.enabled' => true]);
        // 4 novembre 2026, 14:00 à New York : Floride midi (13:30) est passé, pas encore New York midi (14:30).
        $this->travelTo(CarbonImmutable::parse('2026-11-04 19:00:00', 'UTC'));

        $fake = (new FakeLotteryProvider)->withoutResults()
            ->setResult('fl_midday', '2026-11-04', '07', '45', '12', '407', '4512')
            ->setResult('fl_midday', '2026-11-03', '01', '02', '03')
            ->setResult('ny_evening', '2026-11-03', '90', '80', '70');
        $this->app->instance(LotteryProvider::class, $fake);
        app(LotterySyncService::class)->sync(1);
    }

    public function test_routes_are_hidden_while_the_lottery_is_disabled(): void
    {
        config(['lottery.enabled' => false]);

        $this->getJson('/api/v1/lottery/draws')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->getJson('/api/v1/lottery/results')->assertNotFound();
    }

    public function test_draws_are_listed_by_time_with_next_draw_and_latest_result(): void
    {
        $response = $this->getJson('/api/v1/lottery/draws')->assertOk()->assertJsonCount(4, 'data');

        $this->assertSame(['fl_midday', 'ny_midday', 'fl_evening', 'ny_evening'], array_column($response->json('data'), 'key'));

        $flMidday = $response->json('data.0');
        $this->assertSame('Florid Midi', $flMidday['name']);
        $this->assertSame('13:30', $flMidday['draw_time']);
        $this->assertSame('2026-11-05T18:30:00Z', $flMidday['next_draw_at']);
        $this->assertSame('2026-11-04', $flMidday['latest_result']['date']);
        $this->assertSame(['07', '45', '12'], $flMidday['latest_result']['lots']);
        $this->assertSame('407', $flMidday['latest_result']['pick3']);
        $this->assertSame('2026-11-04T18:30:00Z', $flMidday['latest_result']['drawn_at']);

        $nyMidday = $response->json('data.1');
        $this->assertSame('2026-11-04T19:30:00Z', $nyMidday['next_draw_at']);
        $this->assertNull($nyMidday['latest_result']);
    }

    public function test_results_are_listed_newest_first_and_filterable(): void
    {
        $response = $this->getJson('/api/v1/lottery/results')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(
            [['fl_midday', '2026-11-04'], ['ny_evening', '2026-11-03'], ['fl_midday', '2026-11-03']],
            array_map(fn ($r) => [$r['draw']['key'], $r['date']], $response->json('data')),
        );
        $this->assertSame(3, $response->json('meta.total'));

        $this->getJson('/api/v1/lottery/results?draw=ny_evening')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lots', ['90', '80', '70'])
            ->assertJsonPath('data.0.pick3', null);

        $this->getJson('/api/v1/lottery/results?date=2026-11-03')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_invalid_filters_are_refused(): void
    {
        $this->getJson('/api/v1/lottery/results?date=04/11/2026')->assertUnprocessable();
        $this->getJson('/api/v1/lottery/results?per_page=500')->assertUnprocessable();
    }
}
