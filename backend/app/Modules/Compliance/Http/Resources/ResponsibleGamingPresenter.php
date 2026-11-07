<?php

namespace App\Modules\Compliance\Http\Resources;

use App\Modules\Compliance\Models\ResponsibleGamingRestriction;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionLifetime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Format JSON commun au joueur et à la Conformité : limites (montants en Money) et pause. */
final class ResponsibleGamingPresenter
{
    /**
     * @param  array<string, array<string, mixed>>  $limits  ResponsibleGamingService::status()['limits']
     * @return Collection<string, array<string, mixed>>
     */
    public static function limits(array $limits): Collection
    {
        $money = fn (?int $minor) => $minor === null ? null : ['minor' => $minor, 'currency' => (string) config('wallet.currency')];

        return collect($limits)->map(fn (array $limit) => [
            'amount' => $money($limit['amount_minor']),
            'used' => $money($limit['used_minor']),
            'remaining' => $money($limit['remaining_minor']),
            'pending' => $limit['pending'] === null ? null : [
                'amount' => $money($limit['pending']['amount_minor']),
                'effective_at' => $limit['pending']['effective_at']->toIso8601String(),
            ],
        ]);
    }

    /** @return array{active: bool, ends_at: ?string} */
    public static function pause(?ResponsibleGamingRestriction $pause): array
    {
        return ['active' => $pause !== null, 'ends_at' => $pause?->ends_at->toIso8601String()];
    }

    /**
     * Rappel de durée de session (V2-E06) : l'écran affiche un rappel toutes les reminder_minutes depuis started_at
     * (heure de la connexion, posée par EnforceSessionLifetime ; null hors session, ex. vue de la Conformité).
     *
     * @return array{reminder_minutes: ?int, started_at: ?string}
     */
    public static function session(?int $reminderMinutes, ?Request $request = null): array
    {
        $startedAt = $request?->hasSession() ? $request->session()->get(EnforceSessionLifetime::STARTED_AT) : null;

        return [
            'reminder_minutes' => $reminderMinutes,
            'started_at' => is_int($startedAt) ? CarbonImmutable::createFromTimestamp($startedAt)->toIso8601String() : null,
        ];
    }
}
