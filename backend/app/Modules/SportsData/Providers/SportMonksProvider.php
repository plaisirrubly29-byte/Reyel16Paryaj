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
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Connecteur SportMonks Football API v3 (https://docs.sportmonks.com/football) : matchs, cotes et scores du football.
 * Les autres sports restent chez The Odds API (TheOddsApiProvider) ; les deux tournent côte à côte, chacun avec son
 * nom de fournisseur sur les matchs et compétitions qu'il synchronise.
 *
 * - Clés de compétition : celle de The Odds API quand la ligue y correspond (league_keys : 8 → « soccer_epl »), pour
 *   garder libellés, ordre du menu et marge ; sinon « soccer_sm_{id} ». Le préfixe « soccer » range le match en Football.
 * - Pas de crédits : SportMonks limite le nombre d'appels par heure et par type de données (180 en formule gratuite).
 *   Chaque réponse est journalisée (ProviderApiCall, coût 0, appels restants dans « remaining ») ; un 429 donne
 *   quota_exhausted.
 * - Cotes transposées au format des marchés de The Odds API (noms des équipes, « Draw », « Over »/« Under »…) pour
 *   passer par la même validation (CatalogueSyncService) : 1X2, plus/moins, les deux équipes marquent, double chance,
 *   score exact. Un marché par bookmaker suivi, le bookmaker de référence en premier.
 *
 * La clé n'apparaît jamais dans les journaux ni dans les messages d'erreur (en-tête Authorization).
 */
class SportMonksProvider implements SportsDataProvider
{
    public const NAME = 'sportmonks';

    /** Sport « Football » chez SportMonks. */
    private const FOOTBALL = 1;

    /** Préfixe des compétitions sans équivalent chez The Odds API. */
    private const KEY_PREFIX = 'soccer_sm_';

    /** Marchés SportMonks → marché du catalogue. */
    private const MARKETS = [
        1 => 'h2h',            // Full Time Result
        80 => 'totals',        // Goals Over/Under
        14 => 'btts',          // Both Teams to Score
        2 => 'double_chance',  // Double Chance
        57 => 'correct_score', // Correct Score
    ];

    /** États SportMonks d'un match terminé ; le score réglé est celui du temps réglementaire. */
    private const FINISHED = ['FT', 'AET', 'FT_PEN'];

    /** Matchs en cours : relus une seule fois pour toutes les ligues, gardés quelques secondes. */
    private const INPLAY_CACHE_KEY = 'sports_data:sportmonks:inplay';

    /**
     * @param  array{url: string, key: ?string, timeout: int, bookmakers: list<int>, reference_bookmaker: ?int, league_keys: array<int, string>, horizon_days: int, inplay_cache_seconds: int}  $config
     */
    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly array $config,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    /** Clé de compétition d'une ligue SportMonks. */
    public function keyForLeague(int $leagueId): string
    {
        return $this->leagueKeys()[$leagueId] ?? self::KEY_PREFIX.$leagueId;
    }

    /** Ligue SportMonks d'une clé de compétition (correspondance ou « soccer_sm_{id} »). */
    public function leagueForKey(string $key): int
    {
        $mapped = array_search($key, $this->leagueKeys(), true);
        if ($mapped !== false) {
            return (int) $mapped;
        }

        if (preg_match('/^'.self::KEY_PREFIX.'(\d+)$/', $key, $m)) {
            return (int) $m[1];
        }

        throw new SportsDataException(SportsDataException::NOT_FOUND, "Compétition inconnue chez SportMonks : {$key}");
    }

    /** Ligues de football de l'abonnement (/my/leagues, sans « include » : refusé sur ce point d'accès). */
    public function sports(): array
    {
        $sports = [];
        foreach ($this->pages('/my/leagues', []) as $league) {
            if ((int) ($league['sport_id'] ?? 0) !== self::FOOTBALL) {
                continue;
            }

            $sports[] = new SportData(
                key: $this->keyForLeague((int) $league['id']),
                group: 'Soccer',
                title: (string) $league['name'],
                active: (bool) ($league['active'] ?? false),
                hasOutrights: false,
            );
        }

        return $sports;
    }

    public function events(string $sportKey): array
    {
        $leagueId = $this->leagueForKey($sportKey);
        $from = CarbonImmutable::now('UTC')->toDateString();
        $to = CarbonImmutable::now('UTC')->addDays(max(1, (int) $this->config['horizon_days']))->toDateString();

        $fixtures = $this->pages("/football/fixtures/between/{$from}/{$to}", [
            'include' => 'participants;league.country;odds',
            'filters' => $this->filters(['fixtureLeagues' => [$leagueId], 'markets' => array_keys(self::MARKETS), 'bookmakers' => $this->bookmakers()]),
        ], $sportKey);

        $events = [];
        foreach ($fixtures as $fixture) {
            try {
                $events[] = $this->event($fixture, $sportKey);
            } catch (Throwable $e) {
                Log::warning('sports_data.invalid_event', ['provider' => $this->name(), 'id' => $fixture['id'] ?? null, 'error' => $e->getMessage()]);
            }
        }

        return $events;
    }

    /** Marchés d'un seul match ($markets : clés du catalogue, ex. « btts,correct_score »). */
    public function eventMarkets(string $sportKey, string $eventId, string $markets): EventData
    {
        if (! ctype_digit($eventId)) {
            throw new SportsDataException(SportsDataException::NOT_FOUND, "Identifiant d'événement invalide : {$eventId}");
        }

        $wanted = array_map('trim', explode(',', $markets));
        $ids = array_keys(array_filter(self::MARKETS, fn (string $key) => in_array($key, $wanted, true)));

        $payload = $this->get("/football/fixtures/{$eventId}", [
            'include' => 'participants;league.country;odds',
            'filters' => $this->filters(['markets' => $ids ?: array_keys(self::MARKETS), 'bookmakers' => $this->bookmakers()]),
        ], $sportKey);

        try {
            return $this->event((array) ($payload['data'] ?? []), $sportKey);
        } catch (Throwable $e) {
            throw new SportsDataException(SportsDataException::INVALID_RESPONSE, "Événement {$eventId} mal formé : {$e->getMessage()}");
        }
    }

    public function upcomingStarts(string $sportKey): array
    {
        $leagueId = $this->leagueForKey($sportKey);
        $from = CarbonImmutable::now('UTC')->toDateString();
        $to = CarbonImmutable::now('UTC')->addDays(max(1, (int) $this->config['horizon_days']))->toDateString();

        $starts = [];
        foreach ($this->pages("/football/fixtures/between/{$from}/{$to}", ['filters' => $this->filters(['fixtureLeagues' => [$leagueId]])], $sportKey) as $fixture) {
            if (isset($fixture['starting_at_timestamp'])) {
                $starts[] = CarbonImmutable::createFromTimestampUTC((int) $fixture['starting_at_timestamp']);
            }
        }

        return $starts;
    }

    /**
     * $daysFrom = 0 : matchs en cours (/livescores/inplay, un seul appel pour toutes les ligues, gardé
     * inplay_cache_seconds). Sinon : matchs des $daysFrom derniers jours, terminés ou non.
     */
    public function results(string $sportKey, int $daysFrom = 1): array
    {
        $leagueId = $this->leagueForKey($sportKey);
        $include = 'participants;scores;state';

        if ($daysFrom <= 0) {
            $fixtures = $this->cache->remember(
                self::INPLAY_CACHE_KEY,
                max(1, (int) $this->config['inplay_cache_seconds']),
                fn () => $this->pages('/football/livescores/inplay', ['include' => $include], allowEmpty: true),
            );
            $fixtures = array_values(array_filter($fixtures, fn (array $f) => (int) ($f['league_id'] ?? 0) === $leagueId));
        } else {
            $from = CarbonImmutable::now('UTC')->subDays(min(3, $daysFrom))->toDateString();
            $to = CarbonImmutable::now('UTC')->toDateString();
            $fixtures = $this->pages("/football/fixtures/between/{$from}/{$to}", [
                'include' => $include,
                'filters' => $this->filters(['fixtureLeagues' => [$leagueId]]),
            ], $sportKey, allowEmpty: true);
        }

        $results = [];
        foreach ($fixtures as $fixture) {
            try {
                $results[] = $this->result($fixture, $sportKey);
            } catch (Throwable $e) {
                Log::warning('sports_data.invalid_result', ['provider' => $this->name(), 'id' => $fixture['id'] ?? null, 'error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    /** Pas de crédits chez SportMonks : seulement une limite d'appels par heure. */
    public function quota(): ?QuotaData
    {
        return null;
    }

    /** @param array<string, mixed> $fixture */
    private function event(array $fixture, string $sportKey): EventData
    {
        [$home, $away] = $this->teams($fixture);
        $bookmakers = $this->bookmakers();
        $byBookmaker = [];

        foreach ($fixture['odds'] ?? [] as $odd) {
            $key = self::MARKETS[(int) ($odd['market_id'] ?? 0)] ?? null;
            $bookmaker = (int) ($odd['bookmaker_id'] ?? 0);
            if ($key === null || ($bookmakers !== [] && ! in_array($bookmaker, $bookmakers, true)) || ! empty($odd['stopped'])) {
                continue;
            }
            $byBookmaker[$bookmaker][$key][] = $odd;
        }

        // Bookmaker de référence d'abord : le catalogue retient le premier marché valide de chaque type.
        $reference = (int) ($this->config['reference_bookmaker'] ?? 0);
        uksort($byBookmaker, fn (int $a, int $b) => [$a !== $reference, $a] <=> [$b !== $reference, $b]);

        $markets = [];
        foreach ($byBookmaker as $bookmaker => $rows) {
            foreach ($rows as $key => $odds) {
                try {
                    $selections = $this->selections($key, $odds, $home, $away);
                    if (count($selections) < 2) {
                        continue;
                    }
                    $markets[] = new MarketData(
                        key: $key,
                        bookmaker: "sportmonks:{$bookmaker}",
                        updatedAt: $this->latestUpdate($odds),
                        selections: $selections,
                    );
                } catch (InvalidArgumentException $e) {
                    Log::warning('sports_data.invalid_market', ['provider' => $this->name(), 'id' => $fixture['id'] ?? null,
                        'bookmaker' => $bookmaker, 'market' => $key, 'error' => $e->getMessage()]);
                }
            }
        }

        $league = $fixture['league'] ?? [];
        $country = $league['country']['name'] ?? null;
        $title = (string) ($league['name'] ?? $sportKey);

        return new EventData(
            externalId: (string) $fixture['id'],
            sportKey: $sportKey,
            competition: $country ? "{$title} - {$country}" : $title,
            homeTeam: $home,
            awayTeam: $away,
            startsAt: $this->startsAt($fixture),
            markets: $markets,
        );
    }

    /**
     * Lignes SportMonks d'un marché → sélections au format The Odds API.
     *
     * @param  list<array<string, mixed>>  $odds
     * @return list<SelectionData>
     */
    private function selections(string $key, array $odds, string $home, string $away): array
    {
        $price = fn (array $o) => SelectionData::oddsFromProvider((string) $o['value']);
        $side = fn (string $label) => match (mb_strtolower(trim($label))) {
            'home', '1' => $home,
            'away', '2' => $away,
            'draw', 'x' => 'Draw',
            default => throw new InvalidArgumentException("Sélection inconnue : {$label}"),
        };

        return match ($key) {
            'h2h' => array_map(fn (array $o) => new SelectionData($side((string) $o['label']), $price($o)), $odds),
            'btts' => array_map(fn (array $o) => new SelectionData((string) $o['label'], $price($o)), $odds),
            'totals' => $this->totals($odds, $price),
            'double_chance' => $this->doubleChance($odds, $home, $away, $price),
            'correct_score' => $this->correctScore($odds, $home, $away, $price),
        };
    }

    /**
     * Plus/moins : une seule ligne, 2.5 buts si elle est cotée, sinon la première qui a un plus et un moins.
     *
     * @param  list<array<string, mixed>>  $odds
     * @return list<SelectionData>
     */
    private function totals(array $odds, \Closure $price): array
    {
        $lines = [];
        foreach ($odds as $o) {
            $line = (string) ($o['total'] ?? $o['name'] ?? '');
            $label = mb_strtolower(trim((string) $o['label']));
            if (is_numeric($line) && in_array($label, ['over', 'under'], true)) {
                $lines[$line][$label] = $o;
            }
        }

        $complete = array_filter($lines, fn (array $pair) => isset($pair['over'], $pair['under']));
        if ($complete === []) {
            throw new InvalidArgumentException('Marché totals incomplet (plus ou moins absent).');
        }

        $line = array_key_exists('2.5', $complete) ? '2.5' : (string) array_key_first($complete);

        return [
            new SelectionData('Over', $price($complete[$line]['over']), $line),
            new SelectionData('Under', $price($complete[$line]['under']), $line),
        ];
    }

    /**
     * Double chance : SportMonks nomme les paires avec des noms d'équipe parfois raccourcis (« Paderborn or Draw »,
     * « Draw or Schalke ») ; la place du nul suffit à reconnaître la paire, renommée avec les noms complets.
     *
     * @param  list<array<string, mixed>>  $odds
     * @return list<SelectionData>
     */
    private function doubleChance(array $odds, string $home, string $away, \Closure $price): array
    {
        $selections = [];
        foreach ($odds as $o) {
            $parts = preg_split('/\s+or\s+|\s*\/\s*/i', trim((string) $o['label'])) ?: [];
            if (count($parts) !== 2) {
                throw new InvalidArgumentException("Sélection inconnue : {$o['label']}");
            }

            [$first, $second] = array_map(fn (string $p) => in_array(mb_strtolower(trim($p)), ['draw', 'x'], true), $parts);
            $name = match (true) {
                ! $first && $second => "{$home} or Draw",
                $first && ! $second => "Draw or {$away}",
                ! $first && ! $second => "{$home} or {$away}",
                default => throw new InvalidArgumentException("Sélection inconnue : {$o['label']}"),
            };
            $selections[] = new SelectionData($name, $price($o));
        }

        return $selections;
    }

    /**
     * Score exact : « name » donne le score du vainqueur en premier (« 3-0 » avec le libellé 2 : victoire 3-0 de
     * l'équipe à l'extérieur) ; transposé en « Domicile:0|Extérieur:3 ».
     *
     * @param  list<array<string, mixed>>  $odds
     * @return list<SelectionData>
     */
    private function correctScore(array $odds, string $home, string $away, \Closure $price): array
    {
        $selections = [];
        foreach ($odds as $o) {
            if (! preg_match('/^(\d{1,2})\s*[-:]\s*(\d{1,2})$/', trim((string) ($o['name'] ?? '')), $m)) {
                continue; // « Any Other Score » : pas une sélection du catalogue
            }

            [$a, $b] = [(int) $m[1], (int) $m[2]];
            [$h, $w] = match (mb_strtolower(trim((string) $o['label']))) {
                '2', 'away' => [min($a, $b), max($a, $b)],
                default => [max($a, $b), min($a, $b)], // 1 (domicile) ou nul (scores égaux)
            };
            $selections[] = new SelectionData("{$home}:{$h}|{$away}:{$w}", $price($o));
        }

        return $selections;
    }

    /** @param array<string, mixed> $fixture */
    private function result(array $fixture, string $sportKey): ResultData
    {
        [$home, $away] = $this->teams($fixture);
        $state = (string) ($fixture['state']['developer_name'] ?? $fixture['state']['short_name'] ?? '');
        $completed = in_array($state, self::FINISHED, true);

        // Après prolongation ou tirs au but, les paris se règlent sur le temps réglementaire (fin de 2e mi-temps).
        $description = $completed && $state !== 'FT' ? '2ND_HALF' : 'CURRENT';
        $goals = ['home' => null, 'away' => null];
        foreach ($fixture['scores'] ?? [] as $score) {
            $side = $score['score']['participant'] ?? null;
            if (($score['description'] ?? null) === $description && in_array($side, ['home', 'away'], true)) {
                $goals[$side] = (string) (int) ($score['score']['goals'] ?? 0);
            }
        }

        // Match en cours sans but encore enregistré : 0-0.
        if (! $completed && in_array($state, ['INPLAY_1ST_HALF', 'HT', 'INPLAY_2ND_HALF', 'BREAK', 'INPLAY_ET', 'EXTRA_TIME_BREAK', 'INPLAY_ET_SECOND_HALF', 'PEN_BREAK', 'INPLAY_PENALTIES'], true)) {
            $goals = array_map(fn (?string $g) => $g ?? '0', $goals);
        }

        return new ResultData(
            externalId: (string) $fixture['id'],
            sportKey: $sportKey,
            homeTeam: $home,
            awayTeam: $away,
            startsAt: $this->startsAt($fixture),
            completed: $completed && $goals['home'] !== null && $goals['away'] !== null,
            homeScore: $goals['home'],
            awayScore: $goals['away'],
            updatedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array{0: string, 1: string}
     */
    private function teams(array $fixture): array
    {
        $teams = [];
        foreach ($fixture['participants'] ?? [] as $participant) {
            $location = $participant['meta']['location'] ?? null;
            if (in_array($location, ['home', 'away'], true)) {
                $teams[$location] = trim((string) $participant['name']);
            }
        }

        if (empty($teams['home']) || empty($teams['away'])) {
            throw new InvalidArgumentException('Équipes absentes.');
        }

        return [$teams['home'], $teams['away']];
    }

    /** @param array<string, mixed> $fixture */
    private function startsAt(array $fixture): CarbonImmutable
    {
        if (isset($fixture['starting_at_timestamp'])) {
            return CarbonImmutable::createFromTimestampUTC((int) $fixture['starting_at_timestamp']);
        }

        // « starting_at » est en UTC chez SportMonks.
        return CarbonImmutable::parse((string) $fixture['starting_at'], 'UTC');
    }

    /** @param list<array<string, mixed>> $odds */
    private function latestUpdate(array $odds): CarbonImmutable
    {
        $latest = null;
        foreach ($odds as $o) {
            $at = $o['latest_bookmaker_update'] ?? $o['created_at'] ?? null;
            if ($at !== null) {
                $parsed = CarbonImmutable::parse((string) $at, 'UTC');
                $latest = $latest === null || $parsed->gt($latest) ? $parsed : $latest;
            }
        }

        return $latest ?? CarbonImmutable::now();
    }

    /** @return list<int> */
    private function bookmakers(): array
    {
        return array_values(array_map('intval', (array) ($this->config['bookmakers'] ?? [])));
    }

    /** @return array<int, string> */
    private function leagueKeys(): array
    {
        return (array) ($this->config['league_keys'] ?? []);
    }

    /** @param array<string, list<int>> $filters */
    private function filters(array $filters): string
    {
        return collect($filters)
            ->filter(fn (array $ids) => $ids !== [])
            ->map(fn (array $ids, string $name) => $name.':'.implode(',', $ids))
            ->implode(';');
    }

    /**
     * Toutes les pages d'une liste (50 par page, 10 pages au plus).
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function pages(string $path, array $query, ?string $competition = null, bool $allowEmpty = false): array
    {
        $items = [];
        for ($page = 1; $page <= 10; $page++) {
            $payload = $this->get($path, $query + ['per_page' => 50, 'page' => $page], $competition, $allowEmpty);
            $data = $payload['data'] ?? [];
            if (! is_array($data) || ! array_is_list($data)) {
                throw new SportsDataException(SportsDataException::INVALID_RESPONSE, 'Réponse SportMonks inattendue (liste attendue).');
            }
            array_push($items, ...$data);

            if (! ($payload['pagination']['has_more'] ?? false)) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query, ?string $competition = null, bool $allowEmpty = false): array
    {
        if (empty($this->config['key'])) {
            throw new SportsDataException(SportsDataException::UNAUTHORIZED, 'Clé SportMonks absente (SPORTMONKS_API_KEY).');
        }

        try {
            $response = $this->http
                ->baseUrl(rtrim($this->config['url'], '/'))
                ->acceptJson()
                ->withHeaders(['Authorization' => $this->config['key']])
                ->timeout($this->config['timeout'])
                ->retry(3, 1000, fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && in_array($e->response->status(), [502, 503, 504], true)), throw: false)
                ->get($path, array_filter($query, fn ($v) => $v !== '' && $v !== null));
        } catch (ConnectionException $e) {
            throw new SportsDataException(SportsDataException::UNAVAILABLE, 'SportMonks injoignable : '.$this->redact($e->getMessage()), $e);
        }

        $this->record($path, $competition, $response);

        $json = $response->json();

        if ($response->successful()) {
            // Aucun résultat : SportMonks répond 200 avec un message et sans « data ».
            if (! isset($json['data'])) {
                if ($allowEmpty || str_contains((string) ($json['message'] ?? ''), 'No result')) {
                    return ['data' => []];
                }

                throw new SportsDataException(SportsDataException::INVALID_RESPONSE, 'Réponse SportMonks sans données.');
            }

            return $json;
        }

        $reason = match (true) {
            $response->status() === 401, $response->status() === 403 => SportsDataException::UNAUTHORIZED,
            $response->status() === 429 => SportsDataException::QUOTA_EXHAUSTED,
            $response->status() === 404, $response->status() === 422 => SportsDataException::NOT_FOUND,
            default => SportsDataException::UNAVAILABLE,
        };

        $message = (string) ($json['message'] ?? $response->reason());
        Log::warning('sports_data.provider_error', ['provider' => $this->name(), 'path' => $path, 'status' => $response->status(), 'reason' => $reason]);

        throw new SportsDataException($reason, "SportMonks {$response->status()} sur {$path} : ".$this->redact($message));
    }

    /**
     * Journal des appels (sports_data_api_calls) : chemin générique (identifiants et dates remplacés), compétition,
     * appels restants de l'heure. Jamais la clé ni les paramètres.
     */
    private function record(string $path, ?string $competition, Response $response): void
    {
        try {
            $endpoint = preg_replace(['#/\d{4}-\d{2}-\d{2}#', '#/\d+(?=/|$)#'], ['/{date}', '/{id}'], $path) ?? $path;

            ProviderApiCall::create([
                'provider' => $this->name(),
                'endpoint' => $endpoint,
                'competition' => $competition,
                'cost' => 0,
                'remaining' => isset($response->json()['rate_limit']['remaining']) ? (int) $response->json()['rate_limit']['remaining'] : null,
                'used' => null,
                'status' => $response->status(),
                'called_at' => CarbonImmutable::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('sports_data.api_call_not_recorded', ['provider' => $this->name(), 'path' => $path, 'error' => $this->redact($e->getMessage())]);
        }
    }

    private function redact(string $text): string
    {
        $key = (string) ($this->config['key'] ?? '');

        return $key === '' ? $text : str_replace($key, '***', $text);
    }
}
