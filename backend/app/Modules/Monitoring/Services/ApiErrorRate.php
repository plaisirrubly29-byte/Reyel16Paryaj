<?php

namespace App\Modules\Monitoring\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Compteurs des réponses de l'API par minute (total et 5xx), dans le cache partagé (Redis) : lus par
 * monitoring:check pour l'alerte « erreurs API ». Gardés deux heures ; jamais bloquants pour la requête.
 */
class ApiErrorRate
{
    private const PREFIX = 'monitoring:api:';

    private const TTL_SECONDS = 7200;

    public function record(int $status): void
    {
        $minute = now()->format('YmdHi');

        try {
            $this->increment(self::PREFIX.$minute.':total');
            if ($status >= 500) {
                $this->increment(self::PREFIX.$minute.':errors');
            }
        } catch (Throwable $e) {
            // Le cache indisponible ne doit jamais faire échouer une réponse : l'alerte « cache » viendra d'ailleurs.
            Log::warning('monitoring.counter_failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return array{total: int, errors: int, rate: float} sur les $minutes dernières minutes, minute en cours comprise */
    public function lastMinutes(int $minutes): array
    {
        $total = 0;
        $errors = 0;
        for ($i = 0; $i < $minutes; $i++) {
            $minute = now()->subMinutes($i)->format('YmdHi');
            $total += (int) Cache::get(self::PREFIX.$minute.':total', 0);
            $errors += (int) Cache::get(self::PREFIX.$minute.':errors', 0);
        }

        return ['total' => $total, 'errors' => $errors, 'rate' => $total > 0 ? $errors / $total : 0.0];
    }

    private function increment(string $key): void
    {
        // add() ne pose la durée de vie qu'à la création ; increment() ne la change pas.
        Cache::add($key, 0, self::TTL_SECONDS);
        Cache::increment($key);
    }
}
