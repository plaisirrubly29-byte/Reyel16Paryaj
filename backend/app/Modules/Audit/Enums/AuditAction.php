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
    case UserStakeLimitChanged = 'user.stake_limit_changed'; // mise maximale propre au joueur (V2-D05)
    case ResponsibleGamingViewed = 'responsible_gaming.viewed';
    case MfaReset = 'mfa.reset';
    case MfaFailed = 'mfa.failed';
    case MfaRecoveryCodesRegenerated = 'mfa.recovery_codes_regenerated';
    case SessionsRevoked = 'sessions.revoked'; // le titulaire déconnecte un ou tous ses autres appareils (V2-C06)

    // KYC (V2-C03)
    case KycSubmitted = 'kyc.submitted'; // dossier envoyé par le joueur
    case KycApproved = 'kyc.approved';
    case KycRejected = 'kyc.rejected';
    case KycDocumentViewed = 'kyc.document_viewed'; // pièce ou selfie ouvert par un administrateur
    case UserPersonalDataViewed = 'user.personal_data_viewed';

    // Admin — événements et marchés (P6-02)
    case MarketSuspended = 'market.suspended';
    case MarketReopened = 'market.reopened';

    // Admin — corrections financières à double validation (P6-04)
    case FinanceCorrectionRequested = 'finance.correction_requested';
    case FinanceCorrectionApproved = 'finance.correction_approved';
    case FinanceCorrectionRejected = 'finance.correction_rejected';

    // Admin — dépôt / retrait enregistré par le Super Admin (paiement hors plateforme)
    case WalletDeposited = 'wallet.deposited';
    case WalletWithdrawn = 'wallet.withdrawn';

    // Payments — retraits des joueurs versés par le prestataire (V2-B03)
    case WithdrawalRequested = 'withdrawal.requested';
    case WithdrawalCancelled = 'withdrawal.cancelled';
    case WithdrawalPaid = 'withdrawal.paid';
    case WithdrawalFailed = 'withdrawal.failed';

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
            self::KycSubmitted, self::KycDocumentViewed,
            self::ResponsibleGamingViewed, self::SessionsRevoked => false,
            default => true,
        };
    }
}
