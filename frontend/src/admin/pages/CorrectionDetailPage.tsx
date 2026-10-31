import { Link, useParams } from 'react-router-dom';
import { Alert } from '../../shared/components/Alert';
import { DataTable, type Column } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { MoneyText } from '../../shared/components/MoneyText';
import { Spinner } from '../../shared/components/Spinner';
import { useMe } from '../../features/auth/api/auth';
import { outcomeLabel, sourceLabel, useDecideCorrection, useResultCorrection, type CorrectionImpact, type ResultCorrection } from '../api/results';
import { PageHeader } from '../components/PageHeader';
import { ReasonForm } from '../components/ReasonForm';
import { TicketStatusBadge } from '../components/TicketStatusBadge';
import { dateTime } from '../api/events';
import { CorrectionStatusBadge } from './SettlementsPage';

type ImpactRow = CorrectionImpact['tickets'][number];

const htg = (minor: number) => ({ minor, currency: 'HTG' });

const impactColumns: Column<ImpactRow>[] = [
  { key: 'ticket', header: 'Ticket', render: (t) => <Link to={`/admin/tickets/${t.ticket_id}`} className="mono nowrap">{t.reference}</Link> },
  { key: 'player', header: 'Joueur', render: (t) => <Link to={`/admin/tickets?user_id=${t.user_id}`}>n° {t.user_id}</Link> },
  { key: 'from', header: 'Avant', render: (t) => <span className="nowrap"><TicketStatusBadge status={t.from.status} /> <MoneyText value={htg(t.from.payout_minor)} /></span> },
  { key: 'to', header: 'Après', render: (t) => <span className="nowrap"><TicketStatusBadge status={t.to.status} /> <MoneyText value={htg(t.to.payout_minor)} /></span> },
  { key: 'delta', header: 'Effet sur le solde', align: 'right', render: (t) => <MoneyText value={htg(t.ledger_delta_minor)} signed /> },
];

/** Demande de correction : comparaison, impact sur les tickets, validation ou refus avec motif. */
export function CorrectionDetailPage() {
  const { id = '' } = useParams();
  const correction = useResultCorrection(id);
  const c = correction.data;

  return (
    <section className="admin-page">
      <PageHeader
        back={{ to: '/admin/settlements', label: 'Résultats & règlement' }}
        title={c ? `Correction n° ${c.id}` : 'Correction de résultat'}
        subtitle={c && <Link to={`/admin/events/${c.event.id}`}>{c.event.name}</Link>}
        actions={c && <CorrectionStatusBadge status={c.status} />}
      />

      {correction.isPending && <Spinner label="Chargement de la demande" />}
      <ErrorAlert error={correction.error} onRetry={() => correction.refetch()} />

      {c && (
        <>
          <dl className="summary-grid">
            <div className="summary-item"><dt>Résultat en vigueur</dt><dd>{outcomeLabel(c.current)} <span className="muted small">(version {c.current.version})</span></dd></div>
            <div className="summary-item"><dt>Proposé</dt><dd><strong>{outcomeLabel(c.proposed)}</strong></dd></div>
            <div className="summary-item"><dt>Demandée par</dt><dd>{c.requested_by ? c.requested_by.name : sourceLabel(c.source)}</dd></div>
            <div className="summary-item"><dt>Le</dt><dd>{dateTime(c.created_at)}</dd></div>
          </dl>

          <div className="detail-grid">
            <section className="admin-card stack">
              <h2 className="card-title">Motif de la demande</h2>
              <p>{c.reason}</p>
              <Impact correction={c} />
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

function Impact({ correction }: { correction: ResultCorrection }) {
  const impact = correction.status === 'pending' ? correction.preview : correction.impact;
  if (!impact) return null;

  return (
    <>
      <h2 className="card-title">{correction.status === 'pending' ? 'Effet si la demande est validée maintenant' : 'Effet appliqué'}</h2>
      <p className="small">
        {impact.tickets_count === 0
          ? 'Aucun ticket ne change d\'issue.'
          : <>{impact.tickets_count} ticket{impact.tickets_count > 1 ? 's' : ''} change{impact.tickets_count > 1 ? 'nt' : ''} d'issue ; effet total sur les soldes : <MoneyText value={htg(impact.ledger_delta_minor)} signed /> (négatif : argent repris aux joueurs, même si leur solde devient négatif).</>}
      </p>
      {impact.tickets_count > 0 && (
        <DataTable caption="Tickets réévalués" columns={impactColumns} rows={impact.tickets} rowKey={(t) => t.ticket_id} />
      )}
    </>
  );
}

/** Validation (results.approve, jamais le demandeur) ou refus / retrait (demandeur ou results.approve). */
function Decision({ correction }: { correction: ResultCorrection }) {
  const { data: me } = useMe();
  const decide = useDecideCorrection(correction);
  const isRequester = correction.requested_by?.id === me?.id;
  const canApprove = Boolean(me?.permissions.includes('results.approve')) && !isRequester;
  const canReject = isRequester || Boolean(me?.permissions.includes('results.approve'));

  return (
    <>
      <h2 className="card-title">Décision</h2>
      {isRequester && <Alert tone="info">Vous avez fait cette demande : un autre administrateur doit la valider. Vous pouvez la retirer.</Alert>}
      {!canApprove && !canReject && <Alert tone="info">La validation est réservée aux administrateurs habilités (Super Admin, Opérations).</Alert>}

      {canApprove && (
        <ReasonForm
          id="approve"
          title="Valider la correction"
          description="Crée la nouvelle version du résultat et réévalue les tickets ci-contre (écritures compensatoires)."
          submitLabel="Valider"
          onSubmit={(reason, idempotencyKey) => decide.mutateAsync({ decision: 'approve', reason, idempotencyKey })}
        />
      )}
      {canReject && (
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

function DecisionSummary({ correction }: { correction: ResultCorrection }) {
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
