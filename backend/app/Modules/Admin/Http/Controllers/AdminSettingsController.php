<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Resources\SettingVersionResource;
use App\Modules\Admin\Models\SettingVersion;
use App\Modules\Admin\Services\PlatformSettings;
use App\Support\Http\Pagination;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;

/**
 * Paramètres de la plateforme versionnés (P6-08) : consultation (settings.view), modification (settings.update) ;
 * plafonds de risque seulement avec risk.manage (Risk & Fraud, V2-G03).
 */
class AdminSettingsController extends Controller
{
    public function show(PlatformSettings $settings): JsonResponse
    {
        return response()->json(['data' => $this->present($settings)]);
    }

    public function versions(Request $request): AnonymousResourceCollection
    {
        $versions = SettingVersion::query()
            ->with('author:id,name')
            ->orderByDesc('version')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return SettingVersionResource::collection($versions);
    }

    public function update(Request $request, PlatformSettings $settings): JsonResponse
    {
        $data = $request->validate([
            ...PlatformSettings::rules(),
            'base_version' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        // { betting: { min_stake_minor: … } } → { "betting.min_stake_minor": … } ; les clés inconnues sont refusées.
        $values = Arr::dot((array) $request->input('values'));

        // Sans settings.update (route : settings.update ou risk.manage), seuls les plafonds de risque sont modifiables.
        if (! $request->user()->hasPermission('settings.update') && array_diff(array_keys($values), PlatformSettings::riskKeys()) !== []) {
            throw new AuthorizationException;
        }

        $settings->update($request->user(), $values, $data['reason'], (int) $data['base_version']);

        return response()->json(['data' => $this->present($settings)]);
    }

    /** @return array<string, mixed> */
    private function present(PlatformSettings $settings): array
    {
        $current = $settings->current();

        return [
            'version' => $current?->version ?? 0,
            'updated_at' => $current?->created_at->toIso8601String(),
            'updated_by' => $current === null ? null : ['id' => (string) $current->created_by, 'name' => $current->author?->name],
            'items' => $settings->items(),
        ];
    }
}
