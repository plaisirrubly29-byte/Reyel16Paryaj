import { Link, useSearchParams } from 'react-router-dom';
import { DataTable, type Column } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Pagination } from '../../shared/components/Pagination';
import {
  CORRECTION_STATUS_LABELS, currentLabel, isFirstResult, MANUAL_RESULT_DELAY_HOURS, outcomeLabel, sourceLabel, useResultCorrections,
  type CorrectionStatus, type ResultCorrection,
} from '../api/results';
import { PageHeader } from '../components/PageHeader';
import { dateTime } from '../api/events';

const STATUSES = Object.keys(CORRECTION_STATUS_LABELS) as CorrectionStatus[];

export function CorrectionStatusBadge({ status }: { status: CorrectionStatus }) {
  const tone = { pending: 'status-suspended', approved: 'status-active', rejected: 'status-closed' }[status];

  return <span className={`badge ${tone}`}>{CORRECTION_STATUS_LABELS[status]}</span>;
}

const columns: Column<ResultCorrection>[] = [
  {
    key: 'match',
    header: 'Match',
    render: (c) => (
      <div className="cell-stack">
        <Link to={`/admin/settlements/${c.id}`}>{c.event.name}</Link>
        <span className="muted small">Demande n° {c.id} · {dateTime(c.created_at)}</span>
      </div>
    ),
  },
  {
    key: 'change',
    header: 'Demande',
    render: (c) => (isFirstResult(c)
      ? <span className="nowrap">Premier résultat : <strong>{outcomeLabel(c.proposed)}</strong></span>
      : <span className="nowrap">{currentLabel(c)} → <strong>{outcomeLabel(c.proposed)}</strong></span>),
  },
  {
    key: 'by',
    header: 'Demandée par',
    render: (c) => (c.requested_by ? c.requested_by.name : <span title="Correction envoyée par le fournisseur après règlement">{sourceLabel(c.source)}</span>),
  },
  { key: 'status', header: 'Statut', render: (c) => <CorrectionStatusBadge status={c.status} /> },
];

/**
 * Résultats & règlement : demandes de correction de résultat (P5-06), à valider par un second administrateur.
 * « À valider » par défaut ; filtre dans l'URL.
 */
export function SettlementsPage() {
  const [params, setParams] = useSearchParams();
  const statusParam = params.get('status');
  const status = statusParam === 'all' ? undefined : STATUSES.includes(statusParam as CorrectionStatus) ? (statusParam as CorrectionStatus) : 'pending';
  const page = Math.max(1, Number(params.get('page')) || 1);
  const corrections = useResultCorrections({ status, page });
  const total = corrections.data?.meta.total;

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
        title="Résultats & règlement"
        subtitle={status === 'pending' && total !== undefined
          ? `${total} demande${total > 1 ? 's' : ''} de résultat en attente de validation.`
          : 'Résultats saisis à la main et corrections : demande, validation par un second administrateur, règlement des tickets.'}
      />

      <div className="admin-card">
        <div className="filters">
          <div className="field">
            <label htmlFor="correction-filter">Demandes</label>
            <select id="correction-filter" value={status ?? 'all'} onChange={(e) => update({ status: e.target.value })}>
              {STATUSES.map((s) => <option key={s} value={s}>{CORRECTION_STATUS_LABELS[s]}</option>)}
              <option value="all">Toutes</option>
            </select>
          </div>
        </div>
        <p className="muted small">
          Pour saisir un résultat que le fournisseur n'a pas publié, ou demander une correction, ouvrez le match dans{' '}
          <Link to="/admin/events">Événements & marchés</Link> : un match commencé depuis plus de {MANUAL_RESULT_DELAY_HOURS} h sans résultat
          y est signalé « Résultat manquant ».
        </p>

        <ErrorAlert error={corrections.error} onRetry={() => corrections.refetch()} />
        <DataTable
          caption="Demandes de résultat"
          columns={columns}
          rows={corrections.data?.data}
          rowKey={(c) => c.id}
          loading={corrections.isFetching}
          empty={status === 'pending' ? 'Aucune demande en attente.' : 'Aucune demande.'}
        />
        <Pagination meta={corrections.data?.meta} onPage={(p) => update({ page: String(p) })} />
      </div>
    </section>
  );
}
