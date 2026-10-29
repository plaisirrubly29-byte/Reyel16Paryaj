<?php

namespace App\Modules\IdentityAccess\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Support\Http\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Suspension / réactivation d'un compte par un administrateur, et auto-exclusion du joueur. Seul chemin
 * qui modifie users.status ; chaque changement est audité (avec motif pour l'administration).
 */
class AccountStatusService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Auto-exclusion demandée par le joueur lui-même (jeu responsable, P7-02) : le compte ne peut plus se
     * connecter. Aucun administrateur ne peut la lever par reactivate() ; la réouverture relève de la Conformité.
     */
    public function selfExclude(User $user): User
    {
        if ($user->status !== AccountStatus::Active) {
            throw new ApiException('invalid_status_transition', __('Seul un compte actif peut être exclu.'), 409);
        }

        return DB::transaction(function () use ($user) {
            $user->forceFill(['status' => AccountStatus::Excluded, 'status_changed_at' => now()])->save();

            $this->audit->record(AuditAction::UserSelfExcluded, $user, ['status' => AccountStatus::Active->value], ['status' => AccountStatus::Excluded->value], actor: $user);

            return $user;
        });
    }

    public function suspend(User $actor, User $target, string $reason): User
    {
        $this->authorize($actor, $target);

        if ($target->status === AccountStatus::Suspended) {
            return $target; // déjà suspendu : rien à faire, rien à journaliser
        }

        if ($target->status !== AccountStatus::Active) {
            throw new ApiException('invalid_status_transition', __('Seul un compte actif peut être suspendu.'), 409);
        }

        return $this->change($actor, $target, AccountStatus::Suspended, AuditAction::UserSuspended, $reason);
    }

    public function reactivate(User $actor, User $target, string $reason): User
    {
        $this->authorize($actor, $target);

        if ($target->status === AccountStatus::Active) {
            return $target;
        }

        // L'auto-exclusion relève du jeu responsable (P7-02) : un administrateur ne peut pas la lever.
        if ($target->status !== AccountStatus::Suspended) {
            throw new ApiException('invalid_status_transition', __('Seul un compte suspendu peut être réactivé.'), 409);
        }

        return $this->change($actor, $target, AccountStatus::Active, AuditAction::UserReactivated, $reason);
    }

    private function authorize(User $actor, User $target): void
    {
        if (! $actor->hasPermission('users.suspend')) {
            throw new AuthorizationException;
        }

        if ($actor->is($target)) {
            throw new ApiException('cannot_change_own_status', __('Vous ne pouvez pas modifier le statut de votre propre compte.'), 403);
        }

        // Le compte d'un administrateur ne peut être suspendu que par un gestionnaire des rôles (Super Admin).
        if ($target->isAdmin() && ! $actor->hasPermission('roles.manage')) {
            throw new AuthorizationException;
        }
    }

    private function change(User $actor, User $target, AccountStatus $to, AuditAction $action, string $reason): User
    {
        return DB::transaction(function () use ($actor, $target, $to, $action, $reason) {
            $from = $target->status;

            $target->forceFill(['status' => $to, 'status_changed_at' => now()])->save();

            $this->audit->record($action, $target, ['status' => $from->value], ['status' => $to->value], $reason, $actor);

            return $target;
        });
    }
}
