<?php

namespace App\Modules\Monitoring\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Signal de vie du travailleur de file (queue:work), écrit à chaque tour de sa boucle (événement Queue::looping),
 * au plus une fois toutes les BEAT_EVERY_SECONDS secondes. Sans signal récent, monitoring:check alerte : le
 * règlement et les autres tâches en file ne tournent plus.
 */
class QueueHeartbeat
{
    public const KEY = 'monitoring:queue:heartbeat';

    public const MAX_AGE_MINUTES = 5;

    private const BEAT_EVERY_SECONDS = 30;

    private ?int $lastBeat = null;

    public function beat(): void
    {
        $now = now()->getTimestamp();
        if ($this->lastBeat !== null && $now - $this->lastBeat < self::BEAT_EVERY_SECONDS) {
            return;
        }

        Cache::forever(self::KEY, now()->toIso8601String());
        $this->lastBeat = $now;
    }

    public function last(): ?CarbonImmutable
    {
        $at = Cache::get(self::KEY);

        return is_string($at) ? CarbonImmutable::parse($at) : null;
    }
}
