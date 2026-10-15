<?php

namespace Tests\Feature\Modules\SportsData;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Providers\TheOddsApiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TheOddsApiProviderTest extends TestCase
{
    private const KEY = 'cle-de-test-secrete';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sports_data.provider' => 'theodds',
            'sports_data.theodds.url' => 'https://api.the-odds-api.com/v4',
            'sports_data.theodds.key' => self::KEY,
            'sports_data.theodds.regions' => 'eu',
            'sports_data.theodds.markets' => 'h2h',
            'sports_data.theodds.reference_bookmaker' => null,
            'sports_data.min_remaining_requests' => 25,
        ]);
        Cache::flush();
        $this->app->forgetInstance(SportsDataProvider::class);
        Http::preventStrayRequests();
    }

    private function provider(): SportsDataProvider
    {
        return app(SportsDataProvider::class);
    }

    /** Réponse au format documenté de The Odds API v4. */
    private function oddsPayload(): array
    {
        return [[
            'id' => 'e912304de2b2ce35b473ce2ecd3d1502',
            'sport_key' => 'soccer_epl',
            'sport_title' => 'EPL',
            'commence_time' => '2026-10-17T11:30:00Z',
            'home_team' => 'Everton',
            'away_team' => 'Chelsea',
            'bookmakers' => [
                [
                    'key' => 'winamax_fr',
                    'title' => 'Winamax (FR)',
                    'last_update' => '2026-10-15T20:10:00Z',
                    'markets' => [[
                        'key' => 'h2h',
                        'last_update' => '2026-10-15T20:09:30Z',
                        'outcomes' => [
                            ['name' => 'Chelsea', 'price' => 2.05],
                            ['name' => 'Everton', 'price' => 2.95],
                            ['name' => 'Draw', 'price' => 3.6],
                        ],
                    ]],
                ],
                [
                    'key' => 'unibet_fr',
                    'title' => 'Unibet (FR)',
                    'last_update' => '2026-10-15T20:05:00Z',
                    'markets' => [[
                        'key' => 'h2h',
                        'outcomes' => [
                            ['name' => 'Chelsea', 'price' => 2.1],
                            ['name' => 'Everton', 'price' => 2.9],
                            ['name' => 'Draw', 'price' => 3.5],
                        ],
                    ]],
                ],
            ],
        ]];
    }

    private function quotaHeaders(int $remaining = 499, int $used = 1, int $last = 1): array
    {
        return ['x-requests-remaining' => (string) $remaining, 'x-requests-used' => (string) $used, 'x-requests-last' => (string) $last];
    }

    public function test_the_provider_is_selected_by_configuration(): void
    {
        $this->assertInstanceOf(TheOddsApiProvider::class, $this->provider());

        config(['sports_data.provider' => 'fake']);
        $this->app->forgetInstance(SportsDataProvider::class);

        $this->assertInstanceOf(FakeSportsDataProvider::class, app(SportsDataProvider::class));
    }

    public function test_events_are_mapped_with_exact_decimal_odds(): void
    {
        Http::fake(['api.the-odds-api.com/*' => Http::response($this->oddsPayload(), 200, $this->quotaHeaders())]);

        $events = $this->provider()->events('soccer_epl');

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('e912304de2b2ce35b473ce2ecd3d1502', $event->externalId);
        $this->assertSame('Everton', $event->homeTeam);
        $this->assertSame('2026-10-17T11:30:00+00:00', $event->startsAt->toIso8601String());
        $this->assertCount(2, $event->markets);

        $market = $event->markets[0];
        $this->assertSame('winamax_fr', $market->bookmaker);
        $this->assertSame('2026-10-15T20:09:30+00:00', $market->updatedAt->toIso8601String());
        $this->assertSame(['2.05', '2.95', '3.6'], array_map(fn ($s) => $s->odds, $market->selections));

        // Sans last_update sur le marché, on prend celui du bookmaker.
        $this->assertSame('2026-10-15T20:05:00+00:00', $event->markets[1]->updatedAt->toIso8601String());

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/sports/soccer_epl/odds')
            && $r['regions'] === 'eu' && $r['markets'] === 'h2h' && $r['oddsFormat'] === 'decimal');
    }

    public function test_reference_bookmaker_filters_markets(): void
    {
        config(['sports_data.theodds.reference_bookmaker' => 'unibet_fr']);
        $this->app->forgetInstance(SportsDataProvider::class);
        Http::fake(['*' => Http::response($this->oddsPayload(), 200, $this->quotaHeaders())]);

        $markets = $this->provider()->events('soccer_epl')[0]->markets;

        $this->assertCount(1, $markets);
        $this->assertSame('unibet_fr', $markets[0]->bookmaker);
    }

    public function test_a_malformed_event_is_skipped_without_blocking_the_others(): void
    {
        $payload = $this->oddsPayload();
        $payload[] = ['id' => 'casse', 'sport_key' => 'soccer_epl', 'home_team' => 'A']; // champs manquants
        $payload[0]['bookmakers'][0]['markets'][0]['outcomes'][0]['price'] = 2.05;
        Http::fake(['*' => Http::response($payload, 200, $this->quotaHeaders())]);

        $this->assertCount(1, $this->provider()->events('soccer_epl'));
    }

    public function test_results_are_mapped_with_scores(): void
    {
        Http::fake(['*' => Http::response([[
            'id' => 'abc',
            'sport_key' => 'soccer_epl',
            'commence_time' => '2026-10-14T19:00:00Z',
            'completed' => true,
            'home_team' => 'Arsenal',
            'away_team' => 'Leeds United',
            'scores' => [['name' => 'Arsenal', 'score' => '3'], ['name' => 'Leeds United', 'score' => '1']],
            'last_update' => '2026-10-14T21:00:00Z',
        ], [
            'id' => 'def',
            'sport_key' => 'soccer_epl',
            'commence_time' => '2026-10-18T19:00:00Z',
            'completed' => false,
            'home_team' => 'Fulham',
            'away_team' => 'Hull City',
            'scores' => null,
            'last_update' => null,
        ]], 200, $this->quotaHeaders(497, 3, 2))]);

        [$finished, $upcoming] = $this->provider()->results('soccer_epl', 2);

        $this->assertTrue($finished->completed);
        $this->assertSame('3', $finished->homeScore);
        $this->assertSame('1', $finished->awayScore);
        $this->assertFalse($upcoming->completed);
        $this->assertNull($upcoming->homeScore);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/scores') && (int) $r['daysFrom'] === 2);
    }

    public function test_quota_is_read_from_response_headers(): void
    {
        Http::fake(['*' => Http::response($this->oddsPayload(), 200, $this->quotaHeaders(420, 80, 1))]);

        $this->provider()->events('soccer_epl');
        $quota = $this->provider()->quota();

        $this->assertSame(420, $quota->remaining);
        $this->assertSame(80, $quota->used);
        $this->assertSame(1, $quota->lastCost);
    }

    public function test_paid_calls_stop_below_the_quota_threshold_but_free_calls_continue(): void
    {
        Http::fake([
            '*/sports?*' => Http::response([['key' => 'soccer_epl', 'group' => 'Soccer', 'title' => 'EPL', 'active' => true, 'has_outrights' => false]], 200, $this->quotaHeaders(10, 490, 0)),
            '*' => Http::response($this->oddsPayload(), 200, $this->quotaHeaders(10, 490, 1)),
        ]);

        $this->provider()->sports(); // gratuit : met à jour le quota connu (10 restants)

        try {
            $this->provider()->events('soccer_epl');
            $this->fail('Un appel payant a été envoyé sous le seuil de quota.');
        } catch (SportsDataException $e) {
            $this->assertSame(SportsDataException::QUOTA_EXHAUSTED, $e->reason);
        }

        Http::assertSentCount(1);
        $this->assertCount(1, $this->provider()->sports()); // l'appel gratuit reste possible
    }

    public function test_provider_errors_are_classified(): void
    {
        $cases = [
            [401, SportsDataException::UNAUTHORIZED],
            [429, SportsDataException::QUOTA_EXHAUSTED],
            [404, SportsDataException::NOT_FOUND],
            [500, SportsDataException::UNAVAILABLE],
        ];

        $sequence = Http::sequence();
        foreach ($cases as [$status]) {
            $sequence->push(['message' => 'erreur'], $status);
        }
        Http::fake(['*' => $sequence]);

        foreach ($cases as [$status, $reason]) {
            try {
                $this->provider()->events('soccer_epl');
                $this->fail("Aucune exception pour le statut {$status}.");
            } catch (SportsDataException $e) {
                $this->assertSame($reason, $e->reason, "Statut {$status}");
            }
        }
    }

    public function test_network_failure_is_reported_as_unavailable_without_leaking_the_key(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 on https://api.the-odds-api.com/v4/sports?apiKey='.self::KEY));

        try {
            $this->provider()->sports();
            $this->fail('Aucune exception en cas de panne réseau.');
        } catch (SportsDataException $e) {
            $this->assertSame(SportsDataException::UNAVAILABLE, $e->reason);
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function test_paid_calls_are_never_retried_on_network_failure(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        try {
            $this->provider()->events('soccer_epl');
        } catch (SportsDataException $e) {
            $this->assertSame(SportsDataException::UNAVAILABLE, $e->reason);
        }

        $this->assertSame(1, $attempts, 'Un appel payant a été rejoué (crédit gaspillé).');
    }

    public function test_free_calls_are_retried_on_network_failure(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            if (++$attempts < 3) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response([], 200);
        });

        $this->assertSame([], $this->provider()->sports());
        $this->assertSame(3, $attempts);
    }

    public function test_missing_key_is_refused_before_any_call(): void
    {
        config(['sports_data.theodds.key' => null]);
        $this->app->forgetInstance(SportsDataProvider::class);
        Http::fake();

        $this->expectExceptionObject(new SportsDataException(SportsDataException::UNAUTHORIZED, 'Clé The Odds API absente (SPORTS_DATA_API_KEY).'));

        try {
            $this->provider()->sports();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_unsafe_sport_keys_are_rejected(): void
    {
        Http::fake();

        $this->expectException(SportsDataException::class);

        try {
            $this->provider()->events('../sports?apiKey=x');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_unexpected_payload_is_an_invalid_response(): void
    {
        Http::fake(['*' => Http::response(['message' => 'pas une liste'], 200)]);

        $this->expectException(SportsDataException::class);
        $this->provider()->events('soccer_epl');
    }

    public function test_check_command_uses_only_the_free_endpoint(): void
    {
        config(['sports_data.sports' => ['soccer_epl', 'cricket_inconnu']]);
        Http::fake(['*/sports?*' => Http::response([['key' => 'soccer_epl', 'group' => 'Soccer', 'title' => 'EPL', 'active' => true, 'has_outrights' => false]], 200, $this->quotaHeaders(499, 1, 0))]);

        $this->artisan('sports-data:check')
            ->expectsOutputToContain('Fournisseur : theodds')
            ->expectsOutputToContain('499 requêtes restantes')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/v4/sports'));
    }
}
