<?php

namespace App\Modules\Compliance\Jobs;

use App\Models\User;
use App\Modules\Compliance\Enums\AmlMovement;
use App\Modules\Compliance\Services\AmlMonitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Contrôle AML d'un dépôt ou d'un retrait, envoyé par le ledger après la validation de l'écriture (V2-C04).
 * En file : le mouvement d'argent n'attend pas le contrôle et n'est jamais bloqué par lui.
 * Relançable sans risque : une seule alerte par règle et par écriture.
 */
class CheckAmlRules implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> secondes entre deux essais */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly int $userId,
        public readonly int $ledgerEntryId,
        public readonly AmlMovement $movement,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly Carbon $occurredAt,
    ) {}

    public function handle(AmlMonitor $monitor): void
    {
        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        $monitor->check($user, $this->ledgerEntryId, $this->movement, $this->amountMinor, $this->currency, $this->occurredAt);
    }
}
