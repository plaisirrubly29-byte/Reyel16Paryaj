<?php

namespace App\Modules\Betting\Models;

use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Models\Selection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Sélection d'un ticket : instantané du match, du marché et de la cote acceptés.
 * Le catalogue peut changer ou supprimer la sélection d'origine, l'instantané reste la référence.
 *
 * @property int $id
 * @property int $bet_ticket_id
 * @property int $event_id
 * @property int $market_id
 * @property int|null $selection_id null si le fournisseur a retiré la sélection du catalogue
 * @property string $event_name
 * @property Carbon $event_starts_at
 * @property string $market_key
 * @property string|null $market_line
 * @property string $selection_key
 * @property string $selection_name
 * @property string $odds cote acceptée (chaîne, ex. "2.050")
 * @property int $odds_version version de cote du marché au moment de l'acceptation
 * @property BetSelectionStatus $status
 * @property Carbon|null $settled_at
 */
class BetSelection extends Model
{
    /** Ce qui a été accepté : seuls le statut et la date de règlement évoluent. */
    private const FROZEN = [
        'bet_ticket_id', 'event_id', 'market_id', 'event_name', 'event_starts_at', 'market_key',
        'market_line', 'selection_key', 'selection_name', 'odds', 'odds_version',
    ];

    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'bet_ticket_id', 'event_id', 'market_id', 'selection_id', 'event_name', 'event_starts_at', 'market_key',
        'market_line', 'selection_key', 'selection_name', 'odds', 'odds_version', 'status', 'settled_at',
        'created_at', 'updated_at', 'result_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'event_starts_at' => 'datetime',
            'odds' => 'decimal:3',
            'odds_version' => 'integer',
            'status' => BetSelectionStatus::class,
            'settled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $selection) {
            if ($selection->isDirty(self::FROZEN)) {
                throw new LogicException('La cote et la sélection acceptées d\'un ticket sont immuables.');
            }
        });

        static::deleting(fn () => throw new LogicException('Une sélection de ticket ne peut pas être supprimée.'));
    }

    /** @return BelongsTo<BetTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(BetTicket::class, 'bet_ticket_id');
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Market, $this> */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /** @return BelongsTo<Selection, $this> */
    public function selection(): BelongsTo
    {
        return $this->belongsTo(Selection::class);
    }
}
