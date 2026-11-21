<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Enums\LimitKind;
use App\Modules\Compliance\Enums\LimitPeriod;
use App\Modules\Compliance\Enums\RestrictionType;
use App\Modules\Compliance\Http\Resources\ResponsibleGamingPresenter;
use App\Modules\Compliance\Services\ResponsibleGamingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Jeu responsable côté joueur (P7-02) : limites de mise, de dépôt (V2-B06) et de pertes (V2-E06), pause,
 * auto-exclusion, rappel de durée de session et historique des dépenses (V2-E06).
 */
class ResponsibleGamingController extends Controller
{
    public function __construct(private readonly ResponsibleGamingService $responsibleGaming) {}

    public function show(Request $request): JsonResponse
    {
        return $this->state($request);
    }

    public function updateLimit(Request $request, string $period): JsonResponse
    {
        return $this->changeLimit($request, $period, LimitKind::Stake);
    }

    public function updateDepositLimit(Request $request, string $period): JsonResponse
    {
        return $this->changeLimit($request, $period, LimitKind::Deposit);
    }

    public function updateLossLimit(Request $request, string $period): JsonResponse
    {
        return $this->changeLimit($request, $period, LimitKind::Loss);
    }

    public function updateSessionReminder(Request $request): JsonResponse
    {
        $data = $request->validate([
            // null : rappel désactivé.
            'minutes' => ['present', 'nullable', 'integer', Rule::in(config('compliance.session_reminder_minutes'))],
        ]);

        $this->responsibleGaming->setSessionReminder($request->user(), $data['minutes']);

        return $this->state($request);
    }

    public function history(Request $request): JsonResponse
    {
        $history = $this->responsibleGaming->spendingHistory($request->user());
        $money = fn (array $row) => collect($row)->map(fn ($value, string $key) => in_array($key, ['date', 'month'], true)
            ? $value
            : ['minor' => $value, 'currency' => (string) config('wallet.currency')]);

        return response()->json(['data' => [
            'timezone' => $history['timezone'],
            'days' => array_map($money, $history['days']),
            'months' => array_map($money, $history['months']),
        ]]);
    }

    private function changeLimit(Request $request, string $period, LimitKind $kind): JsonResponse
    {
        $data = validator(['period' => $period] + $request->all(), [
            'period' => ['required', Rule::enum(LimitPeriod::class)],
            // null : supprimer la limite (après le délai de réflexion).
            'amount_minor' => ['present', 'nullable', 'integer', 'min:1', 'max:'.config('compliance.limits.max_amount_minor')],
        ])->validate();

        $this->responsibleGaming->setLimit($request->user(), LimitPeriod::from($data['period']), $data['amount_minor'], $kind);

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

        return response()->json(['data' => [
            'limits' => ResponsibleGamingPresenter::limits($status['limits']),
            'deposit_limits' => ResponsibleGamingPresenter::limits($status['deposit_limits']),
            'loss_limits' => ResponsibleGamingPresenter::limits($status['loss_limits']),
            'pause' => ResponsibleGamingPresenter::pause($status['pause']),
            'session' => ResponsibleGamingPresenter::session($status['session_reminder_minutes'], $request),
            'options' => [
                'pause_durations' => array_keys(RestrictionType::Pause->durations()),
                'exclusion_durations' => array_keys(RestrictionType::SelfExclusion->durations()),
                'limit_increase_delay_days' => (int) config('compliance.limits.increase_delay_days'),
                'session_reminder_minutes' => config('compliance.session_reminder_minutes'),
            ],
        ]]);
    }
}
