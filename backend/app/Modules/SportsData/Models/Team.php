<?php

namespace App\Modules\SportsData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $sport_id
 * @property string $name
 */
class Team extends Model
{
    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'sport_id', 'name', 'created_at', 'updated_at',
    ];
}
