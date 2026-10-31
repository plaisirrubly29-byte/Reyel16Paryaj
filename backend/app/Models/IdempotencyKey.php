<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Réponse mémorisée pour une clé d'idempotence (voir RequireIdempotencyKey).
 *
 * @property string $scope
 * @property string $key
 * @property string $fingerprint
 * @property string $status
 * @property int|null $response_status
 * @property string|null $response_body
 * @property string|null $response_content_type
 */
class IdempotencyKey extends Model
{
    use MassPrunable;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    /** Durée de conservation d'une clé (heures). */
    public const RETENTION_HOURS = 24;

    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'scope', 'key', 'method', 'path', 'fingerprint', 'status', 'response_status', 'response_body',
        'response_content_type', 'created_at', 'updated_at',
    ];

    /** Purge quotidienne : `php artisan model:prune` (planifié dans routes/console.php). */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subHours(self::RETENTION_HOURS));
    }
}
