<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Compliance\Http\Resources\ResponsibleGamingPresenter;
use App\Modules\Compliance\Models\ResponsibleGamingRestriction;
use App\Modules\Compliance\Models\ResponsiblePlayEvent;
use App\Modules\Compliance\Services\ResponsibleGamingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Jeu responsable d'un joueur vu par la Conformité (P7-02, partie 2) : consulter (responsible_gaming.view,
 * consultation auditée) ; rouvrir une auto-exclusion échue à la demande du joueur (responsible_gaming.manage).
 */
class AdminResponsibleGamingController extends Controller
{
    public function __construct(private readonly ResponsibleGamingService $responsibleGaming) {}

    public function show(User $user, AuditLogger $audit): JsonResponse
    {
        // Comportement de jeu d'un joueur : donnée sensible, consultation journalisée.
        $audit->record(AuditAction::ResponsibleGamingViewed, $user);

        return $this->overview($user);
    }

    public function reopen(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        $this->responsibleGaming->liftSelfExclusion($request->user(), $user, trim($data['reason']));

        return $this->overview($user->refresh());
    }

    private function overview(User $user): JsonResponse
    {
        $overview = $this->responsibleGaming->overview($user);

        return response()->json(['data' => [
            'user' => ['id' => (string) $user->id, 'status' => $user->status->value],
            'limits' => ResponsibleGamingPresenter::limits($overview['limits']),
            'pause' => ResponsibleGamingPresenter::pause($overview['pause']),
            'restrictions' => $overview['restrictions']->map(fn (ResponsibleGamingRestriction $r) => [
                'id' => (string) $r->id,
                'type' => $r->type->value,
                'duration' => $r->duration,
                'starts_at' => $r->starts_at->toIso8601String(),
                'ends_at' => $r->ends_at->toIso8601String(),
                'ended' => $r->ends_at->isPast(),
                'lifted' => $r->lifted_at === null ? null : [
                    'at' => $r->lifted_at->toIso8601String(),
                    'by' => (string) $r->lifted_by,
                    'reason' => $r->lift_reason,
                ],
            ])->values(),
            'events' => $overview['events']->map(fn (ResponsiblePlayEvent $e) => [
                'type' => $e->type->value,
                'data' => $e->data,
                'actor_id' => $e->actor_id === null ? null : (string) $e->actor_id,
                'created_at' => $e->created_at->toIso8601String(),
            ])->values(),
        ]]);
    }
}
