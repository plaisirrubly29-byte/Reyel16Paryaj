<?php

namespace App\Modules\IdentityAccess\Enums;

/**
 * Rôles administratifs (docs/permissions-matrix.md, décision D-07 / P0-06 ; Risk & Fraud et Gestion des agents : V2-G03).
 * Un joueur n'a aucun rôle.
 */
enum RoleSlug: string
{
    case SuperAdmin = 'super_admin';
    case Operations = 'operations';
    case Finance = 'finance';
    case Support = 'support';
    case Compliance = 'compliance';
    case RiskFraud = 'risk_fraud';
    case AgentManager = 'agent_manager';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Operations => 'Opérations',
            self::Finance => 'Finance',
            self::Support => 'Support',
            self::Compliance => 'Conformité',
            self::RiskFraud => 'Risk & Fraud',
            self::AgentManager => 'Gestion des agents',
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
            self::RiskFraud => 'Plafonds de risque, exposition, alertes AML et mise maximale des joueurs ; ni ledger ni corrections.',
            self::AgentManager => 'Agents et commissions (consultation) ; ni ledger global ni taux de commission.',
        };
    }
}
