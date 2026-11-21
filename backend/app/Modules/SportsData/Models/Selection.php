<?php

namespace App\Modules\SportsData\Models;

use App\Modules\SportsData\Enums\OddsOverrideStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $market_id
 * @property string $key
 * @property string $name
 * @property string $odds cote proposée aux joueurs (chaîne, ex. "2.050") : calculée, ou fixée à la main (V2-D03)
 * @property string|null $computed_odds cote calculée par la synchronisation, avant toute cote fixée à la main
 * @property string|null $provider_odds cote du bookmaker de référence (null : marché maison)
 * @property string|null $best_odds meilleure cote parmi les bookmakers suivis (null : marché maison)
 * @property bool $adjusted cote fixée à la main en vigueur (OddsOverride active) : la synchronisation ne l'écrase pas
 * @property string|null $result
 */
class Selection extends Model
{
    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'market_id', 'key', 'name', 'odds', 'result', 'created_at', 'updated_at',
        'computed_odds', 'provider_odds', 'best_odds', 'adjusted',
    ];

    protected function casts(): array
    {
        return [
            'odds' => 'decimal:3',
            'computed_odds' => 'decimal:3',
            'provider_odds' => 'decimal:3',
            'best_odds' => 'decimal:3',
            'adjusted' => 'boolean',
        ];
    }

    /**
     * Cote fixée à la main en attente ou en vigueur (une seule à la fois, D-18).
     *
     * @return HasOne<OddsOverride, $this>
     */
    public function openOverride(): HasOne
    {
        return $this->hasOne(OddsOverride::class)->ofMany(['id' => 'max'],
            fn ($q) => $q->whereIn('status', [OddsOverrideStatus::Pending, OddsOverrideStatus::Active]));
    }

    /** @return BelongsTo<Market, $this> */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /** @return HasMany<OddsSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(OddsSnapshot::class);
    }
}
