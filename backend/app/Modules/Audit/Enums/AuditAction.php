<?php

namespace App\Modules\Audit\Enums;

/**
 * Registre des actions auditées. Chaque module ajoute ses actions ici ("<ressource>.<verbe passé>").
 */
enum AuditAction: string
{
    // Identity & Access
    case RolesUpdated = 'roles.updated';
    case UserSuspended = 'user.suspended';
    case UserReactivated = 'user.reactivated';
    case UserSelfExcluded = 'user.self_excluded'; // auto-exclusion du joueur (P7-02)
    case UserSelfExclusionEnded = 'user.self_exclusion_ended'; // réouverture par la Conformité, après échéance
    case ResponsibleGamingViewed = 'responsible_gaming.viewed';
    case MfaReset = 'mfa.reset';
    case MfaFailed = 'mfa.failed';
    case UserPersonalDataViewed = 'user.personal_data_viewed';

    // Audit
    case AuditViewed = 'audit.viewed';
    case AuditExported = 'audit.exported';

    /** Les actions sensibles exigent un motif écrit (règle §7 de l'architecture). */
    public function requiresReason(): bool
    {
        return match ($this) {
            self::AuditViewed, self::UserPersonalDataViewed, self::MfaFailed, self::UserSelfExcluded,
            self::ResponsibleGamingViewed => false,
            default => true,
        };
    }
}
