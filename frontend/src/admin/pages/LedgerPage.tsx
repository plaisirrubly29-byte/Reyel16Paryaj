import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useMe } from '../../features/auth/api/auth';
import { Button } from '../../shared/components/Button';
import { DataTable, type Column } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { MoneyText } from '../../shared/components/MoneyText';
import { Pagination } from '../../shared/components/Pagination';
import { dateTime } from '../api/events';
import { useLedger, useRequestFinanceCorrection, type FinanceLedgerEntry } from '../api/finance';
import { LEDGER_TYPE_LABELS, type AdminLedgerEntry } from '../api/tickets';
import { FinanceNav } from '../components/FinanceNav';
import { PageHeader } from '../components/PageHeader';
import { ReasonForm } from '../components/ReasonForm';

const TYPES = Object.keys(LEDGER_TYPE_LABELS) as AdminLedgerEntry['type'][];
const DATE = /^\d{4}-\d{2}-\d{2}$/;

function columns(canCorrect: boolean): Column<FinanceLedgerEntry>[] {
  return [
    {
      key: 'entry',
      header: 'Écriture',
      render: (e) => (
        <div className="cell-stack">
          <span>n° {e.id} · {LEDGER_TYPE_LABELS[e.type] ?? e.type}</span>
          <span className="muted small nowrap">{dateTime(e.created_at)}</span>
        </div>
      ),
    },
    { key: 'player', header: 'Joueur', render: (e) => <Link to={`/admin/users/${e.user_id}`}>n° {e.user_id}</Link> },
    {
      key: 'reference',
      header: 'Référence',
      render: (e) => (
        <div className="cell-stack">
          <span className="mono small">{e.reference}</span>
          {e.related?.type === 'bet' && <Link className="small" to={`/admin/tickets/${e.related.id}`}>Ticket n° {e.related.id}</Link>}
          {e.reversal_of_id && <span className="muted small">Compense l'écriture n° {e.reversal_of_id}</span>}
          {e.reversed_by_id && <span className="text-warning small">Compensée par l'écriture n° {e.reversed_by_id}</span>}
        </div>
      ),
    },
    { key: 'amount', header: 'Montant', align: 'right', render: (e) => <MoneyText value={e.amount} signed /> },
    { key: 'balance', header: 'Solde après', align: 'right', render: (e) => <MoneyText value={e.balance_after} /> },
    ...(canCorrect ? [{ key: 'action', header: 'Correction', render: (e: FinanceLedgerEntry) => (e.correctable ? <CorrectionRequest entry={e} /> : <span className="muted small">—</span>) }] : []),
  ];
}

/** Demande de compensation d'une écriture (finance.correct) : un autre administrateur devra la valider. */
function CorrectionRequest({ entry }: { entry: FinanceLedgerEntry }) {
  const request = useRequestFinanceCorrection();

  return (
    <details className="market-action">
      <summary>Demander une correction</summary>
      {request.data
        ? <p className="small">Demande <Link to={`/admin/finance/corrections/${request.data.id}`}>n° {request.data.id}</Link> envoyée : elle attend la validation d'un second administrateur.</p>
        : (
          <ReasonForm
            id={`correct-${entry.id}`}
            title={`Compenser l'écriture n° ${entry.id}`}
            description={<>Une écriture inverse de <MoneyText value={{ ...entry.amount, minor: -entry.amount.minor }} signed /> sera passée après validation par un autre administrateur.</>}
            submitLabel="Envoyer la demande"
            onSubmit={(reason, idempotencyKey) => request.mutateAsync({ ledgerEntryId: entry.id, reason, idempotencyKey })}
          />
        )}
    </details>
  );
}

/** Grand livre (ledger.view) : écritures filtrables, demande de correction (finance.correct). */
export function LedgerPage() {
  const { data: me } = useMe();
  const [params, setParams] = useSearchParams();
  const userId = /^\d+$/.test(params.get('user_id') ?? '') ? params.get('user_id')! : undefined;
  const reference = params.get('reference') ?? '';
  const type = TYPES.includes(params.get('type') as AdminLedgerEntry['type']) ? (params.get('type') as AdminLedgerEntry['type']) : undefined;
  const from = DATE.test(params.get('from') ?? '') ? params.get('from')! : undefined;
  const to = DATE.test(params.get('to') ?? '') ? params.get('to')! : undefined;
  const page = Math.max(1, Number(params.get('page')) || 1);

  const [draft, setDraft] = useState({ userId: userId ?? '', reference });
  const ledger = useLedger({ user_id: userId, type, reference: reference || undefined, from, to, page });
  const canCorrect = Boolean(me?.permissions.includes('finance.correct'));

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
    update({ user_id: draft.userId.trim() || undefined, reference: draft.reference.trim() || undefined });
  }

  const filtered = Boolean(userId || reference || type || from || to);
  const total = ledger.data?.meta.total;

  return (
    <section className="admin-page">
      <PageHeader
        title="Finance"
        subtitle={total === undefined ? 'Grand livre, rapprochement et corrections.' : `${total.toLocaleString('fr-FR')} écriture${total > 1 ? 's' : ''}${filtered ? ' correspondant aux filtres' : ''}.`}
      />
      <FinanceNav />

      <div className="admin-card">
        <form className="filters filters-ledger" onSubmit={submit} role="search">
          <div className="field">
            <label htmlFor="ledger-user">Joueur (n°)</label>
            <input id="ledger-user" inputMode="numeric" value={draft.userId} onChange={(e) => setDraft({ ...draft, userId: e.target.value })} />
          </div>
          <div className="field">
            <label htmlFor="ledger-reference">Référence exacte</label>
            <input id="ledger-reference" value={draft.reference} onChange={(e) => setDraft({ ...draft, reference: e.target.value })} />
          </div>
          <div className="field">
            <label htmlFor="ledger-type">Type</label>
            <select id="ledger-type" value={type ?? ''} onChange={(e) => update({ type: e.target.value || undefined })}>
              <option value="">Tous</option>
              {TYPES.map((t) => <option key={t} value={t}>{LEDGER_TYPE_LABELS[t]}</option>)}
            </select>
          </div>
          <div className="field">
            <label htmlFor="ledger-from">Du</label>
            <input id="ledger-from" type="date" value={from ?? ''} onChange={(e) => update({ from: e.target.value || undefined })} />
          </div>
          <div className="field">
            <label htmlFor="ledger-to">Au</label>
            <input id="ledger-to" type="date" value={to ?? ''} onChange={(e) => update({ to: e.target.value || undefined })} />
          </div>
          <Button type="submit" variant="secondary">Rechercher</Button>
        </form>

        {filtered && (
          <div className="filter-chips">
            <button type="button" className="link-button" onClick={() => { setDraft({ userId: '', reference: '' }); setParams(new URLSearchParams()); }}>
              Effacer les filtres
            </button>
          </div>
        )}

        <ErrorAlert error={ledger.error} onRetry={() => ledger.refetch()} />
        <DataTable
          caption="Grand livre"
          columns={columns(canCorrect)}
          rows={ledger.data?.data}
          rowKey={(e) => e.id}
          loading={ledger.isFetching}
          empty={filtered ? 'Aucune écriture ne correspond à ces critères.' : 'Aucune écriture pour le moment.'}
        />
        <Pagination meta={ledger.data?.meta} onPage={(p) => update({ page: String(p) })} />
      </div>
    </section>
  );
}
