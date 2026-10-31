<?php

namespace App\Modules\Admin\Http\Resources;

use App\Modules\Admin\Models\SettingVersion;
use App\Modules\Admin\Services\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Version des paramètres : changements par rapport à la précédente, auteur et motif.
 * Charger author pour éviter une requête par version.
 *
 * @mixin SettingVersion
 */
class SettingVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'version' => $this->version,
            'changes' => array_map(fn (string $key, array $change) => [
                'key' => $key,
                'label' => PlatformSettings::DEFINITIONS[$key]['label'] ?? $key,
                'type' => PlatformSettings::DEFINITIONS[$key]['type'] ?? null,
                'from' => $change['from'],
                'to' => $change['to'],
            ], array_keys($this->changes), $this->changes),
            'reason' => $this->reason,
            'created_by' => ['id' => (string) $this->created_by, 'name' => $this->author?->name],
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
