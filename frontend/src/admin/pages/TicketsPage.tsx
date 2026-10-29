import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Button } from '../../shared/components/Button';
import { DataTable, type Column } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { MoneyText } from '../../shared/components/MoneyText';
import { Pagination } from '../../shared/components/Pagination';
import { TICKET_STATUS_LABELS, useAdminTickets, type AdminTicket, type TicketStatus } from '../api/tickets';
import { PageHeader } from '../components/PageHeader';
import { TicketStatusBadge } from '../components/TicketStatusBadge';

const STATUSES = Object.keys(TICKET_STATUS_LABELS) as TicketStatus[];
const DATE = /^\d{4}-\d{2}-\d{2}$/;

const dateTime = (iso: string) => new Date(iso).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });

const columns: Column<AdminTicket>[] = [
  {
    key: 'reference',
    header: 'Ticket',
    render: (t) => (
      <div className="cell-stack">
        <Link to={`/admin/tickets/${t.id}`} className="mono nowrap">{t.reference}</Link>
        <span className="muted small nowrap">{t.type === 'single' ? 'Simple' : 'Combiné'} · {dateTime(t.placed_at)}</span>
      </div>
    ),
  },
  {
    key: 'player',
    header: 'Joueur',
    render: (t) => <Link to={`/admin/tickets?user_id=${t.user.id}`} title="Voir les tickets de ce joueur">{t.user.name ?? `#${t.user.id}`}</Link>,
  },
  { key: 'stake', header: 'Mise', align: 'right', render: (t) => <MoneyText value={t.stake} /> },
  { key: 'odds', header: 'Cote', align: 'right', render: (t) => t.total_odds },
  {
    key: 'payout',
    header: 'Gain',
    align: 'right',
    render: (t) => (t.payout ? <MoneyText value={t.payout} /> : <span className="muted" title="Gain potentiel"><MoneyText value={t.potential_payout} /></span>),
  },
  { key: 'status', header: 'Statut', render: (t) => <TicketStatusBadge status={t.status} /> },
];

/**
 * Recherche de tickets (permission tickets.view) par référence, statut, joueur et période.
 * Filtres et page conservés dans l'URL (lien partageable, retour arrière du navigateur).
 */
export function TicketsPage() {
  const [params, setParams] = useSearchParams();
  const reference = params.get('reference') ?? '';
  const statusParam = params.get('status');
  const status = STATUSES.includes(statusParam as TicketStatus) ? (statusParam as TicketStatus) : undefined;
  const userId = /^\d+$/.test(params.get('user_id') ?? '') ? params.get('user_id')! : undefined;
  const from = DATE.test(params.get('placed_from') ?? '') ? params.get('placed_from')! : undefined;
  const to = DATE.test(params.get('placed_to') ?? '') ? params.get('placed_to')! : undefined;
  const page = Math.max(1, Number(params.get('page')) || 1);

  const [draft, setDraft] = useState(reference);
  const tickets = useAdminTickets({ reference: reference || undefined, status, user_id: userId, placed_from: from, placed_to: to, page });

  function update(changes: Record<string, string | undefined>) {
    const next = new URLSearchParams(params);
    for (const [key, value] of Object.entries(changes)) {
      if (value) next.set(key, value); else next.delete(key);
    }
    if (!('page' in changes)) next.delete('page');
    setParams(next);
  }

  function submit(e: FormEvent) {
    e.preventDefault();
    update({ reference: draft.trim() || undefined });
  }

  const filtered = Boolean(reference || status || userId || from || to);
  const total = tickets.data?.meta.total;

  return (
    <section className="admin-page">
      <PageHeader
        title="Tickets"
        subtitle={total === undefined ? 'Paris placés par les joueurs.' : `${total.toLocaleString('fr-FR')} ticket${total > 1 ? 's' : ''}${filtered ? ' correspondant aux filtres' : ''}.`}
      />

      <div className="admin-card">
        <form className="filters filters-tickets" onSubmit={submit} role="search">
          <div className="field">
            <label htmlFor="ticket-reference">Référence</label>
            <input
              id="ticket-reference"
              type="search"
              placeholder="R16-7KQ2M9XAPB"
              autoCapitalize="characters"
              spellCheck={false}
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
            />
          </div>
          <div className="field">
            <label htmlFor="ticket-status">Statut</label>
            <select id="ticket-status" value={status ?? ''} onChange={(e) => update({ status: e.target.value || undefined })}>
              <option value="">Tous</option>
              {STATUSES.map((s) => <option key={s} value={s}>{TICKET_STATUS_LABELS[s]}</option>)}
            </select>
          </div>
          <div className="field">
            <label htmlFor="ticket-from">Placé du</label>
            <input id="ticket-from" type="date" value={from ?? ''} max={to} onChange={(e) => update({ placed_from: e.target.value || undefined })} />
          </div>
          <div className="field">
            <label htmlFor="ticket-to">au</label>
            <input id="ticket-to" type="date" value={to ?? ''} min={from} onChange={(e) => update({ placed_to: e.target.value || undefined })} />
          </div>
          <Button type="submit" variant="secondary">Rechercher</Button>
        </form>

        {(userId || filtered) && (
          <div className="filter-chips">
            {userId && (
              <button type="button" className="chip" onClick={() => update({ user_id: undefined })}>
                Joueur n° {userId} <span aria-hidden="true">×</span><span className="sr-only">(retirer ce filtre)</span>
              </button>
            )}
            {filtered && (
              <button type="button" className="link-button" onClick={() => { setDraft(''); setParams(new URLSearchParams()); }}>
                Effacer les filtres
              </button>
            )}
          </div>
        )}

        <ErrorAlert error={tickets.error} onRetry={() => tickets.refetch()} />
        <DataTable
          caption="Tickets"
          columns={columns}
          rows={tickets.data?.data}
          rowKey={(t) => t.id}
          loading={tickets.isFetching}
          empty={filtered ? 'Aucun ticket ne correspond à ces critères.' : 'Aucun ticket placé pour le moment.'}
        />
        <Pagination meta={tickets.data?.meta} onPage={(p) => update({ page: String(p) })} />
      </div>
    </section>
  );
}
