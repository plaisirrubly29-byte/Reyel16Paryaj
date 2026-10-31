<?php

namespace App\Modules\Compliance\Models;

use App\Modules\Compliance\Enums\ResponsiblePlayEventType;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Journal des événements de jeu responsable (P7-02), en ajout seul.
 *
 * @property int $id
 * @property int $user_id
 * @property ResponsiblePlayEventType $type
 * @property array<string, mixed>|null $data
 * @property int|null $actor_id null : action du joueur lui-même ou du système
 */
class ResponsiblePlayEvent extends Model
{
    public const UPDATED_AT = null;

    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'user_id', 'type', 'data', 'actor_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ResponsiblePlayEventType::class,
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal de jeu responsable est en ajout seul.'));
        static::deleting(fn () => throw new LogicException('Le journal de jeu responsable est en ajout seul.'));
    }
}
