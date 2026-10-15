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
    case MfaReset = 'mfa.reset';

    // Audit
    case AuditViewed = 'audit.viewed';
    case AuditExported = 'audit.exported';

    /** Les actions sensibles exigent un motif écrit (règle §7 de l'architecture). */
    public function requiresReason(): bool
    {
        return match ($this) {
            self::AuditViewed => false,
            default => true,
        };
    }
}
