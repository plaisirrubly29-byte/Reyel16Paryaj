import { Link, useSearchParams } from 'react-router-dom';
import { DataTable, type Column } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Pagination } from '../../shared/components/Pagination';
import { dateTime } from '../api/events';
import {
  deviationLabel, matchName, OVERRIDE_APPROVAL_THRESHOLD_PERCENT, OVERRIDE_STATUS_LABELS, useOddsOverrides,
  type OddsOverride, type OddsOverrideStatus,
} from '../api/odds';
import { PageHeader } from '../components/PageHeader';

const STATUSES = Object.keys(OVERRIDE_STATUS_LABELS) as OddsOverrideStatus[];

export function OverrideStatusBadge({ status }: { status: OddsOverrideStatus }) {
  const tone = { pending: 'status-suspended', active: 'status-active', rejected: 'status-closed', ended: '', expired: '' }[status];

  return <span className={['badge', tone].filter(Boolean).join(' ')}>{OVERRIDE_STATUS_LABELS[status]}</span>;
}

const columns: Column<OddsOverride>[] = [
  {
    key: 'match',
    header: 'Match',
    render: (o) => (
      <div className="cell-stack">
        <Link to={`/admin/odds/${o.id}`}>{matchName(o)}</Link>
        <span className="muted small">Demande n° {o.id} · {dateTime(o.requested_at)}</span>
      </div>
    ),
  },
  { key: 'selection', header: 'Sélection', render: (o) => o.selection.name ?? '—' },
  {
    key: 'odds',
    header: 'Cote',
    render: (o) => (
      <span className="nowrap">
        {o.computed_odds} → <strong>{o.odds}</strong> <span className="muted small">({deviationLabel(o.deviation_percent)})</span>
      </span>
    ),
  },
  { key: 'by', header: 'Demandée par', render: (o) => o.requested_by?.name ?? '—' },
  { key: 'until', header: "Jusqu'au", render: (o) => <span className="nowrap">{dateTime(o.expires_at)}</span> },
  { key: 'status', header: 'Statut', render: (o) => <OverrideStatusBadge status={o.status} /> },
];

/**
 * Cotes ajustées (V2-D03, D-18) : cotes fixées à la main, celles à valider d'abord. Toutes par défaut ; filtre
 * par statut dans l'URL. On fixe une cote depuis la fiche du match.
 */
export function OddsOverridesPage() {
  const [params, setParams] = useSearchParams();
  const statusParam = params.get('status');
  const status = STATUSES.includes(statusParam as OddsOverrideStatus) ? (statusParam as OddsOverrideStatus) : undefined;
  const page = Math.max(1, Number(params.get('page')) || 1);
  const overrides = useOddsOverrides({ status, page });

  function update(changes: Record<string, string | undefined>) {
    const next = new URLSearchParams(params);
    for (const [key, value] of Object.entries(changes)) {
      if (value) next.set(key, value); else next.delete(key);
    }
    if (!('page' in changes)) next.delete('page');
    setParams(next);
  }

  return (
    <section className="admin-page">
      <PageHeader
        title="Cotes ajustées"
        subtitle={`Cotes fixées à la main par l'administration. Au-delà de ±${OVERRIDE_APPROVAL_THRESHOLD_PERCENT} % d'écart avec la cote calculée, un second administrateur valide.`}
      />

      <div className="admin-card">
        <div className="filters">
          <div className="field">
            <label htmlFor="override-filter">Statut</label>
            <select id="override-filter" value={status ?? 'all'} onChange={(e) => update({ status: e.target.value === 'all' ? undefined : e.target.value })}>
              <option value="all">Toutes (à valider d'abord)</option>
              {STATUSES.map((s) => <option key={s} value={s}>{OVERRIDE_STATUS_LABELS[s]}</option>)}
            </select>
          </div>
        </div>
        <p className="muted small">
          Pour fixer une cote, ouvrez le match dans <Link to="/admin/events">Événements & marchés</Link>.
        </p>

        <ErrorAlert error={overrides.error} onRetry={() => overrides.refetch()} />
        <DataTable
          caption="Cotes fixées à la main"
          columns={columns}
          rows={overrides.data?.data}
          rowKey={(o) => o.id}
          loading={overrides.isFetching}
          empty={status === 'pending' ? 'Aucune cote à valider.' : 'Aucune cote fixée à la main.'}
        />
        <Pagination meta={overrides.data?.meta} onPage={(p) => update({ page: String(p) })} />
      </div>
    </section>
  );
}
