<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

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

    /**
     * Réponse chiffrée en base avec APP_KEY : elle peut contenir des données personnelles (revue P8-02, F10).
     * Une réponse enregistrée en clair avant le chiffrement (purgée sous 24 h) est rendue telle quelle.
     */
    protected function responseBody(): Attribute
    {
        return Attribute::make(
            get: function (?string $value) {
                try {
                    return $value === null ? null : Crypt::decryptString($value);
                } catch (DecryptException) {
                    return $value;
                }
            },
            set: fn (?string $value) => $value === null ? null : Crypt::encryptString($value),
        );
    }

    /** Purge quotidienne : `php artisan model:prune` (planifié dans routes/console.php). */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subHours(self::RETENTION_HOURS));
    }
}
