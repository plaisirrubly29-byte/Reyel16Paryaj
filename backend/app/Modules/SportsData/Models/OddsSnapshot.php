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
 */
class OddsSnapshot extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'recorded_at';

    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'selection_id', 'odds_version', 'odds', 'provider_updated_at', 'recorded_at', 'line',
    ];

    protected function casts(): array
    {
        return [
            'odds' => 'decimal:3',
            'provider_updated_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }
}
