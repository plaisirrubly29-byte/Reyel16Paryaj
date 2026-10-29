<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Enums\LimitPeriod;
use App\Modules\Compliance\Enums\RestrictionType;
use App\Modules\Compliance\Services\ResponsibleGamingService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** Jeu responsable côté joueur (P7-02) : limites de mise, pause, auto-exclusion. */
class ResponsibleGamingController extends Controller
{
    public function __construct(private readonly ResponsibleGamingService $responsibleGaming) {}

    public function show(Request $request): JsonResponse
    {
        return $this->state($request);
    }

    public function updateLimit(Request $request, string $period): JsonResponse
    {
        $data = validator(['period' => $period] + $request->all(), [
            'period' => ['required', Rule::enum(LimitPeriod::class)],
            // null : supprimer la limite (après le délai de réflexion).
            'amount_minor' => ['present', 'nullable', 'integer', 'min:1', 'max:'.config('compliance.limits.max_amount_minor')],
        ])->validate();

        $this->responsibleGaming->setLimit($request->user(), LimitPeriod::from($data['period']), $data['amount_minor']);

        return $this->state($request);
    }

    public function pause(Request $request): JsonResponse
    {
        $data = $request->validate([
            'duration' => ['required', Rule::in(array_keys(RestrictionType::Pause->durations()))],
        ]);

        $this->responsibleGaming->pause($request->user(), $data['duration']);

        return $this->state($request);
    }

    public function selfExclude(Request $request): JsonResponse
    {
        $data = $request->validate([
            'duration' => ['required', Rule::in(array_keys(RestrictionType::SelfExclusion->durations()))],
            'password' => ['required', 'string'],
        ]);

        $exclusion = $this->responsibleGaming->selfExclude($request->user(), $data['duration'], $data['password']);

        // Le compte ne peut plus se connecter : la session en cours est fermée tout de suite.
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['data' => [
            'excluded_until' => $exclusion->ends_at->toIso8601String(),
            'reopening' => 'on_request', // à l'échéance, réouverture sur demande auprès du support
        ]]);
    }

    private function state(Request $request): JsonResponse
    {
        $status = $this->responsibleGaming->status($request->user());
        $currency = (string) config('wallet.currency');
        $money = fn (?int $minor) => $minor === null ? null : ['minor' => $minor, 'currency' => $currency];

        return response()->json(['data' => [
            'limits' => collect($status['limits'])->map(fn (array $limit) => [
                'amount' => $money($limit['amount_minor']),
                'used' => $money($limit['used_minor']),
                'remaining' => $money($limit['remaining_minor']),
                'pending' => $limit['pending'] === null ? null : [
                    'amount' => $money($limit['pending']['amount_minor']),
                    'effective_at' => $limit['pending']['effective_at']->toIso8601String(),
                ],
            ]),
            'pause' => [
                'active' => $status['pause'] !== null,
                'ends_at' => $status['pause']?->ends_at instanceof CarbonInterface ? $status['pause']->ends_at->toIso8601String() : null,
            ],
            'options' => [
                'pause_durations' => array_keys(RestrictionType::Pause->durations()),
                'exclusion_durations' => array_keys(RestrictionType::SelfExclusion->durations()),
                'limit_increase_delay_days' => (int) config('compliance.limits.increase_delay_days'),
            ],
        ]]);
    }
}
