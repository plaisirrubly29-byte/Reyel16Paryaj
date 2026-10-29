<?php

namespace App\Modules\IdentityAccess\Console;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\IdentityAccess\Models\Role;
use App\Modules\IdentityAccess\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Donne un rôle administratif à un compte existant, depuis le serveur.
 *
 * Sert à nommer le premier Super Admin (aucun compte n'a encore roles.manage) ou à dépanner un accès perdu.
 * Le compte est créé par la personne elle-même (inscription sur le site, mot de passe choisi par elle) :
 * la commande ne crée ni ne lit aucun mot de passe. MFA exigé à la prochaine connexion. Audité (roles.updated).
 */
class GrantRoleCommand extends Command
{
    protected $signature = 'identity:grant-role
        {login : Téléphone (+509…) ou email du compte}
        {role : super_admin, operations, finance, support ou compliance}
        {--reason= : Motif (obligatoire, audité)}';

    protected $description = 'Donne un rôle administratif à un compte existant (premier Super Admin, dépannage)';

    public function handle(AuditLogger $audit): int
    {
        $role = RoleSlug::tryFrom((string) $this->argument('role'));
        if ($role === null) {
            $this->error('Rôle inconnu. Rôles possibles : '.implode(', ', array_column(RoleSlug::cases(), 'value')).'.');

            return self::INVALID;
        }

        $login = trim((string) $this->argument('login'));
        $user = str_contains($login, '@')
            ? User::where('email', $login)->first()
            : User::where('phone', PhoneNumber::normalize($login))->first();

        if ($user === null) {
            $this->error("Aucun compte pour « {$login} ». La personne doit d'abord s'inscrire sur le site.");

            return self::FAILURE;
        }

        if ($user->status !== AccountStatus::Active) {
            $this->error("Le compte de {$user->name} n'est pas actif ({$user->status->value}).");

            return self::FAILURE;
        }

        if ($user->roles()->where('slug', $role->value)->exists()) {
            $this->info("{$user->name} a déjà le rôle {$role->value}.");

            return self::SUCCESS;
        }

        $reason = trim((string) ($this->option('reason') ?? ''));
        if (mb_strlen($reason) < 5) {
            $reason = trim((string) $this->ask('Motif (5 caractères au moins, enregistré dans le journal d\'audit)'));
        }
        if (mb_strlen($reason) < 5) {
            $this->error('Motif obligatoire (5 caractères au moins).');

            return self::INVALID;
        }

        DB::transaction(function () use ($user, $role, $reason, $audit) {
            $before = $user->roles()->pluck('slug')->sort()->values()->all();
            $user->roles()->attach(Role::where('slug', $role->value)->value('id'), ['assigned_by' => null]);
            $after = $user->roles()->pluck('slug')->sort()->values()->all();

            $audit->record(AuditAction::RolesUpdated, $user, ['roles' => $before], ['roles' => $after], $reason, metadata: ['source' => 'console']);
        });

        $this->info("Rôle {$role->value} donné à {$user->name} (#{$user->id}).");
        $this->line('À la prochaine connexion, la double authentification (MFA) sera demandée avant l\'administration.');

        return self::SUCCESS;
    }
}
