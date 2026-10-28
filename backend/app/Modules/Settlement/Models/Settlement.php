<?php

namespace App\Modules\Settlement\Models;

use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Règlement d'un ticket : issue calculée, gain versé, écriture du ledger et versions de résultat utilisées.
 * Append-only : une réévaluation (P5-06) ajoute une révision, elle ne modifie jamais la précédente.
 *
 * @property int $id
 * @property int $bet_ticket_id
 * @property int $revision
 * @property BetTicketStatus $status
 * @property int $payout_minor
 * @property string $currency
 * @property int|null $ledger_entry_id
 * @property array<string, int> $results id du match => id de la version de résultat appliquée
 * @property Carbon $created_at
 */
class Settlement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'status' => BetTicketStatus::class,
            'payout_minor' => 'integer',
            'results' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un règlement est en ajout seul : ajouter une révision.'));
        static::deleting(fn () => throw new LogicException('Un règlement est en ajout seul : ajouter une révision.'));
    }

    public function payout(): Money
    {
        return Money::ofMinor($this->payout_minor, $this->currency);
    }

    /** @return BelongsTo<BetTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(BetTicket::class, 'bet_ticket_id');
    }

    /** @return BelongsTo<LedgerEntry, $this> */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }
}
