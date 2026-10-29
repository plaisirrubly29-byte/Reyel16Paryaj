import { Link, useParams } from 'react-router-dom';
import { DataTable } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { MoneyText } from '../../shared/components/MoneyText';
import { Spinner } from '../../shared/components/Spinner';
import { useMe } from '../../features/auth/api/auth';
import { LEDGER_TYPE_LABELS, useAdminTicket, type AdminTicketDetail } from '../api/tickets';
import { PageHeader } from '../components/PageHeader';
import { TicketStatusBadge } from '../components/TicketStatusBadge';

const dateTime = (iso: string | null) => (iso ? new Date(iso).toLocaleString('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }) : '—');

/** Fiche d'un ticket : montants, sélections, et écritures du ledger pour les rôles qui les consultent. */
export function TicketDetailPage() {
  const { id = '' } = useParams();
  const ticket = useAdminTicket(id);
  const t = ticket.data;

  return (
    <section className="admin-page">
      <PageHeader
        back={{ to: '/admin/tickets', label: 'Tickets' }}
        title={t ? <span className="mono">{t.reference}</span> : 'Ticket'}
        subtitle={t ? `${t.type === 'single' ? 'Pari simple' : `Combiné de ${t.selections.length} sélections`} · placé le ${dateTime(t.placed_at)}` : undefined}
        actions={t && <TicketStatusBadge status={t.status} />}
      />

      {ticket.isPending && <div className="table-state"><Spinner /></div>}
      <ErrorAlert error={ticket.error} onRetry={() => ticket.refetch()} />

      {t && (
        <>
          <Summary ticket={t} />

          <div className="detail-grid">
            <section className="admin-card">
              <h2 className="card-title">Sélections</h2>
              <ol className="selections">
                {t.selections.map((s) => (
                  <li key={s.id}>
                    <div className="selection-main">
                      <strong>{s.event.name}</strong>
                      <span className="muted small">{dateTime(s.event.starts_at)}</span>
                      <span>{s.market.name} · <strong>{s.selection.label}</strong>{s.selection.name !== s.selection.label && <span className="muted"> ({s.selection.name})</span>}</span>
                    </div>
                    <div className="selection-side">
                      <span className="odds-pill">{s.odds}</span>
                      <TicketStatusBadge status={s.status} />
                    </div>
                  </li>
                ))}
              </ol>
            </section>

            <aside className="admin-card stack-sm">
              <h2 className="card-title">Joueur</h2>
              <PlayerCard ticket={t} />
              <h2 className="card-title">Règles appliquées</h2>
              <p className="muted small">Version <code>{t.rules_version}</code>, figée au moment du pari.</p>
              <details className="rules-snapshot">
                <summary>Voir les paramètres</summary>
                <dl className="details">
                  {Object.entries(t.rules_snapshot).map(([key, value]) => (
                    <div key={key} className="details-row"><dt>{key}</dt><dd>{typeof value === 'object' ? JSON.stringify(value) : String(value)}</dd></div>
                  ))}
                </dl>
              </details>
            </aside>
          </div>

          {t.ledger_entries && (
            <section className="admin-card">
              <h2 className="card-title">Écritures du portefeuille</h2>
              <DataTable
                caption="Écritures liées au ticket"
                rows={t.ledger_entries}
                rowKey={(e) => e.id}
                empty="Aucune écriture liée à ce ticket."
                columns={[
                  { key: 'date', header: 'Date', render: (e) => dateTime(e.created_at) },
                  { key: 'type', header: 'Type', render: (e) => LEDGER_TYPE_LABELS[e.type] ?? e.type },
                  { key: 'ref', header: 'Référence', render: (e) => <span className="mono small">{e.reference}</span> },
                  { key: 'amount', header: 'Montant', align: 'right', render: (e) => <MoneyText value={e.amount} signed /> },
                  { key: 'balance', header: 'Solde après', align: 'right', render: (e) => <MoneyText value={e.balance_after} /> },
                  {
                    key: 'state',
                    header: '',
                    render: (e) => (e.reversed_by_id ? <span className="badge">Compensée</span> : e.reversal_of_id ? <span className="badge">Compensation</span> : null),
                  },
                ]}
              />
            </section>
          )}
        </>
      )}
    </section>
  );
}

function Summary({ ticket: t }: { ticket: AdminTicketDetail }) {
  const settled = t.status !== 'pending';

  return (
    <dl className="summary-grid">
      <div className="summary-item"><dt>Mise</dt><dd><MoneyText value={t.stake} /></dd></div>
      <div className="summary-item"><dt>Cote totale</dt><dd>{t.total_odds}</dd></div>
      <div className="summary-item">
        <dt>{settled ? 'Gain versé' : 'Gain potentiel'}</dt>
        <dd><MoneyText value={settled ? (t.payout ?? { minor: 0, currency: t.stake.currency }) : t.potential_payout} /></dd>
      </div>
      <div className="summary-item"><dt>Réglé le</dt><dd>{settled ? dateTime(t.settled_at) : 'En attente des résultats'}</dd></div>
    </dl>
  );
}

function PlayerCard({ ticket: t }: { ticket: AdminTicketDetail }) {
  const { data: me } = useMe();
  const canViewUsers = me?.permissions.includes('users.view') ?? false;

  return (
    <div className="player-card">
      <p>
        <strong>{t.user.name ?? 'Joueur'}</strong>
        <span className="muted small"> · n° {t.user.id}</span>
      </p>
      <div className="player-links">
        {canViewUsers && <Link to={`/admin/users/${t.user.id}`}>Fiche du compte</Link>}
        <Link to={`/admin/tickets?user_id=${t.user.id}`}>Tous ses tickets</Link>
      </div>
    </div>
  );
}
