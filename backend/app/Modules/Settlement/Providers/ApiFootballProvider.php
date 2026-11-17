<?php

namespace App\Modules\Settlement\Providers;

use App\Modules\Settlement\Contracts\MatchStatisticsProvider;
use App\Modules\Settlement\Data\FixtureData;
use App\Modules\Settlement\Data\StatisticsData;
use App\Modules\SportsData\Exceptions\SportsDataException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Statistiques des matchs chez API-Football (api-sports.io, V2-D09).
 *
 * - GET /fixtures?date=AAAA-MM-JJ : tous les matchs du jour, score final et à la mi-temps (1 requête par jour).
 * - GET /fixtures/statistics?fixture=ID : corners et cartons de chaque équipe (1 requête par match).
 * Clé dans l'en-tête x-apisports-key (STATISTICS_API_KEY). Une erreur est rendue avec le statut 200 et un champ
 * « errors » non vide (clé invalide, limite du jour, saison hors de la formule…).
 */
class ApiFootballProvider implements MatchStatisticsProvider
{
    /** Statut d'un match terminé à la fin du temps réglementaire (AET : prolongation, PEN : tirs au but). */
    private const FINISHED = 'FT';

    /**
     * @param  array{url: string, key: ?string, timeout: int}  $config
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
    ) {}

    public function name(): string
    {
        return 'apifootball';
    }

    public function fixtures(CarbonImmutable $date): array
    {
        $fixtures = [];
        foreach ($this->get('/fixtures', ['date' => $date->toDateString(), 'timezone' => 'UTC']) as $row) {
            try {
                $fixtures[] = new FixtureData(
                    externalId: (string) $row['fixture']['id'],
                    homeTeam: (string) $row['teams']['home']['name'],
                    awayTeam: (string) $row['teams']['away']['name'],
                    startsAt: CarbonImmutable::parse($row['fixture']['date'])->utc(),
                    finished: ($row['fixture']['status']['short'] ?? null) === self::FINISHED,
                    homeGoals: $this->int($row['goals']['home'] ?? null),
                    awayGoals: $this->int($row['goals']['away'] ?? null),
                    firstHalfHome: $this->int($row['score']['halftime']['home'] ?? null),
                    firstHalfAway: $this->int($row['score']['halftime']['away'] ?? null),
                    homeTeamId: isset($row['teams']['home']['id']) ? (string) $row['teams']['home']['id'] : null,
                    awayTeamId: isset($row['teams']['away']['id']) ? (string) $row['teams']['away']['id'] : null,
                );
            } catch (Throwable) {
                continue; // match illisible : ignoré, les autres restent utilisables
            }
        }

        return $fixtures;
    }

    public function statistics(FixtureData $fixture): StatisticsData
    {
        $byTeam = [];
        foreach ($this->get('/fixtures/statistics', ['fixture' => $fixture->externalId]) as $row) {
            $values = [];
            foreach ($row['statistics'] ?? [] as $stat) {
                $values[(string) ($stat['type'] ?? '')] = $stat['value'] ?? null;
            }
            $byTeam[(string) ($row['team']['id'] ?? '')] = $values;
        }

        $home = $byTeam[(string) $fixture->homeTeamId] ?? null;
        $away = $byTeam[(string) $fixture->awayTeamId] ?? null;

        return new StatisticsData(
            externalId: $fixture->externalId,
            firstHalfHome: $fixture->firstHalfHome,
            firstHalfAway: $fixture->firstHalfAway,
            cornersHome: $this->count($home, ['Corner Kicks']),
            cornersAway: $this->count($away, ['Corner Kicks']),
            cardsHome: $this->count($home, ['Yellow Cards', 'Red Cards']),
            cardsAway: $this->count($away, ['Yellow Cards', 'Red Cards']),
        );
    }

    /**
     * Somme de statistiques d'une équipe ; une valeur vide vaut 0 (API-Football n'écrit pas les zéros),
     * mais une équipe sans aucune statistique donne null.
     *
     * @param  array<string, mixed>|null  $values
     * @param  list<string>  $types
     */
    private function count(?array $values, array $types): ?int
    {
        if ($values === null || $values === []) {
            return null;
        }

        $total = 0;
        foreach ($types as $type) {
            $value = $values[$type] ?? null;
            if ($value !== null && ! is_numeric($value)) {
                return null;
            }
            $total += (int) $value;
        }

        return $total;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function get(string $path, array $query): array
    {
        if (empty($this->config['key'])) {
            throw new SportsDataException(SportsDataException::UNAUTHORIZED, 'Clé API-Football absente (STATISTICS_API_KEY).');
        }

        try {
            $response = $this->http
                ->baseUrl(rtrim($this->config['url'], '/'))
                ->withHeaders(['x-apisports-key' => $this->config['key']])
                ->acceptJson()
                ->timeout($this->config['timeout'])
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->get($path, $query);
        } catch (ConnectionException $e) {
            throw new SportsDataException(SportsDataException::UNAVAILABLE, 'API-Football injoignable.', $e);
        }

        $errors = $response->json('errors');
        if ($response->successful() && empty($errors) && is_array($response->json('response'))) {
            Log::info('settlement.statistics_call', ['path' => $path, 'remaining' => $response->header('x-ratelimit-requests-remaining')]);

            return array_values($response->json('response'));
        }

        $text = mb_strtolower(json_encode($errors) ?: '');
        $reason = match (true) {
            $response->status() === 401, $response->status() === 403, str_contains($text, 'token'), str_contains($text, 'key') => SportsDataException::UNAUTHORIZED,
            $response->status() === 429, str_contains($text, 'request'), str_contains($text, 'limit') => SportsDataException::QUOTA_EXHAUSTED,
            $response->successful() => SportsDataException::INVALID_RESPONSE,
            default => SportsDataException::UNAVAILABLE,
        };
        Log::warning('settlement.statistics_error', ['path' => $path, 'status' => $response->status(), 'reason' => $reason, 'errors' => $errors]);

        throw new SportsDataException($reason, "API-Football {$response->status()} sur {$path} : ".mb_substr($text, 0, 200));
    }
}
