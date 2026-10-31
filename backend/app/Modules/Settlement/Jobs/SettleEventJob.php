<?php

namespace App\Modules\Settlement\Jobs;

use App\Modules\Settlement\Services\SettlementService;
use App\Modules\SportsData\Models\Event;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Règle les tickets d'un match après l'enregistrement de son résultat (ResultRecorder).
 * Relançable sans risque : le règlement est idempotent ; un seul job par match en file à la fois.
 * En cas d'échec définitif, la commande planifiée settlement:run rattrape le match.
 */
class SettleEventJob implements ShouldBeUnique, ShouldQueue
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
