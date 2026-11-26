import { Link, useParams } from 'react-router-dom';
import { Alert } from '../../shared/components/Alert';
import { DataTable } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { MoneyText } from '../../shared/components/MoneyText';
import { Spinner } from '../../shared/components/Spinner';
import { useMe } from '../../features/auth/api/auth';
import {
  LEDGER_TYPE_LABELS, TICKET_STATUS_LABELS, TICKET_VOID_STATUS_LABELS, useAdminTicket, useDecideTicketVoid, useRequestTicketVoid, useTicketVoid,
  type AdminTicketDetail, type TicketVoid, type TicketVoidImpact,
} from '../api/tickets';
import { PageHeader } from '../components/PageHeader';
import { ReasonForm } from '../components/ReasonForm';
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

          <VoidSection ticket={t} />

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

/**
 * Annulation du ticket à double validation (erreur de cote, fraude) : un administrateur tickets.void la demande, un
 * AUTRE tickets.void_approve la valide. À la validation, toutes les sélections passent à la cote 1 et la mise est rendue ;
 * un gain déjà versé est d'abord repris.
 */
function VoidSection({ ticket: t }: { ticket: AdminTicketDetail }) {
  const { data: me } = useMe();
  const voids = t.voids ?? [];
  const pending = voids.find((v) => v.status === 'pending');
  const canRequest = Boolean(me?.permissions.includes('tickets.void')) && !pending && ['pending', 'won', 'lost'].includes(t.status);
  const request = useRequestTicketVoid(t.id);

  if (voids.length === 0 && !canRequest) return null;

  return (
    <section className="admin-card stack" aria-labelledby="void-title">
      <h2 id="void-title" className="card-title">Annulation du ticket</h2>

      {voids.filter((v) => v.status !== 'pending').map((v) => (
        <p key={v.id} className="small">
          Demande n° {v.id} de {v.requested_by.name ?? '—'} ({v.reason}) — <strong>{TICKET_VOID_STATUS_LABELS[v.status]}</strong>
          {v.decided_by && <> par {v.decided_by.name}, le {dateTime(v.decided_at)}{v.decision_reason ? ` : ${v.decision_reason}` : ''}</>}
        </p>
      ))}

      {pending && <PendingVoid void={pending} />}

      {canRequest && (
        <details className="market-action">
          <summary>Demander l'annulation du ticket</summary>
          <ReasonForm
            id="ticket-void"
            title="Annuler le ticket"
            description={t.status === 'pending'
              ? "Rien ne change tant qu'un autre administrateur n'a pas validé. À la validation, toutes les sélections passent à la cote 1 et la mise est rendue au joueur."
              : "Ticket déjà réglé : rien ne change tant qu'un autre administrateur n'a pas validé. À la validation, le gain versé est repris (le solde peut devenir négatif) puis la mise est rendue."}
            submitLabel="Envoyer pour validation"
            variant="danger"
            successMessage="Demande envoyée : elle attend la validation d'un second administrateur."
            onSubmit={(reason, idempotencyKey) => request.mutateAsync({ reason, idempotencyKey })}
          />
        </details>
      )}
    </section>
  );
}

function ImpactText({ impact, currency }: { impact: TicketVoidImpact; currency: string }) {
  return (
    <>
      {TICKET_STATUS_LABELS[impact.from.status]} (<MoneyText value={{ minor: impact.from.payout_minor, currency }} />) →{' '}
      {TICKET_STATUS_LABELS[impact.to.status]} (<MoneyText value={{ minor: impact.to.payout_minor, currency }} />) ; portefeuille du joueur :{' '}
      <MoneyText value={{ minor: impact.ledger_delta_minor, currency }} signed />
    </>
  );
}

/** Demande en attente : effet prévu, validation (jamais par le demandeur) ou refus / retrait. */
function PendingVoid({ void: v }: { void: TicketVoid }) {
  const { data: me } = useMe();
  const detail = useTicketVoid(v.id);
  const decide = useDecideTicketVoid(v.id);
  const mine = me?.id === v.requested_by.id;
  const canApprove = Boolean(me?.permissions.includes('tickets.void_approve')) && !mine;
  const canReject = mine || Boolean(me?.permissions.includes('tickets.void_approve'));
  const preview = detail.data?.preview;

  return (
    <div className="stack-sm">
      <Alert tone="info">
        Annulation demandée par {v.requested_by.name ?? '—'}{v.created_at ? ` le ${dateTime(v.created_at)}` : ''} : {v.reason}.
        Elle attend la validation d'un second administrateur.
      </Alert>
      <ErrorAlert error={detail.error} onRetry={() => detail.refetch()} />
      {preview && <p className="small">Effet de la validation : <ImpactText impact={preview} currency={v.ticket.stake.currency} /></p>}
      {mine && <p className="muted small">Vous avez fait cette demande : un autre administrateur doit la valider.</p>}

      {canApprove && (
        <details className="market-action">
          <summary>Valider l'annulation</summary>
          <ReasonForm
            id={`void-approve-${v.id}`}
            title="Valider l'annulation"
            description="Le ticket est annulé maintenant : sélections à la cote 1, mise rendue, gain déjà versé repris."
            submitLabel="Valider l'annulation"
            variant="danger"
            successMessage="Ticket annulé."
            onSubmit={(reason, idempotencyKey) => decide.mutateAsync({ decision: 'approve', reason, idempotencyKey })}
          />
        </details>
      )}
      {canReject && (
        <details className="market-action">
          <summary>{mine ? 'Retirer ma demande' : 'Refuser la demande'}</summary>
          <ReasonForm
            id={`void-reject-${v.id}`}
            title={mine ? 'Retirer la demande' : 'Refuser la demande'}
            submitLabel={mine ? 'Retirer' : 'Refuser'}
            variant="secondary"
            successMessage="Demande close : le ticket ne change pas."
            onSubmit={(reason, idempotencyKey) => decide.mutateAsync({ decision: 'reject', reason, idempotencyKey })}
          />
        </details>
      )}
    </div>
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
