<?php

namespace App\Modules\Betting\Jobs;

use App\Modules\Betting\Services\LiveAcceptanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Revérifie un pari en direct à la fin de son délai d'acceptation (D-20). Envoyée avec ce délai à la création du
 * ticket ; si la file est en retard ou en panne, betting:live-acceptance prend le relais (et refuse passé le délai
 * maximal). Sans effet sur un ticket déjà traité.
 */
class ProcessLiveTicket implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $ticketId) {}

    public function handle(LiveAcceptanceService $acceptance): void
    {
        $acceptance->process($this->ticketId);
    }
}
