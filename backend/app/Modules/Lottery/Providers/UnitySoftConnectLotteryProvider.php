<?php

namespace App\Modules\Lottery\Providers;

use App\Modules\Lottery\Contracts\LotteryProvider;
use App\Modules\Lottery\Data\DrawData;
use App\Modules\Lottery\Data\DrawResultData;
use App\Modules\Lottery\Exceptions\LotteryException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Connecteur de l'API Borlette UnitySoftConnect v1 (https://unityscgroup.com/pages/api-bolet.php).
 *
 * - GET /draws : tirages actifs, heures d'ouverture et de fermeture des ventes (heure d'Haïti), jours 0 = dimanche … 6.
 * - GET /results?from=&to=&page=&limit= : résultats publiés, paginés (100 au plus par page).
 *
 * Chaque tirage donne trois numéros (un de trois chiffres, deux de deux chiffres) ; l'API calcule elle-même les lots
 * de bolèt (games.bolet). L'heure de fermeture des ventes (close_time) sert d'heure du tirage : un résultat publié
 * avant elle est refusé par la synchro. Le pick 3 est le premier numéro, le pick 4 les deux autres accolés
 * (loto 4, option 2).
 *
 * Authentification : en-tête « Authorization: Bearer <clé> ». La clé n'apparaît jamais dans les journaux.
 */
class UnitySoftConnectLotteryProvider implements LotteryProvider
{
    public const DEFAULT_URL = 'https://unityscgroup.com/api/v1/borlette';

    public const DEFAULT_TIMEZONE = 'America/Port-au-Prince';

    private const PAGE_SIZE = 100;

    /** Garde-fou contre une pagination qui ne se termine pas (31 jours × quelques tirages tiennent en 2 ou 3 pages). */
    private const MAX_PAGES = 20;

    /** @param  array{url: ?string, key: ?string, timeout: int}  $config */
    public function __construct(
        private readonly Http $http,
        private readonly array $config,
    ) {}

    public function name(): string
    {
        return 'unitysoftconnect';
    }

    public function draws(): array
    {
        $payload = $this->get('/draws');
        $timezone = is_string($payload['meta']['timezone'] ?? null) ? $payload['meta']['timezone'] : self::DEFAULT_TIMEZONE;

        $draws = [];
        foreach ($this->list($payload) as $draw) {
            if (($draw['is_active'] ?? true) === false) {
                continue;
            }

            $code = (string) ($draw['code'] ?? '');
            $time = (string) ($draw['close_time'] ?? '');
            if (! preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $code) || ! preg_match('/^\d{2}:\d{2}$/D', $time)) {
                Log::warning('lottery.invalid_draw', ['provider' => $this->name(), 'code' => $code, 'close_time' => $time]);

                continue;
            }

            $draws[] = new DrawData($code, (string) ($draw['name'] ?? $code), $time, $timezone, $this->isoDays($draw['days'] ?? null));
        }

        return $draws;
    }

    public function results(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $results = [];
        $page = 1;

        do {
            $payload = $this->get('/results', [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'page' => $page,
                'limit' => self::PAGE_SIZE,
            ]);

            foreach ($this->list($payload) as $result) {
                $data = $this->result($result);
                if ($data !== null) {
                    $results[] = $data;
                }
            }

            $lastPage = (int) ($payload['meta']['last_page'] ?? 1);
            $page++;
        } while ($page <= $lastPage && $page <= self::MAX_PAGES);

        return $results;
    }

    /** @param  array<string, mixed>  $r */
    private function result(array $r): ?DrawResultData
    {
        $numbers = $r['numbers'] ?? null;
        $bolet = $r['games']['bolet'] ?? null;

        if (! is_array($numbers) || count($numbers) !== 3 || ! is_array($bolet) || ! isset($r['draw']['code'], $r['date'])) {
            Log::warning('lottery.invalid_result', ['provider' => $this->name(), 'id' => $r['id'] ?? null, 'error' => 'champs manquants']);

            return null;
        }

        [$first, $second, $third] = array_map(fn ($n) => (string) $n, array_values($numbers));
        $lots = [(string) ($bolet['lot1'] ?? ''), (string) ($bolet['lot2'] ?? ''), (string) ($bolet['lot3'] ?? '')];

        // Les lots calculés par l'API doivent correspondre aux numéros tirés : sinon la réponse est incohérente et
        // le résultat est écarté (il servira à payer des tickets).
        if (strlen($first) !== 3 || $lots !== [substr($first, -2), $second, $third]) {
            Log::warning('lottery.invalid_result', ['provider' => $this->name(), 'id' => $r['id'] ?? null, 'error' => 'lots incohérents avec les numéros']);

            return null;
        }

        try {
            $publishedAt = isset($r['published_at']) ? CarbonImmutable::parse((string) $r['published_at'])->utc() : null;
        } catch (Throwable) {
            $publishedAt = null;
        }

        return new DrawResultData(
            drawKey: (string) $r['draw']['code'],
            drawDate: (string) $r['date'],
            lot1: $lots[0],
            lot2: $lots[1],
            lot3: $lots[2],
            pick3: $first,
            pick4: $second.$third,
            publishedAt: $publishedAt,
            externalId: isset($r['id']) ? (string) $r['id'] : null,
        );
    }

    /**
     * Jours de l'API (0 = dimanche … 6 = samedi) en jours ISO (1 = lundi … 7 = dimanche) ; null = tous les jours.
     *
     * @return list<int>|null
     */
    private function isoDays(mixed $days): ?array
    {
        if (! is_array($days)) {
            return null;
        }

        $iso = [];
        foreach ($days as $day) {
            if (is_int($day) && $day >= 0 && $day <= 6) {
                $iso[] = $day === 0 ? 7 : $day;
            }
        }
        $iso = array_values(array_unique($iso));
        sort($iso);

        return count($iso) === 7 ? null : $iso;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        if (empty($this->config['key'])) {
            throw new LotteryException(LotteryException::UNAUTHORIZED, 'Clé API Borlette absente (LOTTERY_API_KEY).');
        }

        try {
            $response = $this->http
                ->baseUrl(rtrim($this->config['url'] ?: self::DEFAULT_URL, '/'))
                ->acceptJson()
                ->withToken((string) $this->config['key'])
                ->timeout($this->config['timeout'])
                ->retry(3, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->get($path, $query);
        } catch (ConnectionException $e) {
            throw new LotteryException(LotteryException::UNAVAILABLE, 'API Borlette injoignable : '.$this->redact($e->getMessage()), $e);
        }

        if ($response->successful()) {
            $json = $response->json();
            if (! is_array($json)) {
                throw new LotteryException(LotteryException::INVALID_RESPONSE, "Réponse de l'API Borlette illisible sur {$path}.");
            }

            return $json;
        }

        $reason = match ($response->status()) {
            401, 403 => LotteryException::UNAUTHORIZED,
            400, 404, 405, 422 => LotteryException::INVALID_RESPONSE,
            default => LotteryException::UNAVAILABLE, // 429 (quota du mois), 5xx
        };
        $code = (string) ($response->json('error.code') ?? '');
        $message = (string) ($response->json('error.message') ?? $response->reason());

        Log::warning('lottery.provider_error', ['provider' => $this->name(), 'path' => $path, 'status' => $response->status(), 'code' => $code]);

        throw new LotteryException($reason, "API Borlette {$response->status()} ({$code}) sur {$path} : ".$this->redact($message));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function list(array $payload): array
    {
        $data = $payload['data'] ?? null;
        if (! is_array($data) || ! array_is_list($data)) {
            throw new LotteryException(LotteryException::INVALID_RESPONSE, "Réponse de l'API Borlette inattendue (liste attendue dans data).");
        }

        return array_values(array_filter($data, 'is_array'));
    }

    private function redact(string $text): string
    {
        $key = (string) ($this->config['key'] ?? '');

        return $key === '' ? $text : str_replace($key, '***', $text);
    }
}
