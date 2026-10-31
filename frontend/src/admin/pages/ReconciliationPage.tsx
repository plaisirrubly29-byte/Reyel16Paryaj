import { Link } from 'react-router-dom';
import { Alert } from '../../shared/components/Alert';
import { Button } from '../../shared/components/Button';
import { DataTable, type Column } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { MoneyText } from '../../shared/components/MoneyText';
import { Spinner } from '../../shared/components/Spinner';
import { dateTime } from '../api/events';
import { DISCREPANCY_LABELS, useReconciliation, type Discrepancy, type Reconciliation } from '../api/finance';
import { LEDGER_TYPE_LABELS } from '../api/tickets';
import { FinanceNav } from '../components/FinanceNav';
import { PageHeader } from '../components/PageHeader';

type Total = Reconciliation['totals'][number];

const totalColumns: Column<Total>[] = [
  { key: 'type', header: 'Type', render: (t) => LEDGER_TYPE_LABELS[t.type] ?? t.type },
  { key: 'currency', header: 'Devise', render: (t) => t.currency },
  { key: 'entries', header: 'Écritures', align: 'right', render: (t) => t.entries.toLocaleString('fr-FR') },
  { key: 'sum', header: 'Total', align: 'right', render: (t) => <MoneyText value={t.sum} signed /> },
];

const discrepancyColumns: Column<Discrepancy>[] = [
  { key: 'kind', header: 'Écart', render: (d) => <strong>{DISCREPANCY_LABELS[d.kind] ?? d.kind}</strong> },
  {
    key: 'where',
    header: 'Où',
    render: (d) => (
      <div className="cell-stack small">
        {d.ticket_id && <Link to={`/admin/tickets/${d.ticket_id}`} className="mono">{d.reference ?? `Ticket n° ${d.ticket_id}`}</Link>}
        {d.ledger_entry_id && <span>Écriture n° {d.ledger_entry_id}</span>}
        {d.wallet_id && <span className="muted">Portefeuille n° {d.wallet_id}</span>}
        {d.related && <span className="muted">Ticket n° {d.related.id} introuvable</span>}
      </div>
    ),
  },
  { key: 'expected', header: 'Attendu', align: 'right', render: (d) => (d.expected ? <MoneyText value={d.expected} /> : '—') },
  { key: 'actual', header: 'Constaté', align: 'right', render: (d) => (d.actual ? <MoneyText value={d.actual} /> : '—') },
];

/** Rapprochement du ledger (ledger.view), calculé à chaque ouverture : contrôles, totaux et écarts. */
export function ReconciliationPage() {
  const reconciliation = useReconciliation();
  const r = reconciliation.data;

  return (
    <section className="admin-page">
      <PageHeader
        title="Finance"
        subtitle={r ? `Rapprochement calculé le ${dateTime(r.generated_at)}.` : 'Rapprochement du grand livre.'}
        actions={<Button variant="secondary" loading={reconciliation.isFetching} onClick={() => reconciliation.refetch()}>Relancer le contrôle</Button>}
      />
      <FinanceNav />

      {reconciliation.isPending && <Spinner label="Calcul du rapprochement" />}
      <ErrorAlert error={reconciliation.error} onRetry={() => reconciliation.refetch()} />

      {r && (
        <>
          <dl className="summary-grid">
            <div className="summary-item"><dt>Portefeuilles</dt><dd>{r.checked.wallets.toLocaleString('fr-FR')}</dd></div>
            <div className="summary-item"><dt>Écritures</dt><dd>{r.checked.ledger_entries.toLocaleString('fr-FR')}</dd></div>
            <div className="summary-item"><dt>Tickets</dt><dd>{r.checked.tickets.toLocaleString('fr-FR')}</dd></div>
            <div className="summary-item"><dt>Écarts</dt><dd className={r.discrepancies_count > 0 ? 'text-danger' : undefined}>{r.discrepancies_count.toLocaleString('fr-FR')}</dd></div>
          </dl>

          <section className="admin-card stack">
            <h2 className="card-title">Écarts détectés</h2>
            {r.discrepancies_count === 0
              ? <Alert tone="success">Aucun écart : chaque solde suit ses écritures et chaque ticket correspond à ses mouvements.</Alert>
              : (
                <>
                  <Alert tone="error" title={`${r.discrepancies_count} écart${r.discrepancies_count > 1 ? 's' : ''} à analyser`}>
                    Ne corrigez rien à la main : analysez chaque écart puis passez par une correction à double validation.
                    {r.truncated && ' Seuls les 100 premiers écarts de chaque contrôle sont listés.'}
                  </Alert>
                  <DataTable caption="Écarts" columns={discrepancyColumns} rows={r.discrepancies} rowKey={(d) => `${d.kind}-${d.ledger_entry_id ?? d.ticket_id}`} />
                </>
              )}
          </section>

          <section className="admin-card stack">
            <h2 className="card-title">Totaux par type d'écriture</h2>
            <DataTable caption="Totaux" columns={totalColumns} rows={r.totals} rowKey={(t) => `${t.currency}-${t.type}`} empty="Aucune écriture." />
          </section>
        </>
      )}
    </section>
  );
}
