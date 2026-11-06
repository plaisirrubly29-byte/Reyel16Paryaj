<?php

namespace Tests\Feature\Modules\Lottery;

use App\Modules\Lottery\Contracts\LotteryProvider;
use App\Modules\Lottery\Exceptions\LotteryException;
use App\Modules\Lottery\Models\LotteryDraw;
use App\Modules\Lottery\Models\LotteryResult;
use App\Modules\Lottery\Providers\UnitySoftConnectLotteryProvider;
use App\Modules\Lottery\Services\LotterySyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnitySoftConnectLotteryProviderTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'usc_live_cle_de_test';

    private const BASE = 'https://unityscgroup.com/api/v1/borlette';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'lottery.provider' => 'unitysoftconnect',
            'lottery.api.url' => self::BASE,
            'lottery.api.key' => self::KEY,
            'lottery.api.timeout' => 5,
        ]);
        $this->app->forgetInstance(LotteryProvider::class);
        Http::preventStrayRequests();

        // 4 novembre 2026, 20:00 à Port-au-Prince : le tirage de New York Midi (fermeture 14:20) est passé.
        $this->travelTo(CarbonImmutable::parse('2026-11-05 01:00:00', 'UTC'));
    }

    private function provider(): LotteryProvider
    {
        return app(LotteryProvider::class);
    }

    /** Réponse documentée de GET /draws. */
    private function drawsPayload(): array
    {
        return [
            'data' => [
                [
                    'id' => 1, 'code' => 'NYM', 'name' => 'New York Midi', 'open_time' => '06:00', 'close_time' => '14:20',
                    'days' => [0, 1, 2, 3, 4, 5, 6], 'is_active' => true,
                    'today' => ['status' => 'result_published', 'is_open' => false, 'date' => '2026-11-04', 'result' => null],
                ],
                [
                    'id' => 2, 'code' => 'FLS', 'name' => 'Florid Swa', 'open_time' => '15:00', 'close_time' => '21:30',
                    'days' => [1, 2, 3, 4, 5, 6], 'is_active' => true,
                ],
            ],
            'meta' => ['server_time' => '2026-11-04T20:00:00-05:00', 'timezone' => 'America/Port-au-Prince'],
        ];
    }

    /** Objet Rezilta documenté. */
    private function resultPayload(int $id = 1, string $code = 'NYM', string $date = '2026-11-04', array $numbers = ['125', '07', '41']): array
    {
        return [
            'id' => $id,
            'draw' => ['id' => 1, 'code' => $code, 'name' => 'New York Midi'],
            'date' => $date,
            'numbers' => $numbers,
            'games' => [
                'bolet' => ['lot1' => substr($numbers[0], -2), 'lot2' => $numbers[1], 'lot3' => $numbers[2]],
                'loto3' => $numbers[0],
            ],
            'published_at' => "{$date}T14:41:05-05:00",
            'updated_at' => null,
        ];
    }

    public function test_draws_are_mapped_with_bearer_key(): void
    {
        Http::fake([self::BASE.'/draws' => Http::response($this->drawsPayload())]);

        $draws = $this->provider()->draws();

        $this->assertCount(2, $draws);
        $this->assertSame('NYM', $draws[0]->key);
        $this->assertSame('14:20', $draws[0]->drawTime);
        $this->assertSame('America/Port-au-Prince', $draws[0]->timezone);
        $this->assertNull($draws[0]->days, 'Les 7 jours = tous les jours');
        $this->assertSame([1, 2, 3, 4, 5, 6], $draws[1]->days, 'Pas de tirage le dimanche (0 → 7 en ISO)');

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::KEY));
    }

    public function test_results_are_mapped_and_paginated(): void
    {
        Http::fake([
            self::BASE.'/results*' => Http::sequence()
                ->push(['data' => [$this->resultPayload(1)], 'meta' => ['page' => 1, 'per_page' => 1, 'total' => 2, 'last_page' => 2]])
                ->push(['data' => [$this->resultPayload(2, 'NYM', '2026-11-03', ['300', '00', '99'])], 'meta' => ['page' => 2, 'last_page' => 2]]),
        ]);

        $results = $this->provider()->results(CarbonImmutable::parse('2026-11-03'), CarbonImmutable::parse('2026-11-04'));

        $this->assertCount(2, $results);
        $this->assertSame(['25', '07', '41'], $results[0]->lots());
        $this->assertSame('125', $results[0]->pick3);
        $this->assertSame('0741', $results[0]->pick4);
        $this->assertSame('1', $results[0]->externalId);
        $this->assertSame(['00', '00', '99'], $results[1]->lots(), 'Les zéros devant sont gardés');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'from=2026-11-03') && str_contains($r->url(), 'page=2'));
    }

    public function test_inconsistent_result_is_skipped(): void
    {
        $bad = $this->resultPayload(1);
        $bad['games']['bolet']['lot1'] = '99';
        Http::fake([self::BASE.'/results*' => Http::response(['data' => [$bad, $this->resultPayload(2, 'NYM', '2026-11-03')], 'meta' => ['last_page' => 1]])]);

        $results = $this->provider()->results(CarbonImmutable::parse('2026-11-03'), CarbonImmutable::parse('2026-11-04'));

        $this->assertCount(1, $results);
        $this->assertSame('2', $results[0]->externalId);
    }

    public function test_full_sync_imports_draws_and_results(): void
    {
        Http::fake([
            self::BASE.'/draws' => Http::response($this->drawsPayload()),
            self::BASE.'/results*' => Http::response(['data' => [$this->resultPayload(1)], 'meta' => ['last_page' => 1]]),
        ]);

        $report = app(LotterySyncService::class)->sync(1);

        $this->assertSame(2, $report->draws);
        $this->assertSame(1, $report->created);
        $this->assertSame('unitysoftconnect', LotteryDraw::where('external_key', 'NYM')->value('source'));
        $result = LotteryResult::firstOrFail();
        $this->assertSame(['25', '07', '41'], $result->lots());
        $this->assertSame('2026-11-04', $result->draw_date);
    }

    public function test_missing_key_is_refused_without_calling_the_api(): void
    {
        config(['lottery.api.key' => null]);
        $this->app->forgetInstance(LotteryProvider::class);
        Http::fake();

        try {
            $this->provider()->draws();
            $this->fail('Exception attendue');
        } catch (LotteryException $e) {
            $this->assertSame(LotteryException::UNAUTHORIZED, $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_http_errors_are_translated_and_key_is_never_leaked(): void
    {
        Http::fake([
            self::BASE.'/draws' => Http::sequence()
                ->push(['error' => ['code' => 'unauthorized', 'message' => 'Kle '.self::KEY.' pa valab.']], 401)
                ->push(['error' => ['code' => 'quota_exceeded', 'message' => 'Kota depase.']], 429),
        ]);

        foreach ([LotteryException::UNAUTHORIZED, LotteryException::UNAVAILABLE] as $expected) {
            try {
                $this->provider()->draws();
                $this->fail('Exception attendue');
            } catch (LotteryException $e) {
                $this->assertSame($expected, $e->reason);
                $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            }
        }
    }

    public function test_unexpected_payload_is_invalid_response(): void
    {
        Http::fake([self::BASE.'/draws' => Http::response(['data' => ['name' => 'API Borlette']])]);

        try {
            $this->provider()->draws();
            $this->fail('Exception attendue');
        } catch (LotteryException $e) {
            $this->assertSame(LotteryException::INVALID_RESPONSE, $e->reason);
        }
    }

    public function test_provider_is_bound_from_config(): void
    {
        $this->assertInstanceOf(UnitySoftConnectLotteryProvider::class, $this->provider());
        $this->assertSame('unitysoftconnect', $this->provider()->name());
    }
}
