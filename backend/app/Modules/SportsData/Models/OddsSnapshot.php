<?php

namespace App\Modules\SportsData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historique des cotes : une ligne par sélection et par version de cote du marché.
 *
 * @property int $selection_id
 * @property int $odds_version
 * @property string $odds
 */
class OddsSnapshot extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'recorded_at';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'odds' => 'decimal:3',
            'provider_updated_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }
}
