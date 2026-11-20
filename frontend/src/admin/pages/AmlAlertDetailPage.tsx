import { Link, useParams } from 'react-router-dom';
import { useMe } from '../../features/auth/api/auth';
import { Alert } from '../../shared/components/Alert';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { MoneyText } from '../../shared/components/MoneyText';
import { Spinner } from '../../shared/components/Spinner';
import { AML_RULE_LABELS, explainAlert, useAmlAlert, useReviewAmlAlert, type AmlAlert } from '../api/aml';
import { dateTime } from '../api/events';
import { PageHeader } from '../components/PageHeader';
import { ReasonForm } from '../components/ReasonForm';
import { AmlStatusBadge } from './AmlAlertsPage';

/**
 * Alerte AML (aml.view, V2-C04) : ce qui l'a déclenchée, liens vers le joueur et son argent, puis décision motivée
 * (aml.review) : classer (activité justifiée) ou signaler (soupçon). Décision auditée par le serveur.
 */
export function AmlAlertDetailPage() {
  const { id = '' } = useParams();
  const { data: me } = useMe();
  const alert = useAmlAlert(id);
  const a = alert.data;
  const can = (permission: string) => Boolean(me?.permissions.includes(permission));

  return (
    <section className="admin-page">
      <PageHeader
        back={{ to: '/admin/aml', label: 'Alertes AML' }}
        title={a ? `${AML_RULE_LABELS[a.rule] ?? a.rule} · alerte n° ${a.id}` : 'Alerte AML'}
        subtitle={a && <>{a.user.name ?? '—'} · <Link to={`/admin/users/${a.user.id}`}>joueur n° {a.user.id}</Link></>}
        actions={a && <AmlStatusBadge status={a.status} />}
      />

      {alert.isPending && <Spinner label="Chargement de l'alerte" />}
      <ErrorAlert error={alert.error} onRetry={() => alert.refetch()} />

      {a && (
        <>
          <dl className="summary-grid">
            <div className="summary-item"><dt>Règle</dt><dd>{AML_RULE_LABELS[a.rule] ?? a.rule}</dd></div>
            <div className="summary-item"><dt>Montant</dt><dd><MoneyText value={a.amount} /></dd></div>
            <div className="summary-item"><dt>Écriture</dt><dd className="mono">n° {a.ledger_entry_id}</dd></div>
            <div className="summary-item"><dt>Le</dt><dd>{dateTime(a.created_at)}</dd></div>
          </dl>

          <div className="detail-grid">
            <section className="admin-card stack">
              <h2 className="card-title">Pourquoi cette alerte</h2>
              <p>{explainAlert(a)}</p>
              <p className="muted small">
                Seuils en vigueur au moment de l'alerte. Vérifiez l'origine de l'argent et l'activité du joueur avant de
                décider.
              </p>

              <h2 className="card-title">Vérifier</h2>
              <ul className="stack-sm">
                <li><Link to={`/admin/users/${a.user.id}`}>Fiche du joueur</Link></li>
                {can('ledger.view') && (
                  <li><Link to={`/admin/finance?user_id=${encodeURIComponent(a.user.id)}`}>Grand livre du joueur</Link> <span className="muted small">(écriture n° {a.ledger_entry_id})</span></li>
                )}
                {can('tickets.view') && <li><Link to={`/admin/tickets?user_id=${encodeURIComponent(a.user.id)}`}>Paris du joueur</Link></li>}
                {can('kyc.view') && <li><Link to={`/admin/kyc?status=all&user_id=${encodeURIComponent(a.user.id)}`}>Dossiers KYC du joueur</Link></li>}
                <li><Link to={`/admin/aml?status=all&user_id=${encodeURIComponent(a.user.id)}`}>Toutes les alertes de ce joueur</Link></li>
              </ul>
            </section>

            <aside className="admin-card stack">
              {a.status === 'open' ? <Decision alert={a} /> : <DecisionSummary alert={a} />}
            </aside>
          </div>
        </>
      )}
    </section>
  );
}

/** Classer ou signaler (aml.review), jamais une alerte sur son propre compte (le serveur le refuse aussi). */
function Decision({ alert }: { alert: AmlAlert }) {
  const { data: me } = useMe();
  const review = useReviewAmlAlert(alert);
  const canReview = Boolean(me?.permissions.includes('aml.review'));
  const ownAccount = me?.id === alert.user.id;

  return (
    <>
      <h2 className="card-title">Décision</h2>
      {!canReview && <Alert tone="info">La décision est réservée aux administrateurs habilités (Conformité). Vous pouvez consulter l'alerte.</Alert>}
      {canReview && ownAccount && (
        <Alert tone="info">Cette alerte concerne votre propre compte : un autre administrateur doit la traiter.</Alert>
      )}

      {canReview && !ownAccount && (
        <>
          <ReasonForm
            id="dismiss"
            title="Classer l'alerte"
            description="Activité justifiée ou fausse alerte : expliquez pourquoi (justificatif, origine des fonds…). Le motif reste interne."
            submitLabel="Classer"
            variant="secondary"
            successMessage="Alerte classée."
            onSubmit={(reason, idempotencyKey) => review.mutateAsync({ decision: 'dismissed', reason, idempotencyKey })}
          />
          <ReasonForm
            id="report"
            title="Signaler l'alerte"
            description="Soupçon de blanchiment : l'alerte est marquée « signalée », à déclarer selon la procédure de conformité. Le motif reste interne."
            submitLabel="Signaler"
            variant="danger"
            successMessage="Alerte signalée."
            onSubmit={(reason, idempotencyKey) => review.mutateAsync({ decision: 'reported', reason, idempotencyKey })}
          />
        </>
      )}
    </>
  );
}

function DecisionSummary({ alert }: { alert: AmlAlert }) {
  return (
    <>
      <h2 className="card-title">{alert.status === 'reported' ? 'Alerte signalée' : 'Alerte classée'}</h2>
      <dl className="details">
        <div className="details-row"><dt>Par</dt><dd>{alert.reviewed_by?.name ?? '—'}</dd></div>
        <div className="details-row"><dt>Le</dt><dd>{alert.reviewed_at ? dateTime(alert.reviewed_at) : '—'}</dd></div>
        <div className="details-row"><dt>Motif</dt><dd>{alert.review_reason ?? '—'}</dd></div>
      </dl>
      {alert.status === 'reported' && <p className="muted small">Soupçon à déclarer selon la procédure de conformité.</p>}
    </>
  );
}
