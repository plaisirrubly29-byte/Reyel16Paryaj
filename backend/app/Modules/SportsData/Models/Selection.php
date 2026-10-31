<?php

namespace App\Modules\SportsData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $market_id
 * @property string $key
 * @property string $name
 * @property string $odds cote décimale (chaîne, ex. "2.050")
 * @property string|null $result
 */
class Selection extends Model
{
    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'market_id', 'key', 'name', 'odds', 'result', 'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return ['odds' => 'decimal:3'];
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
