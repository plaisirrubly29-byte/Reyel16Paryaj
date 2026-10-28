<?php

namespace App\Modules\Audit\Models;

use App\Modules\Audit\Enums\AuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Entrée du journal d'audit. Append-only : toute modification ou suppression lève une exception
 * (et un trigger l'interdit aussi en base sous PostgreSQL).
 *
 * Écrire uniquement via App\Modules\Audit\Services\AuditLogger.
 *
 * @property int $id
 * @property AuditAction $action
 * @property int|null $actor_id
 * @property string|null $actor_email
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $reason
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'before' => 'array',
            'after' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException("Le journal d'audit est en ajout seul."));
        static::deleting(fn () => throw new LogicException("Le journal d'audit est en ajout seul."));
    }
}
