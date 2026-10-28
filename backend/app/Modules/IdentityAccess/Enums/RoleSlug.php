<?php

namespace App\Modules\IdentityAccess\Enums;

/**
 * Rôles administratifs V1 (docs/permissions-matrix.md, décision D-07 / P0-06).
 * Un joueur n'a aucun rôle.
 */
enum RoleSlug: string
{
    case SuperAdmin = 'super_admin';
    case Operations = 'operations';
    case Finance = 'finance';
    case Support = 'support';
    case Compliance = 'compliance';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Operations => 'Opérations',
            self::Finance => 'Finance',
            self::Support => 'Support',
            self::Compliance => 'Conformité',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Administration complète ; ne peut pas modifier ses propres rôles.',
            self::Operations => 'Événements, marchés et résultats.',
            self::Finance => 'Ledger, rapprochement, règlements et corrections financières.',
            self::Support => 'Assistance aux joueurs avec données minimales.',
            self::Compliance => 'Conformité, jeu responsable et audit.',
        };
    }
}
