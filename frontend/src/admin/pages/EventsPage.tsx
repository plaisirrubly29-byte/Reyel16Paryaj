import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Button } from '../../shared/components/Button';
import { DataTable, type Column } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Pagination } from '../../shared/components/Pagination';
import {
  EVENT_STATUS_LABELS, MARKET_STATUS_LABELS, dateTime, since, useAdminEvents,
  type AdminEvent, type EventStatus, type MarketStatus,
} from '../api/events';
import { EventStatusBadge } from '../components/MarketStatusBadge';
import { PageHeader } from '../components/PageHeader';

const EVENT_STATUSES = Object.keys(EVENT_STATUS_LABELS) as EventStatus[];
const MARKET_STATUSES = Object.keys(MARKET_STATUS_LABELS) as MarketStatus[];
const DATE = /^\d{4}-\d{2}-\d{2}$/;


const columns: Column<AdminEvent>[] = [
  {
    key: 'match',
    header: 'Match',
    render: (e) => (
      <div className="cell-stack">
        <Link to={`/admin/events/${e.id}`}>{e.home_team} – {e.away_team}</Link>
        <span className="muted small">{e.sport.name} · {e.competition.name}{e.competition.country ? ` (${e.competition.country})` : ''}</span>
      </div>
    ),
  },
  { key: 'starts', header: 'Début', render: (e) => <span className="nowrap">{dateTime(e.starts_at)}</span> },
  {
    key: 'status',
    header: 'Statut',
    render: (e) => (
      <div className="cell-stack">
        <EventStatusBadge status={e.status} />
        {e.score && <span className="small">{e.score.home} – {e.score.away}</span>}
      </div>
    ),
  },
  {
    key: 'markets',
    header: 'Marchés',
    render: (e) => (
      <span className="small">
        {e.markets_count.open} ouvert{e.markets_count.open > 1 ? 's' : ''}
        {e.markets_count.suspended > 0 && <strong className="text-warning"> · {e.markets_count.suspended} suspendu{e.markets_count.suspended > 1 ? 's' : ''}</strong>}
      </span>
    ),
  },
  {
    key: 'origin',
    header: 'Origine et synchronisation',
    render: (e) => (
      <div className="cell-stack">
        <span className="small"><span className="mono">{e.origin.provider}</span> · <span className="mono">{e.origin.external_id}</span></span>
        <span className="muted small" title={e.last_synced_at ? dateTime(e.last_synced_at) : undefined}>Synchronisé {since(e.last_synced_at)}</span>
      </div>
    ),
  },
];

/**
 * Événements et marchés (permission events.view) : tous statuts, origine et fraîcheur de la synchronisation.
 * Filtres et page conservés dans l'URL.
 */
export function EventsPage() {
  const [params, setParams] = useSearchParams();
  const search = params.get('search') ?? '';
  const status = EVENT_STATUSES.includes(params.get('status') as EventStatus) ? (params.get('status') as EventStatus) : undefined;
  const marketStatus = MARKET_STATUSES.includes(params.get('market_status') as MarketStatus) ? (params.get('market_status') as MarketStatus) : undefined;
  const date = DATE.test(params.get('date') ?? '') ? params.get('date')! : undefined;
  const page = Math.max(1, Number(params.get('page')) || 1);

  const [draft, setDraft] = useState(search);
  const events = useAdminEvents({ search: search || undefined, status, market_status: marketStatus, date, page });

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
    update({ search: draft.trim() || undefined });
  }

  const filtered = Boolean(search || status || marketStatus || date);
  const total = events.data?.meta.total;

  return (
    <section className="admin-page">
      <PageHeader
        title="Événements & marchés"
        subtitle={total === undefined ? 'Matchs, cotes et suspension des marchés.' : `${total.toLocaleString('fr-FR')} match${total > 1 ? 's' : ''}${filtered ? ' correspondant aux filtres' : ''}.`}
      />

      <div className="admin-card">
        <form className="filters filters-tickets" onSubmit={submit} role="search">
          <div className="field">
            <label htmlFor="event-search">Équipe ou identifiant</label>
            <input id="event-search" type="search" placeholder="Arsenal" value={draft} onChange={(e) => setDraft(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="event-status">Statut</label>
            <select id="event-status" value={status ?? ''} onChange={(e) => update({ status: e.target.value || undefined })}>
              <option value="">Tous</option>
              {EVENT_STATUSES.map((s) => <option key={s} value={s}>{EVENT_STATUS_LABELS[s]}</option>)}
            </select>
          </div>
          <div className="field">
            <label htmlFor="event-market-status">Marchés</label>
            <select id="event-market-status" value={marketStatus ?? ''} onChange={(e) => update({ market_status: e.target.value || undefined })}>
              <option value="">Tous</option>
              {MARKET_STATUSES.map((s) => <option key={s} value={s}>Au moins un {MARKET_STATUS_LABELS[s].toLowerCase()}</option>)}
            </select>
          </div>
          <div className="field">
            <label htmlFor="event-date">Jour</label>
            <input id="event-date" type="date" value={date ?? ''} onChange={(e) => update({ date: e.target.value || undefined })} />
          </div>
          <Button type="submit" variant="secondary">Rechercher</Button>
        </form>

        {filtered && (
          <div className="filter-chips">
            <button type="button" className="link-button" onClick={() => { setDraft(''); setParams(new URLSearchParams()); }}>
              Effacer les filtres
            </button>
          </div>
        )}

        <ErrorAlert error={events.error} onRetry={() => events.refetch()} />
        <DataTable
          caption="Événements"
          columns={columns}
          rows={events.data?.data}
          rowKey={(e) => e.id}
          loading={events.isFetching}
          empty={filtered ? 'Aucun match ne correspond à ces critères.' : 'Aucun match synchronisé pour le moment.'}
        />
        <Pagination meta={events.data?.meta} onPage={(p) => update({ page: String(p) })} />
      </div>
    </section>
  );
}
