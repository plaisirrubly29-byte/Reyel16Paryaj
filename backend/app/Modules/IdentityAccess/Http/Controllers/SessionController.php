<?php

namespace App\Modules\IdentityAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Appareils connectés du titulaire du compte (V2-C06) : sessions de la table `sessions` (SESSION_DRIVER=database).
 * L'identifiant de session n'est jamais renvoyé : chaque appareil est désigné par une empreinte SHA-256 de cet
 * identifiant, inutilisable comme cookie.
 */
class SessionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $current = $request->session()->getId();

        // L'appareil en cours d'abord, puis les autres du plus récemment actif au plus ancien.
        $sessions = $this->sessionsOf($request)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $s) => [
                'id' => $this->publicId($s->id),
                'ip_address' => $s->ip_address,
                'user_agent' => $s->user_agent,
                'last_active_at' => CarbonImmutable::createFromTimestamp($s->last_activity)->toIso8601String(),
                'current' => hash_equals($current, $s->id),
            ])
            ->sortByDesc('current');

        return response()->json(['data' => $sessions->values()]);
    }

    /** Déconnecte un autre appareil. La session courante se ferme avec POST /auth/logout. */
    public function destroy(Request $request, string $session): Response
    {
        $sessionId = $this->sessionsOf($request)->pluck('id')->first(fn (string $id) => hash_equals($this->publicId($id), $session));

        if ($sessionId === null) {
            throw new ApiException('not_found', __('Appareil introuvable.'), 404);
        }

        if (hash_equals($request->session()->getId(), $sessionId)) {
            throw new ApiException('current_session', __('Utilisez la déconnexion pour fermer la session en cours.'), 409);
        }

        $this->revoke($request, $this->sessionsOf($request)->where('id', $sessionId));

        return response()->noContent();
    }

    /** Déconnecte tous les autres appareils. */
    public function destroyOthers(Request $request): Response
    {
        $this->revoke($request, $this->sessionsOf($request)->where('id', '!=', $request->session()->getId()));

        return response()->noContent();
    }

    private function revoke(Request $request, Builder $sessions): void
    {
        DB::transaction(function () use ($request, $sessions) {
            $count = $sessions->delete();

            if ($count > 0) {
                $user = $request->user();
                $this->audit->record(AuditAction::SessionsRevoked, $user, actor: $user, metadata: ['count' => $count]);
            }
        });
    }

    private function sessionsOf(Request $request): Builder
    {
        return DB::connection(config('session.connection'))
            ->table(config('session.table'))
            ->where('user_id', $request->user()->getAuthIdentifier());
    }

    private function publicId(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }
}
