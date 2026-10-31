import { Link, useSearchParams } from 'react-router-dom';
import { DataTable, type Column } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { MoneyText } from '../../shared/components/MoneyText';
import { Pagination } from '../../shared/components/Pagination';
import { dateTime } from '../api/events';
import { FINANCE_CORRECTION_STATUS_LABELS, useFinanceCorrections, type FinanceCorrection, type FinanceCorrectionStatus } from '../api/finance';
import { LEDGER_TYPE_LABELS } from '../api/tickets';
import { FinanceNav } from '../components/FinanceNav';
import { PageHeader } from '../components/PageHeader';

const STATUSES = Object.keys(FINANCE_CORRECTION_STATUS_LABELS) as FinanceCorrectionStatus[];

export function FinanceCorrectionStatusBadge({ status }: { status: FinanceCorrectionStatus }) {
  const tone = { pending: 'status-suspended', approved: 'status-active', rejected: 'status-closed' }[status];

  return <span className={`badge ${tone}`}>{FINANCE_CORRECTION_STATUS_LABELS[status]}</span>;
}

const columns: Column<FinanceCorrection>[] = [
  {
    key: 'correction',
    header: 'Correction',
    render: (c) => (
      <div className="cell-stack">
        <Link to={`/admin/finance/corrections/${c.id}`}>Correction n° {c.id}</Link>
        <span className="muted small">{dateTime(c.requested_at)}</span>
      </div>
    ),
  },
  {
    key: 'entry',
    header: 'Écriture compensée',
    render: (c) => (
      <div className="cell-stack">
        <span>n° {c.ledger_entry.id} · {LEDGER_TYPE_LABELS[c.ledger_entry.type] ?? c.ledger_entry.type}</span>
        <Link className="small" to={`/admin/users/${c.ledger_entry.user_id}`}>Joueur n° {c.ledger_entry.user_id}</Link>
      </div>
    ),
  },
  { key: 'amount', header: 'Montant', align: 'right', render: (c) => <MoneyText value={c.ledger_entry.amount} signed /> },
  { key: 'by', header: 'Demandée par', render: (c) => c.requested_by.name ?? `n° ${c.requested_by.id}` },
  { key: 'status', header: 'Statut', render: (c) => <FinanceCorrectionStatusBadge status={c.status} /> },
];

/** Corrections financières (ledger.view) : « À valider » par défaut, validation par un second administrateur. */
export function FinanceCorrectionsPage() {
  const [params, setParams] = useSearchParams();
  const statusParam = params.get('status');
  const status = statusParam === 'all' ? undefined : STATUSES.includes(statusParam as FinanceCorrectionStatus) ? (statusParam as FinanceCorrectionStatus) : 'pending';
  const page = Math.max(1, Number(params.get('page')) || 1);
  const corrections = useFinanceCorrections({ status, page });
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
        title="Finance"
        subtitle={status === 'pending' && total !== undefined
          ? `${total} correction${total > 1 ? 's' : ''} financière${total > 1 ? 's' : ''} en attente de validation.`
          : 'Corrections financières : demande, validation par un second administrateur, écriture compensatoire.'}
      />
      <FinanceNav />

      <div className="admin-card">
        <div className="filters">
          <div className="field">
            <label htmlFor="finance-correction-filter">Demandes</label>
            <select id="finance-correction-filter" value={status ?? 'all'} onChange={(e) => update({ status: e.target.value })}>
              {STATUSES.map((s) => <option key={s} value={s}>{FINANCE_CORRECTION_STATUS_LABELS[s]}</option>)}
              <option value="all">Toutes</option>
            </select>
          </div>
        </div>
        <p className="muted small">
          Pour demander une correction, choisissez l'écriture dans le <Link to="/admin/finance">grand livre</Link>.
        </p>

        <ErrorAlert error={corrections.error} onRetry={() => corrections.refetch()} />
        <DataTable
          caption="Corrections financières"
          columns={columns}
          rows={corrections.data?.data}
          rowKey={(c) => c.id}
          loading={corrections.isFetching}
          empty={status === 'pending' ? 'Aucune correction en attente.' : 'Aucune correction.'}
        />
        <Pagination meta={corrections.data?.meta} onPage={(p) => update({ page: String(p) })} />
      </div>
    </section>
  );
}
