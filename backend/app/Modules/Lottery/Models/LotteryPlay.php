<?php

namespace App\Modules\Lottery\Models;

use App\Modules\Lottery\Enums\LotteryTicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'une fiche de borlette : un jeu, ses numéros, sa mise et le barème figé à la vente.
 *
 * @property int $id
 * @property int $lottery_ticket_id
 * @property int $position
 * @property string $game
 * @property int|null $option
 * @property string $number1
 * @property string|null $number2
 * @property int $stake_minor
 * @property array<int|string, string> $multipliers
 * @property int $max_payout_minor
 * @property LotteryTicketStatus $status
 * @property int|null $winning_lot
 * @property int|null $payout_minor
 */
class LotteryPlay extends Model
{
    protected $fillable = [
        'lottery_ticket_id', 'position', 'game', 'option', 'number1', 'number2', 'stake_minor', 'multipliers',
        'max_payout_minor', 'status', 'winning_lot', 'payout_minor',
    ];

    protected function casts(): array
    {
        return [
            'option' => 'integer',
            'stake_minor' => 'integer',
            'multipliers' => 'array',
            'max_payout_minor' => 'integer',
            'status' => LotteryTicketStatus::class,
            'winning_lot' => 'integer',
            'payout_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<LotteryTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(LotteryTicket::class, 'lottery_ticket_id');
    }

    /** @return list<string> */
    public function numbers(): array
    {
        return $this->number2 === null ? [$this->number1] : [$this->number1, $this->number2];
    }
}
