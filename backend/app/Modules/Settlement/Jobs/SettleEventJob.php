<?php

namespace App\Modules\Settlement\Jobs;

use App\Modules\Settlement\Services\SettlementService;
use App\Modules\SportsData\Models\Event;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Règle les tickets d'un match après l'enregistrement de son résultat (ResultRecorder).
 * Relançable sans risque : le règlement est idempotent ; un seul job par match EN ATTENTE dans la file.
 * Unique jusqu'au début de son exécution seulement (audit du 18/11/2026, constat n° 6) : le job envoyé par une
 * correction de résultat pendant qu'un règlement tourne n'est plus ignoré ; il passe juste après.
 * En cas d'échec définitif, la commande planifiée settlement:run rattrape le match.
 */
class SettleEventJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> secondes entre deux essais */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $eventId) {}

    public function uniqueId(): string
    {
        return (string) $this->eventId;
    }

    public function handle(SettlementService $settlement): void
    {
        $event = Event::find($this->eventId);
        if ($event === null) {
            return;
        }

        $report = $settlement->settleEvent($event);

        Log::info('settlement.event_settled', ['event_id' => $this->eventId, ...$report->toArray()]);
    }
}
