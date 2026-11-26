<?php

namespace App\Modules\Settlement\Models;

use App\Models\User;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Settlement\Enums\TicketVoidStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Demande d'annulation d'un ticket (erreur de cote, fraude), à double validation (audit du 2026-11-22, A2).
 *
 * @property int $id
 * @property int $bet_ticket_id
 * @property TicketVoidStatus $status
 * @property BetTicketStatus $ticket_status statut du ticket au moment de la demande
 * @property string $reason
 * @property int $requested_by
 * @property int|null $decided_by
 * @property string|null $decision_reason
 * @property Carbon|null $decided_at
 * @property array<string, mixed>|null $impact
 */
class TicketVoid extends Model
{
    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'bet_ticket_id', 'status', 'ticket_status', 'reason', 'requested_by', 'decided_by', 'decision_reason',
        'decided_at', 'impact', 'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketVoidStatus::class,
            'ticket_status' => BetTicketStatus::class,
            'decided_at' => 'datetime',
            'impact' => 'array',
        ];
    }

    /** @return BelongsTo<BetTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(BetTicket::class, 'bet_ticket_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
