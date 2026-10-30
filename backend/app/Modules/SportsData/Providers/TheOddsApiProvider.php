<?php

namespace App\Modules\SportsData\Providers;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Data\EventData;
use App\Modules\SportsData\Data\MarketData;
use App\Modules\SportsData\Data\QuotaData;
use App\Modules\SportsData\Data\ResultData;
use App\Modules\SportsData\Data\SelectionData;
use App\Modules\SportsData\Data\SportData;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Models\ProviderApiCall;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Connecteur The Odds API v4 (https://the-odds-api.com/liveapi/guides/v4/).
 *
 * Quota : chaque appel de cotes coûte (marchés × régions) crédits, /scores coûte 1 (2 avec daysFrom),
 * /sports est gratuit. Le quota restant est lu dans les en-têtes x-requests-* et mémorisé ;
 * sous le seuil "min_remaining_requests", plus aucun appel payant n'est envoyé. Chaque réponse est
 * journalisée (ProviderApiCall) pour le budget de crédits (Services/CreditBudget).
 *
 * La clé n'apparaît jamais dans les journaux ni dans les messages d'erreur.
 */
class TheOddsApiProvider implements SportsDataProvider
{
    public const QUOTA_CACHE_KEY = 'sports_data:theodds:quota';

    /**
     * @param  array{url: string, key: ?string, timeout: int, regions: string, markets: string, reference_bookmaker: ?string}  $config
     */
    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly array $config,
        private readonly int $minRemaining,
    ) {}

    public function name(): string
    {
        return 'theodds';
    }

    public function sports(): array
    {
        $payload = $this->get('/sports', [], paid: false);

        return array_map(fn (array $s) => new SportData(
            key: (string) $s['key'],
            group: (string) ($s['group'] ?? ''),
            title: (string) ($s['title'] ?? $s['key']),
            active: (bool) ($s['active'] ?? false),
            hasOutrights: (bool) ($s['has_outrights'] ?? false),
        ), $this->list($payload));
    }

    public function events(string $sportKey): array
    {
        $payload = $this->get("/sports/{$this->segment($sportKey)}/odds", [
            'regions' => $this->config['regions'],
            'markets' => $this->config['markets'],
            'oddsFormat' => 'decimal',
            'dateFormat' => 'iso',
        ]);

        $events = [];
        foreach ($this->list($payload) as $event) {
            try {
                $events[] = $this->event($event);
            } catch (Throwable $e) {
                // Un événement mal formé est ignoré (et journalisé) sans bloquer les autres.
                Log::warning('sports_data.invalid_event', ['provider' => $this->name(), 'id' => $event['id'] ?? null, 'error' => $e->getMessage()]);
            }
        }

        return $events;
    }

    /** /events/{id}/odds : seul accès aux marchés hors 1X2, plus/moins et handicap (btts, double chance, score exact…). */
    public function eventMarkets(string $sportKey, string $eventId, string $markets): EventData
    {
        if (! preg_match('/^[a-z0-9]+$/i', $eventId)) {
            throw new SportsDataException(SportsDataException::NOT_FOUND, "Identifiant d'événement invalide : {$eventId}");
        }

        $payload = $this->get("/sports/{$this->segment($sportKey)}/events/{$eventId}/odds", [
            'regions' => $this->config['regions'],
            'markets' => $markets,
            'oddsFormat' => 'decimal',
            'dateFormat' => 'iso',
        ]);

        try {
            return $this->event(is_array($payload) ? $payload : []);
        } catch (Throwable $e) {
            throw new SportsDataException(SportsDataException::INVALID_RESPONSE, "Événement {$eventId} mal formé : {$e->getMessage()}");
        }
    }

    /** /events est gratuit (ne compte pas dans le quota) : liste des matchs sans cotes. */
    public function upcomingStarts(string $sportKey): array
    {
        $payload = $this->get("/sports/{$this->segment($sportKey)}/events", ['dateFormat' => 'iso'], paid: false);

        $starts = [];
        foreach ($this->list($payload) as $event) {
            if (isset($event['commence_time'])) {
                $starts[] = CarbonImmutable::parse($event['commence_time']);
            }
        }

        return $starts;
    }

    public function results(string $sportKey, int $daysFrom = 1): array
    {
        // Sans daysFrom (1 crédit au lieu de 2) : seulement les matchs en cours et à venir.
        $query = ['dateFormat' => 'iso'] + ($daysFrom > 0 ? ['daysFrom' => min(3, $daysFrom)] : []);
        $payload = $this->get("/sports/{$this->segment($sportKey)}/scores", $query);

        return array_map(function (array $r) use ($sportKey) {
            $scores = collect($r['scores'] ?? [])->mapWithKeys(fn ($s) => [(string) $s['name'] => (string) $s['score']]);

            return new ResultData(
                externalId: (string) $r['id'],
                sportKey: (string) ($r['sport_key'] ?? $sportKey),
                homeTeam: (string) $r['home_team'],
                awayTeam: (string) $r['away_team'],
                startsAt: CarbonImmutable::parse($r['commence_time']),
                completed: (bool) ($r['completed'] ?? false),
                homeScore: $scores->get((string) $r['home_team']),
                awayScore: $scores->get((string) $r['away_team']),
                updatedAt: isset($r['last_update']) ? CarbonImmutable::parse($r['last_update']) : null,
            );
        }, $this->list($payload));
    }

    public function quota(): ?QuotaData
    {
        // Stocké en tableau : le cache de Laravel refuse de désérialiser des objets arbitraires.
        $quota = $this->cache->get(self::QUOTA_CACHE_KEY);

        if (! is_array($quota)) {
            return null;
        }

        return new QuotaData($quota['remaining'], $quota['used'], $quota['last_cost'], CarbonImmutable::parse($quota['observed_at']));
    }

    /** @param array<string, mixed> $event */
    private function event(array $event): EventData
    {
        $markets = [];
        $bookmakers = $event['bookmakers'] ?? [];
        $reference = $this->config['reference_bookmaker'] ?? null;

        if ($reference) {
            $bookmakers = array_values(array_filter($bookmakers, fn ($b) => ($b['key'] ?? null) === $reference));
        }

        foreach ($bookmakers as $bookmaker) {
            foreach ($bookmaker['markets'] ?? [] as $market) {
                $markets[] = new MarketData(
                    key: (string) $market['key'],
                    bookmaker: (string) $bookmaker['key'],
                    updatedAt: CarbonImmutable::parse($market['last_update'] ?? $bookmaker['last_update']),
                    selections: array_map(fn (array $o) => new SelectionData(
                        name: (string) $o['name'],
                        odds: SelectionData::oddsFromProvider($o['price']),
                        point: isset($o['point']) ? (string) $o['point'] : null,
                    ), $market['outcomes'] ?? []),
                );
            }
        }

        return new EventData(
            externalId: (string) $event['id'],
            sportKey: (string) $event['sport_key'],
            competition: (string) ($event['sport_title'] ?? $event['sport_key']),
            homeTeam: (string) $event['home_team'],
            awayTeam: (string) $event['away_team'],
            startsAt: CarbonImmutable::parse($event['commence_time']),
            markets: $markets,
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return mixed
     */
    private function get(string $path, array $query, bool $paid = true)
    {
        if (empty($this->config['key'])) {
            throw new SportsDataException(SportsDataException::UNAUTHORIZED, 'Clé The Odds API absente (SPORTS_DATA_API_KEY).');
        }

        $quota = $this->quota();
        if ($paid && $quota !== null && $quota->remaining < $this->minRemaining) {
            throw new SportsDataException(
                SportsDataException::QUOTA_EXHAUSTED,
                "Quota The Odds API presque épuisé ({$quota->remaining} restants, seuil {$this->minRemaining}) : appel annulé.",
            );
        }

        try {
            $response = $this->http
                ->baseUrl(rtrim($this->config['url'], '/'))
                ->acceptJson()
                ->timeout($this->config['timeout'])
                // Nouvel essai réseau seulement pour les appels gratuits : rejouer un appel payant coûte un crédit,
                // même si la première réponse n'est jamais arrivée (délai dépassé).
                ->retry($paid ? 1 : 3, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->get($path, ['apiKey' => $this->config['key']] + $query);
        } catch (ConnectionException $e) {
            throw new SportsDataException(SportsDataException::UNAVAILABLE, 'The Odds API injoignable : '.$this->redact($e->getMessage()), $e);
        }

        $this->rememberQuota($response);
        $this->record($path, $response);

        if ($response->successful()) {
            return $response->json();
        }

        $reason = match (true) {
            $response->status() === 401 => SportsDataException::UNAUTHORIZED,
            $response->status() === 429 => SportsDataException::QUOTA_EXHAUSTED,
            $response->status() === 404, $response->status() === 422 => SportsDataException::NOT_FOUND,
            default => SportsDataException::UNAVAILABLE,
        };

        $message = (string) ($response->json('message') ?? $response->reason());
        Log::warning('sports_data.provider_error', ['provider' => $this->name(), 'path' => $path, 'status' => $response->status(), 'reason' => $reason]);

        throw new SportsDataException($reason, "The Odds API {$response->status()} sur {$path} : ".$this->redact($message));
    }

    /** @return list<array<string, mixed>> */
    private function list(mixed $payload): array
    {
        if (! is_array($payload) || ! array_is_list($payload)) {
            throw new SportsDataException(SportsDataException::INVALID_RESPONSE, 'Réponse The Odds API inattendue (liste attendue).');
        }

        return $payload;
    }

    private function rememberQuota(Response $response): void
    {
        $remaining = $response->header('x-requests-remaining');

        if ($remaining === '') {
            return;
        }

        $this->cache->forever(self::QUOTA_CACHE_KEY, [
            'remaining' => (int) $remaining,
            'used' => (int) $response->header('x-requests-used'),
            'last_cost' => (int) $response->header('x-requests-last'),
            'observed_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    /**
     * Journalise la réponse (sports_data_api_calls) pour le suivi des crédits : chemin générique,
     * compétition et en-têtes x-requests-*, jamais la clé ni les paramètres. Un échec d'écriture
     * est journalisé sans interrompre la synchronisation.
     */
    private function record(string $path, Response $response): void
    {
        try {
            $competition = preg_match('#^/sports/([a-z0-9_]+)/#', $path, $m) ? $m[1] : null;
            $path = preg_replace('#/events/[^/]+/#', '/events/{event}/', $path) ?? $path;
            $header = fn (string $name) => $response->header($name) === '' ? null : (int) $response->header($name);

            ProviderApiCall::create([
                'provider' => $this->name(),
                'endpoint' => $competition === null ? $path : str_replace("/{$competition}/", '/{sport}/', $path),
                'competition' => $competition,
                'cost' => $header('x-requests-last') ?? 0,
                'remaining' => $header('x-requests-remaining'),
                'used' => $header('x-requests-used'),
                'status' => $response->status(),
                'called_at' => CarbonImmutable::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('sports_data.api_call_not_recorded', ['provider' => $this->name(), 'path' => $path, 'error' => $this->redact($e->getMessage())]);
        }
    }

    /** Clé de sport insérée dans l'URL : caractères sûrs uniquement. */
    private function segment(string $sportKey): string
    {
        if (! preg_match('/^[a-z0-9_]+$/', $sportKey)) {
            throw new SportsDataException(SportsDataException::NOT_FOUND, "Clé de sport invalide : {$sportKey}");
        }

        return $sportKey;
    }

    private function redact(string $text): string
    {
        $key = (string) ($this->config['key'] ?? '');

        return $key === '' ? $text : str_replace($key, '***', $text);
    }
}
