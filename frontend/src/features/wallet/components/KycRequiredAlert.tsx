import type { ApiError } from '../../../shared/api/client';
import { Alert } from '../../../shared/components/Alert';
import { formatMoney } from '../../../shared/format/money';

/**
 * Refus kyc_required (V2-C03) d'un dépôt au-delà du seuil ou d'un retrait : message selon le statut du dossier
 * du joueur (details.kyc_status), et pour un dépôt, ce qu'il peut encore déposer sans identité vérifiée.
 */
export function KycRequiredAlert({ error, operation, currency }: { error: ApiError; operation: 'deposit' | 'withdrawal'; currency: string }) {
  const { kyc_status: status, threshold_minor: threshold, deposited_minor: deposited } = error.details as {
    kyc_status?: string;
    threshold_minor?: number;
    deposited_minor?: number;
  };

  const reason = operation === 'withdrawal'
    ? 'Un retrait exige une identité vérifiée.'
    : <>
        Au-delà de {typeof threshold === 'number' ? formatMoney({ minor: threshold, currency }) : 'un certain montant'} de dépôts, une identité vérifiée est exigée.
        {typeof threshold === 'number' && typeof deposited === 'number' && threshold > deposited && (
          <> Vous pouvez encore déposer {formatMoney({ minor: threshold - deposited, currency })} sans vérification.</>
        )}
      </>;

  if (status === 'pending') {
    return (
      <Alert tone="info" title="Vérification d'identité en cours">
        {reason} Votre dossier est en cours d'examen : vous pourrez continuer dès sa validation.
      </Alert>
    );
  }

  return (
    <Alert tone="warning" title="Vérification d'identité requise">
      {reason}{' '}
      {status === 'rejected'
        ? 'Votre dernier dossier a été refusé : envoyez une nouvelle pièce d\'identité et un selfie.'
        : 'Envoyez une pièce d\'identité et un selfie pour faire vérifier votre compte.'}
    </Alert>
  );
}
