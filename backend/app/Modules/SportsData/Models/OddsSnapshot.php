<?php

namespace App\Modules\SportsData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historique des cotes : une ligne par sélection et par version de cote du marché.
 *
 * @property int $selection_id
 * @property int $odds_version
 * @property string $odds
 * @property string|null $line ligne du marché à cette version (totals), null pour le 1X2
 * @property string|null $computed_odds cote calculée à cette version (V2-D03)
 * @property string|null $provider_odds cote du bookmaker de référence à cette version
 * @property bool $adjusted cote fixée à la main à cette version
 */
class OddsSnapshot extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'recorded_at';

    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'selection_id', 'odds_version', 'odds', 'provider_updated_at', 'recorded_at', 'line',
        'computed_odds', 'provider_odds', 'adjusted',
    ];

    protected function casts(): array
    {
        return [
            'odds' => 'decimal:3',
            'computed_odds' => 'decimal:3',
            'provider_odds' => 'decimal:3',
            'adjusted' => 'boolean',
            'provider_updated_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }
}
