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
    case MfaRecoveryCodesRegenerated = 'mfa.recovery_codes_regenerated';
    case UserPersonalDataViewed = 'user.personal_data_viewed';

    // Admin — événements et marchés (P6-02)
    case MarketSuspended = 'market.suspended';
    case MarketReopened = 'market.reopened';

    // Admin — corrections financières à double validation (P6-04)
    case FinanceCorrectionRequested = 'finance.correction_requested';
    case FinanceCorrectionApproved = 'finance.correction_approved';
    case FinanceCorrectionRejected = 'finance.correction_rejected';

    // Settlement — correction de résultat à double contrôle (P5-06)
    case ResultCorrectionRequested = 'result.correction_requested';
    case ResultCorrectionApproved = 'result.correction_approved';
    case ResultCorrectionRejected = 'result.correction_rejected';

    // Admin — paramètres de la plateforme versionnés (P6-08)
    case SettingsUpdated = 'settings.updated';

    // Audit
    case AuditViewed = 'audit.viewed';
    case AuditExported = 'audit.exported';

    /** Les actions sensibles exigent un motif écrit (règle §7 de l'architecture). */
    public function requiresReason(): bool
    {
        return match ($this) {
            self::AuditViewed, self::UserPersonalDataViewed, self::MfaFailed, self::MfaRecoveryCodesRegenerated, self::UserSelfExcluded,
            self::ResponsibleGamingViewed => false,
            default => true,
        };
    }
}
