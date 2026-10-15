<?php

namespace App\Modules\IdentityAccess\Services;

use App\Models\User;
use App\Modules\IdentityAccess\Models\Role;
use App\Support\Http\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Attribution des rôles administratifs.
 *
 * Protections contre l'auto-élévation :
 * - permission roles.manage obligatoire ;
 * - un administrateur ne modifie jamais ses propres rôles ;
 * - les permissions d'un rôle ne sont modifiables que dans le code (config/permissions.php).
 */
class RoleAssignmentService
{
    /**
     * Remplace les rôles de $target.
     *
     * @param  list<string>  $roleSlugs
     * @return array{before: list<string>, after: list<string>}
     */
    public function sync(User $actor, User $target, array $roleSlugs, string $reason): array
    {
        if (! $actor->hasPermission('roles.manage')) {
            throw new AuthorizationException;
        }

        if ($actor->is($target)) {
            throw new ApiException('cannot_modify_own_roles', __('Vous ne pouvez pas modifier vos propres rôles.'), 403);
        }

        return DB::transaction(function () use ($actor, $target, $roleSlugs) {
            $before = $target->roles()->pluck('slug')->sort()->values()->all();

            $wanted = Role::whereIn('slug', $roleSlugs)->pluck('id');
            $current = $target->roles()->pluck('roles.id');

            // Les rôles conservés gardent leur "assigned_by" d'origine.
            $target->roles()->detach($current->diff($wanted)->all());
            $target->roles()->attach($wanted->diff($current)->all(), ['assigned_by' => $actor->id]);
            $target->unsetRelation('roles');

            $after = $target->roles()->pluck('slug')->sort()->values()->all();

            return ['before' => $before, 'after' => $after];
        });
    }
}
