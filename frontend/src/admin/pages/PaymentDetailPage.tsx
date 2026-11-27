import { Link, useParams } from 'react-router-dom';
import { useMe } from '../../features/auth/api/auth';
import { Alert } from '../../shared/components/Alert';
import { DataTable, type Column } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { MoneyText } from '../../shared/components/MoneyText';
import { Spinner } from '../../shared/components/Spinner';
import { formatMoney } from '../../shared/format/money';
import { dateTime } from '../api/events';
import { DIRECTION_LABELS, providerLabel, usePayment, type AdminPayment, type PaymentWebhook } from '../api/payments';
import { PageHeader } from '../components/PageHeader';
import { PaymentStatusBadge } from './PaymentsPage';

/** Ce que veut dire le statut, et ce qu'il faut faire, selon le sens du paiement. */
function explain(p: AdminPayment): { tone: 'info' | 'warning' | 'success' | 'error'; text: string } {
  if (p.direction === 'payout') {
    switch (p.status) {
      case 'pending': return { tone: 'info', text: 'Demandé par le joueur : le montant est déjà réservé sur son solde. Il sera envoyé au prestataire au prochain passage de la tâche des versements ; le joueur peut encore l\'annuler.' };
      case 'processing': return { tone: 'warning', text: 'Envoyé au prestataire, en attente de sa réponse. S\'il reste dans cet état longtemps, vérifier chez le prestataire avec sa référence.' };
      case 'succeeded': return { tone: 'success', text: 'Versé par le prestataire. Le montant avait été débité à la demande : rien d\'autre ne change au solde.' };
      case 'failed': return { tone: 'error', text: 'Refusé par le prestataire : le montant a été rendu au solde du joueur par une écriture compensatoire.' };
      case 'cancelled': return { tone: 'info', text: 'Annulé par le joueur avant l\'envoi : le montant lui a été rendu.' };
      default: return { tone: 'warning', text: 'État inhabituel pour un retrait : vérifier chez le prestataire avec sa référence.' };
    }
  }

  switch (p.status) {
    case 'pending': return { tone: 'info', text: 'Dépôt ouvert, en attente du paiement chez le prestataire. Rien n\'est crédité tant que le prestataire ne l\'a pas confirmé.' };
    case 'succeeded': return { tone: 'success', text: `Confirmé par le prestataire et crédité une seule fois au solde du joueur${p.late ? ', après l\'expiration de la demande' : ''}.` };
    case 'review': return { tone: 'warning', text: 'Mis en revue : le prestataire a annoncé un autre montant ou une autre devise. Rien n\'a été crédité ; vérifier avec la référence du prestataire avant toute correction.' };
    case 'expired': return { tone: 'info', text: 'Aucune confirmation reçue à temps : rien n\'a été crédité. Une confirmation tardive est encore appliquée si elle arrive.' };
    case 'failed': return { tone: 'error', text: 'Refusé par le prestataire : rien n\'a été crédité.' };
    default: return { tone: 'info', text: 'Paiement en cours chez le prestataire.' };
  }
}

const webhookColumns: Column<PaymentWebhook>[] = [
  { key: 'date', header: 'Reçu le', render: (w) => <span className="small">{dateTime(w.created_at)}</span> },
  { key: 'outcome', header: 'Résultat', render: (w) => <span className="mono small">{w.outcome}</span> },
  { key: 'signature', header: 'Signature', render: (w) => (w.signature_valid ? 'Valide' : <strong>Invalide</strong>) },
  { key: 'ip', header: 'Adresse', render: (w) => <span className="mono small">{w.ip_address ?? 'vérification par nous'}</span> },
  {
    key: 'payload',
    header: 'Contenu',
    render: (w) => (
      <details>
        <summary className="small">Voir</summary>
        <pre className="mono small">{JSON.stringify(w.payload, null, 2)}</pre>
      </details>
    ),
  },
];

/**
 * Fiche d'un paiement (ledger.view) — V2-B03, #145. Lecture seule : statut expliqué, montants, références, liens pour
 * vérifier (joueur, grand livre, KYC, alertes AML) et messages reçus du prestataire.
 */
export function PaymentDetailPage() {
  const { id = '' } = useParams();
  const { data: me } = useMe();
  const payment = usePayment(id);
  const p = payment.data;
  const can = (permission: string) => Boolean(me?.permissions.includes(permission));
  const status = p && explain(p);

  return (
    <section className="admin-page">
      <PageHeader
        back={{ to: p?.direction === 'deposit' ? '/admin/payments?direction=deposit' : '/admin/payments', label: 'Paiements' }}
        title={p ? `${DIRECTION_LABELS[p.direction]} ${p.reference}` : 'Paiement'}
        subtitle={p && <Link to={`/admin/users/${p.user_id}`}>Joueur n° {p.user_id}</Link>}
        actions={p && <PaymentStatusBadge payment={p} />}
      />

      {payment.isPending && <Spinner label="Chargement du paiement" />}
      <ErrorAlert error={payment.error} onRetry={() => payment.refetch()} />

      {p && status && (
        <>
          <dl className="summary-grid">
            <div className="summary-item"><dt>Montant</dt><dd><MoneyText value={p.amount} /></dd></div>
            <div className="summary-item"><dt>Prestataire</dt><dd>{providerLabel(p.provider)}</dd></div>
            <div className="summary-item"><dt>Créé le</dt><dd>{dateTime(p.created_at)}</dd></div>
            <div className="summary-item"><dt>Terminé le</dt><dd>{p.completed_at ? dateTime(p.completed_at) : '—'}</dd></div>
          </dl>

          <div className="detail-grid">
            <section className="admin-card stack">
              <h2 className="card-title">Où en est ce {p.direction === 'payout' ? 'retrait' : 'dépôt'}</h2>
              <Alert tone={status.tone}>{status.text}</Alert>
              {p.review_reason && (
                <p className="small">
                  Motif de la revue : <span className="mono">{p.review_reason}</span>
                  {p.reported?.amount_minor !== undefined && (
                    <> · montant annoncé par le prestataire : {formatMoney({ minor: p.reported.amount_minor, currency: p.reported.currency ?? p.amount.currency })}</>
                  )}
                </p>
              )}

              <dl className="details">
                <div className="details-row"><dt>Notre référence</dt><dd className="mono">{p.reference}</dd></div>
                <div className="details-row"><dt>Référence du prestataire</dt><dd className="mono">{p.provider_reference ?? '—'}</dd></div>
                <div className="details-row"><dt>Écriture du grand livre</dt><dd className="mono">{p.ledger_entry_id ? `n° ${p.ledger_entry_id}` : '—'}</dd></div>
                {p.expires_at && p.direction === 'deposit' && (
                  <div className="details-row"><dt>Expire le</dt><dd>{dateTime(p.expires_at)}</dd></div>
                )}
              </dl>
            </section>

            <aside className="admin-card stack">
              <h2 className="card-title">Vérifier</h2>
              <ul className="stack-sm">
                <li><Link to={`/admin/users/${p.user_id}`}>Fiche du joueur</Link></li>
                <li><Link to={`/admin/finance?user_id=${encodeURIComponent(p.user_id)}`}>Grand livre du joueur</Link></li>
                <li><Link to={`/admin/payments?direction=all&user_id=${encodeURIComponent(p.user_id)}`}>Tous les paiements de ce joueur</Link></li>
                {can('kyc.view') && <li><Link to={`/admin/kyc?status=all&user_id=${encodeURIComponent(p.user_id)}`}>Dossiers KYC du joueur</Link></li>}
                {can('aml.view') && <li><Link to={`/admin/aml?status=all&user_id=${encodeURIComponent(p.user_id)}`}>Alertes AML du joueur</Link></li>}
              </ul>
            </aside>
          </div>

          <section className="admin-card stack">
            <h2 className="card-title">Messages du prestataire</h2>
            <p className="muted small">
              Notifications reçues (webhooks) et vérifications faites par nous, dans l'ordre. Contenu brut du prestataire :
              il peut contenir des données personnelles.
            </p>
            <DataTable
              caption="Messages du prestataire"
              columns={webhookColumns}
              rows={p.webhooks}
              rowKey={(w) => w.id}
              empty="Aucun message reçu du prestataire."
            />
          </section>
        </>
      )}
    </section>
  );
}
