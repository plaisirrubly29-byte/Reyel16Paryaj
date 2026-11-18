import { Link, useParams } from 'react-router-dom';
import { Alert } from '../../shared/components/Alert';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Spinner } from '../../shared/components/Spinner';
import { useMe } from '../../features/auth/api/auth';
import { dateTime } from '../api/events';
import { deviationLabel, matchName, OVERRIDE_APPROVAL_THRESHOLD_PERCENT, useOddsOverride, useOverrideAction, type OddsOverride } from '../api/odds';
import { PageHeader } from '../components/PageHeader';
import { ReasonForm } from '../components/ReasonForm';
import { OverrideStatusBadge } from './OddsOverridesPage';

/** Cote fixée à la main : comparaison avec la cote calculée, validation, refus ou fin, avec motif. */
export function OddsOverrideDetailPage() {
  const { id = '' } = useParams();
  const override = useOddsOverride(id);
  const o = override.data;

  return (
    <section className="admin-page">
      <PageHeader
        back={{ to: '/admin/odds', label: 'Cotes ajustées' }}
        title={o ? `Cote fixée à la main n° ${o.id}` : 'Cote fixée à la main'}
        subtitle={o?.event && <Link to={`/admin/events/${o.event.id}`}>{matchName(o)}</Link>}
        actions={o && <OverrideStatusBadge status={o.status} />}
      />

      {override.isPending && <Spinner label="Chargement de la demande" />}
      <ErrorAlert error={override.error} onRetry={() => override.refetch()} />

      {o && (
        <>
          <dl className="summary-grid">
            <div className="summary-item"><dt>Sélection</dt><dd>{o.selection.name ?? '—'}</dd></div>
            <div className="summary-item"><dt>Cote calculée</dt><dd>{o.computed_odds}</dd></div>
            <div className="summary-item"><dt>Cote fixée</dt><dd><strong>{o.odds}</strong> <span className="muted small">({deviationLabel(o.deviation_percent)})</span></dd></div>
            <div className="summary-item"><dt>Jusqu'au</dt><dd>{dateTime(o.expires_at)}</dd></div>
          </dl>

          <div className="detail-grid">
            <section className="admin-card stack">
              <h2 className="card-title">Demande</h2>
              <dl className="details">
                <div className="details-row"><dt>Par</dt><dd>{o.requested_by?.name ?? '—'}</dd></div>
                <div className="details-row"><dt>Le</dt><dd>{dateTime(o.requested_at)}</dd></div>
                <div className="details-row"><dt>Motif</dt><dd>{o.reason}</dd></div>
                <div className="details-row">
                  <dt>Validation</dt>
                  <dd>{o.requires_approval
                    ? `Double validation : écart de plus de ${OVERRIDE_APPROVAL_THRESHOLD_PERCENT} % avec la cote calculée.`
                    : `En vigueur sans double validation : écart de ${OVERRIDE_APPROVAL_THRESHOLD_PERCENT} % au plus.`}</dd>
                </div>
              </dl>
            </section>

            <aside className="admin-card stack">
              {o.status === 'pending' && <Decision override={o} />}
              {o.status === 'active' && <End override={o} />}
              <History override={o} />
            </aside>
          </div>
        </>
      )}
    </section>
  );
}

/** Valider (odds.override, jamais le demandeur) ou refuser / retirer (demandeur ou odds.override). */
function Decision({ override }: { override: OddsOverride }) {
  const { data: me } = useMe();
  const action = useOverrideAction(override);
  const allowed = Boolean(me?.permissions.includes('odds.override'));
  const isRequester = override.requested_by?.id === me?.id;
  const canApprove = allowed && !isRequester;

  return (
    <>
      <h2 className="card-title">Décision</h2>
      {isRequester && <Alert tone="info">Vous avez fait cette demande : un autre administrateur doit la valider. Vous pouvez la retirer.</Alert>}
      {!allowed && <Alert tone="info">La validation est réservée aux administrateurs habilités (Super Admin, Opérations).</Alert>}

      {canApprove && (
        <ReasonForm
          id="approve"
          title="Valider la cote"
          description="La cote entre en vigueur tout de suite (nouvelle version de cote ; les tickets placés gardent la leur)."
          submitLabel="Valider"
          onSubmit={(reason, idempotencyKey) => action.mutateAsync({ action: 'approve', reason, idempotencyKey })}
        />
      )}
      {allowed && (
        <ReasonForm
          id="reject"
          title={isRequester ? 'Retirer la demande' : 'Refuser la demande'}
          submitLabel={isRequester ? 'Retirer' : 'Refuser'}
          variant="secondary"
          onSubmit={(reason, idempotencyKey) => action.mutateAsync({ action: 'reject', reason, idempotencyKey })}
        />
      )}
    </>
  );
}

/** Revenir à la cote calculée avant la date limite (odds.override). */
function End({ override }: { override: OddsOverride }) {
  const { data: me } = useMe();
  const action = useOverrideAction(override);
  if (!me?.permissions.includes('odds.override')) return null;

  return (
    <ReasonForm
      id="end"
      title="Revenir à la cote calculée"
      description="La cote fixée prend fin maintenant ; la cote calculée revient."
      submitLabel="Revenir à la cote calculée"
      variant="secondary"
      onSubmit={(reason, idempotencyKey) => action.mutateAsync({ action: 'end', reason, idempotencyKey })}
    />
  );
}

function History({ override: o }: { override: OddsOverride }) {
  if (o.status === 'pending') return null;
  const title = {
    pending: '', active: 'En vigueur', rejected: 'Refusée ou retirée', ended: 'Terminée', expired: 'Expirée',
  }[o.status];

  return (
    <>
      <h2 className="card-title">{title}</h2>
      <dl className="details">
        {o.decided_at && (
          <>
            <div className="details-row"><dt>Décision</dt><dd>{o.decided_by?.name ?? '—'}, {dateTime(o.decided_at)}</dd></div>
            <div className="details-row"><dt>Motif</dt><dd>{o.decision_reason ?? '—'}</dd></div>
          </>
        )}
        {o.ended_at && (
          <div className="details-row">
            <dt>Fin</dt>
            <dd>{o.ended_by ? `${o.ended_by.name}, ` : 'Date limite atteinte, '}{dateTime(o.ended_at)}</dd>
          </div>
        )}
      </dl>
    </>
  );
}
