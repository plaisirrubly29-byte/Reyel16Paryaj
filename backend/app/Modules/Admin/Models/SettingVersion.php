<?php

namespace App\Modules\Admin\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Version des paramètres de la plateforme (append-only). Écrire uniquement via
 * App\Modules\Admin\Services\PlatformSettings.
 *
 * @property int $id
 * @property int $version
 * @property array<string, mixed> $values
 * @property array<string, array{from: mixed, to: mixed}> $changes
 * @property string $reason
 * @property int $created_by
 * @property Carbon $created_at
 */
class SettingVersion extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'values' => 'array',
            'changes' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
