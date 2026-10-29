<?php

namespace App\Modules\Compliance\Http\Resources;

use App\Modules\Compliance\Models\ResponsibleGamingRestriction;
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
}
