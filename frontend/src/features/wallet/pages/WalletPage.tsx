import { Link, useSearchParams } from 'react-router-dom';
import { Alert } from '../../../shared/components/Alert';
import { DataTable, type Column } from '../../../shared/components/DataTable';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { MoneyText } from '../../../shared/components/MoneyText';
import { Pagination } from '../../../shared/components/Pagination';
import { Spinner } from '../../../shared/components/Spinner';
import { ENTRY_LABELS, useWallet, useWalletTransactions, type LedgerEntry, type LedgerEntryType } from '../api/wallet';

const columns: Column<LedgerEntry>[] = [
  { key: 'date', header: 'Date', render: (e) => new Date(e.created_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) },
  {
    key: 'type',
    header: 'Opération',
    render: (e) => (
      <>
        {ENTRY_LABELS[e.type]}
        {e.related?.type === 'bet' && <> · <Link to={`/bets/${e.related.id}`}>ticket {e.related.id}</Link></>}
        {e.description && <div className="muted small">{e.description}</div>}
      </>
    ),
  },
  { key: 'amount', header: 'Montant', align: 'right', render: (e) => <MoneyText value={e.amount} signed /> },
  // Solde après opération : fourni par le serveur pour chaque écriture.
  { key: 'balance', header: 'Solde', align: 'right', render: (e) => <MoneyText value={e.balance_after} /> },
];

const TYPES = Object.keys(ENTRY_LABELS) as LedgerEntryType[];

/** Portefeuille du joueur connecté. Aucun calcul de solde côté client : tout vient de l'API. */
export function WalletPage() {
  const [params, setParams] = useSearchParams();
  const page = Math.max(1, Number(params.get('page')) || 1);
  const typeParam = params.get('type');
  const type = TYPES.includes(typeParam as LedgerEntryType) ? (typeParam as LedgerEntryType) : undefined;

  const wallet = useWallet();
  const transactions = useWalletTransactions({ page, type });

  function update(next: { page?: number; type?: string }) {
    const merged = new URLSearchParams(params);
    if (next.type !== undefined) {
      if (next.type) merged.set('type', next.type); else merged.delete('type');
      merged.delete('page');
    }
    if (next.page !== undefined) merged.set('page', String(next.page));
    setParams(merged);
  }

  return (
    <section className="stack">
      <h1>Portefeuille</h1>

      {wallet.isPending && <Spinner />}
      <ErrorAlert error={wallet.error} onRetry={() => wallet.refetch()} />
      {wallet.data && (
        <div className="balance-card">
          <span className="muted">Solde disponible</span>
          <strong className="balance"><MoneyText value={wallet.data.balance} /></strong>
          {wallet.data.simulated && <span className="badge">Fonds fictifs</span>}
        </div>
      )}
      {wallet.data?.status === 'frozen' && (
        <Alert tone="warning" title="Portefeuille gelé">Aucune mise n'est possible pour le moment. Contactez le support.</Alert>
      )}

      <div className="toolbar">
        <h2>Historique</h2>
        <div className="field field-inline">
          <label htmlFor="type-filter">Type</label>
          <select id="type-filter" value={type ?? ''} onChange={(e) => update({ type: e.target.value })}>
            <option value="">Toutes les opérations</option>
            {TYPES.map((t) => <option key={t} value={t}>{ENTRY_LABELS[t]}</option>)}
          </select>
        </div>
      </div>

      <ErrorAlert error={transactions.error} onRetry={() => transactions.refetch()} />
      <DataTable
        caption="Historique des opérations"
        columns={columns}
        rows={transactions.data?.data}
        rowKey={(e) => e.id}
        loading={transactions.isFetching}
        empty="Aucune opération pour le moment."
      />
      <Pagination meta={transactions.data?.meta} onPage={(p) => update({ page: p })} />
    </section>
  );
}
