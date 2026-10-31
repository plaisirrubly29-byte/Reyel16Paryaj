<?php

namespace App\Modules\SportsData\Models;

use App\Modules\SportsData\Enums\EventStatus;
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
    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'event_id', 'key', 'status', 'suspension_reason', 'odds_version', 'source_bookmaker', 'odds_updated_at',
        'created_at', 'updated_at', 'line',
    ];

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
     * Pendant le match, seulement si les paris en direct sont autorisés (betting.allow_live).
     *
     * @param  Builder<Market>  $query
     */
    public function scopeBettable(Builder $query): void
    {
        $query->where('status', MarketStatus::Open)
            ->whereNotNull('odds_updated_at')
            ->where(fn ($q) => $q
                ->where(fn ($u) => $u->whereHas('event', fn ($e) => $e->where('status', '!=', EventStatus::Live))
                    ->where('odds_updated_at', '>=', now()->subMinutes(self::maxAgeMinutes(false))))
                ->when(self::liveBettingAllowed(), fn ($q) => $q
                    ->orWhere(fn ($l) => $l->whereHas('event', fn ($e) => $e->where('status', EventStatus::Live))
                        ->where('odds_updated_at', '>=', now()->subMinutes(self::maxAgeMinutes(true))))));
    }

    /** Paris en direct : hors périmètre V1, désactivés par défaut (revue P8-02, E1). */
    public static function liveBettingAllowed(): bool
    {
        return (bool) config('betting.allow_live');
    }

    /** Âge maximal d'une cote pariable : plus court pendant le match (la cote bouge vite). */
    public static function maxAgeMinutes(bool $live): int
    {
        return (int) config($live ? 'sports_data.max_live_odds_age_minutes' : 'sports_data.max_odds_age_minutes');
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
        $live = $this->event?->status === EventStatus::Live;

        return $this->status === MarketStatus::Open
            && $this->odds_updated_at !== null
            && (! $live || self::liveBettingAllowed())
            && $this->odds_updated_at->gte(now()->subMinutes(self::maxAgeMinutes($live)));
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
