import { Link, useParams } from 'react-router-dom';
import { useMe } from '../../features/auth/api/auth';
import { Alert } from '../../shared/components/Alert';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { MoneyText } from '../../shared/components/MoneyText';
import { Spinner } from '../../shared/components/Spinner';
import { dateTime } from '../api/events';
import { useDecideFinanceCorrection, useFinanceCorrection, type FinanceCorrection } from '../api/finance';
import { LEDGER_TYPE_LABELS } from '../api/tickets';
import { PageHeader } from '../components/PageHeader';
import { ReasonForm } from '../components/ReasonForm';
import { FinanceCorrectionStatusBadge } from './FinanceCorrectionsPage';

/** Correction financière : écriture visée, double validation (demandeur, puis un autre administrateur). */
export function FinanceCorrectionDetailPage() {
  const { id = '' } = useParams();
  const correction = useFinanceCorrection(id);
  const c = correction.data;
  const entry = c?.ledger_entry;

  return (
    <section className="admin-page">
      <PageHeader
        back={{ to: '/admin/finance/corrections', label: 'Corrections financières' }}
        title={c ? `Correction n° ${c.id}` : 'Correction financière'}
        subtitle={entry && <>Écriture n° {entry.id} · <Link to={`/admin/users/${entry.user_id}`}>joueur n° {entry.user_id}</Link></>}
        actions={c && <FinanceCorrectionStatusBadge status={c.status} />}
      />

      {correction.isPending && <Spinner label="Chargement de la correction" />}
      <ErrorAlert error={correction.error} onRetry={() => correction.refetch()} />

      {c && entry && (
        <>
          <dl className="summary-grid">
            <div className="summary-item"><dt>Écriture</dt><dd>{LEDGER_TYPE_LABELS[entry.type] ?? entry.type} <MoneyText value={entry.amount} signed /></dd></div>
            <div className="summary-item"><dt>Effet de la correction</dt><dd><MoneyText value={{ ...entry.amount, minor: -entry.amount.minor }} signed /></dd></div>
            <div className="summary-item"><dt>Demandée par</dt><dd>{c.requested_by.name ?? `n° ${c.requested_by.id}`}</dd></div>
            <div className="summary-item"><dt>Le</dt><dd>{dateTime(c.requested_at)}</dd></div>
          </dl>

          <div className="detail-grid">
            <section className="admin-card stack">
              <h2 className="card-title">Motif de la demande</h2>
              <p>{c.reason}</p>
              <h2 className="card-title">Écriture visée</h2>
              <dl className="details">
                <div className="details-row"><dt>Référence</dt><dd className="mono small">{entry.reference}</dd></div>
                <div className="details-row"><dt>Passée le</dt><dd>{dateTime(entry.created_at)}</dd></div>
                <div className="details-row"><dt>Solde après</dt><dd><MoneyText value={entry.balance_after} /></dd></div>
                {entry.description && <div className="details-row"><dt>Libellé</dt><dd>{entry.description}</dd></div>}
                {c.reversal_entry_id && <div className="details-row"><dt>Compensation</dt><dd>Écriture n° {c.reversal_entry_id}</dd></div>}
              </dl>
            </section>

            <aside className="admin-card stack">
              {c.status === 'pending' ? <Decision correction={c} /> : <DecisionSummary correction={c} />}
            </aside>
          </div>
        </>
      )}
    </section>
  );
}

/** Validation (finance.approve, jamais le demandeur) ; refus ou retrait (finance.approve). */
function Decision({ correction }: { correction: FinanceCorrection }) {
  const { data: me } = useMe();
  const decide = useDecideFinanceCorrection(correction);
  const isRequester = correction.requested_by.id === me?.id;
  const canDecide = Boolean(me?.permissions.includes('finance.approve'));

  return (
    <>
      <h2 className="card-title">Décision</h2>
      <Alert tone="info">Double validation : la correction n'est passée au grand livre qu'après l'accord d'un second administrateur.</Alert>
      {isRequester && <Alert tone="info">Vous avez fait cette demande : un autre administrateur doit la valider. Vous pouvez la retirer.</Alert>}
      {!canDecide && <Alert tone="info">La validation est réservée aux administrateurs habilités (Super Admin, Finance).</Alert>}

      {canDecide && !isRequester && (
        <ReasonForm
          id="approve"
          title="Valider la correction"
          description="Passe l'écriture compensatoire au grand livre. Définitif : une compensation ne peut pas être corrigée à son tour."
          submitLabel="Valider"
          onSubmit={(reason, idempotencyKey) => decide.mutateAsync({ decision: 'approve', reason, idempotencyKey })}
        />
      )}
      {canDecide && (
        <ReasonForm
          id="reject"
          title={isRequester ? 'Retirer la demande' : 'Refuser la demande'}
          submitLabel={isRequester ? 'Retirer' : 'Refuser'}
          variant="secondary"
          onSubmit={(reason, idempotencyKey) => decide.mutateAsync({ decision: 'reject', reason, idempotencyKey })}
        />
      )}
    </>
  );
}

function DecisionSummary({ correction }: { correction: FinanceCorrection }) {
  return (
    <>
      <h2 className="card-title">{correction.status === 'approved' ? 'Validée' : 'Refusée ou retirée'}</h2>
      <dl className="details">
        <div className="details-row"><dt>Par</dt><dd>{correction.decided_by?.name ?? '—'}</dd></div>
        <div className="details-row"><dt>Le</dt><dd>{correction.decided_at ? dateTime(correction.decided_at) : '—'}</dd></div>
        <div className="details-row"><dt>Motif</dt><dd>{correction.decision_reason ?? '—'}</dd></div>
      </dl>
    </>
  );
}
