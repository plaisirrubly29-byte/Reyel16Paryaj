<?php

namespace App\Modules\SportsData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property bool $is_active
 */
class Sport extends Model
{
    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'slug', 'name', 'is_active', 'sort_order', 'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Competition, $this> */
    public function competitions(): HasMany
    {
        return $this->hasMany(Competition::class);
    }
}
