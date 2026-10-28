<?php

namespace App\Modules\SportsData\Models;

use App\Modules\SportsData\Enums\MarketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property string $key
 * @property string|null $line ligne du marché totals (ex. "2.5"), null pour le 1X2
 * @property MarketStatus $status
 * @property string|null $suspension_reason
 * @property int $odds_version
 * @property string|null $source_bookmaker
 * @property Carbon|null $odds_updated_at
 */
class Market extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => MarketStatus::class,
            'odds_version' => 'integer',
            'odds_updated_at' => 'datetime',
        ];
    }

    /**
     * Marchés sur lesquels on peut parier : ouverts ET dont la cote est assez récente (règle de fraîcheur).
     * Une cote trop ancienne (synchro en panne, fournisseur indisponible) n'est jamais proposée.
     *
     * @param  Builder<Market>  $query
     */
    public function scopeBettable(Builder $query): void
    {
        $query->where('status', MarketStatus::Open)
            ->whereNotNull('odds_updated_at')
            ->where('odds_updated_at', '>=', now()->subMinutes((int) config('sports_data.max_odds_age_minutes')));
    }

    /**
     * Marchés affichés au catalogue : ouverts et déjà cotés, quel que soit l'âge de la cote.
     * Une cote trop ancienne reste visible mais n'est pas pariable (isBettable).
     *
     * @param  Builder<Market>  $query
     */
    public function scopeDisplayable(Builder $query): void
    {
        $query->where('status', MarketStatus::Open)->whereNotNull('odds_updated_at');
    }

    public function isBettable(): bool
    {
        return $this->status === MarketStatus::Open
            && $this->odds_updated_at !== null
            && $this->odds_updated_at->gte(now()->subMinutes((int) config('sports_data.max_odds_age_minutes')));
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<Selection, $this> */
    public function selections(): HasMany
    {
        return $this->hasMany(Selection::class);
    }
}
