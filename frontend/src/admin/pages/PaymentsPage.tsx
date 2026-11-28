import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { DataTable, type Column } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { MoneyText } from '../../shared/components/MoneyText';
import { Pagination } from '../../shared/components/Pagination';
import { dateTime } from '../api/events';
import {
  DIRECTION_LABELS,
  PAYMENT_STATUSES,
  paymentStatusLabel,
  paymentStatusTone,
  providerLabel,
  usePayments,
  type AdminPayment,
  type PaymentStatus,
} from '../api/payments';
import { PageHeader } from '../components/PageHeader';

const DATE = /^\d{4}-\d{2}-\d{2}$/;

export function PaymentStatusBadge({ payment }: { payment: Pick<AdminPayment, 'status' | 'direction'> }) {
  return <span className={`badge ${paymentStatusTone(payment.status)}`}>{paymentStatusLabel(payment.status, payment.direction)}</span>;
}

const columns: Column<AdminPayment>[] = [
  {
    key: 'payment',
    header: 'Paiement',
    render: (p) => (
      <div className="cell-stack">
        <Link to={`/admin/payments/${p.id}`} className="mono">{p.reference}</Link>
        <span className="muted small">{DIRECTION_LABELS[p.direction]} · {dateTime(p.created_at)}</span>
      </div>
    ),
  },
  { key: 'player', header: 'Joueur', render: (p) => <Link to={`/admin/users/${p.user_id}`}>Joueur n° {p.user_id}</Link> },
  { key: 'amount', header: 'Montant', align: 'right', render: (p) => <MoneyText value={p.amount} /> },
  { key: 'provider', header: 'Prestataire', render: (p) => providerLabel(p.provider) },
  { key: 'completed', header: 'Terminé le', render: (p) => (p.completed_at ? <span className="small">{dateTime(p.completed_at)}</span> : '—') },
  { key: 'status', header: 'Statut', render: (p) => <PaymentStatusBadge payment={p} /> },
];

/**
 * Suivi des paiements (ledger.view : Super Admin, Finance, Conformité) — V2-B03, #145. Lecture seule : les retraits sont
 * versés automatiquement par le prestataire (décision du 2026-11-17). Retraits par défaut ; filtres dans l'URL.
 */
export function PaymentsPage() {
  const [params, setParams] = useSearchParams();
  const directionParam = params.get('direction');
  const direction = directionParam === 'all' ? undefined : directionParam === 'deposit' ? 'deposit' : 'payout';
  const status = PAYMENT_STATUSES.includes(params.get('status') as PaymentStatus) ? (params.get('status') as PaymentStatus) : undefined;
  const userId = /^\d+$/.test(params.get('user_id') ?? '') ? params.get('user_id')! : undefined;
  const reference = params.get('reference')?.trim() || undefined;
  const from = DATE.test(params.get('from') ?? '') ? params.get('from')! : undefined;
  const to = DATE.test(params.get('to') ?? '') ? params.get('to')! : undefined;
  const page = Math.max(1, Number(params.get('page')) || 1);
  const payments = usePayments({ direction, status, userId, reference, from, to, page });
  const [search, setSearch] = useState(reference ?? '');

  function update(changes: Record<string, string | undefined>) {
    const next = new URLSearchParams(params);
    for (const [key, value] of Object.entries(changes)) {
      if (value) next.set(key, value); else next.delete(key);
    }
    if (!('page' in changes)) next.delete('page');
    setParams(next);
  }

  const title = direction === 'deposit' ? 'Dépôts' : direction === 'payout' ? 'Retraits' : 'Paiements';

  return (
    <section className="admin-page">
      <PageHeader
        title="Paiements"
        subtitle={direction === 'payout'
          ? 'Retraits des joueurs, versés automatiquement par le prestataire. À surveiller : « Demandé », « Envoi au prestataire » et « À vérifier ».'
          : 'Dépôts et retraits passés par le prestataire de paiement, du plus récent au plus ancien. Lecture seule.'}
      />

      <div className="admin-card">
        <div className="filters">
          <div className="field">
            <label htmlFor="pay-direction">Sens</label>
            <select id="pay-direction" value={direction ?? 'all'} onChange={(e) => update({ direction: e.target.value })}>
              <option value="payout">Retraits</option>
              <option value="deposit">Dépôts</option>
              <option value="all">Tous</option>
            </select>
          </div>
          <div className="field">
            <label htmlFor="pay-status">Statut</label>
            <select id="pay-status" value={status ?? ''} onChange={(e) => update({ status: e.target.value || undefined })}>
              <option value="">Tous les statuts</option>
              {PAYMENT_STATUSES.map((s) => <option key={s} value={s}>{paymentStatusLabel(s, direction ?? 'payout')}</option>)}
            </select>
          </div>
          <div className="field">
            <label htmlFor="pay-from">Du</label>
            <input id="pay-from" type="date" value={from ?? ''} onChange={(e) => update({ from: e.target.value || undefined })} />
          </div>
          <div className="field">
            <label htmlFor="pay-to">Au</label>
            <input id="pay-to" type="date" value={to ?? ''} onChange={(e) => update({ to: e.target.value || undefined })} />
          </div>
          <form className="field" onSubmit={(e) => { e.preventDefault(); update({ reference: search.trim() || undefined }); }}>
            <label htmlFor="pay-reference">Référence</label>
            <input id="pay-reference" type="search" placeholder="pay_… ou référence du prestataire" value={search}
              onChange={(e) => setSearch(e.target.value)} />
          </form>
          {userId && (
            <p className="small">
              Joueur n° {userId} · <button type="button" className="link-button" onClick={() => update({ user_id: undefined })}>Tous les joueurs</button>
            </p>
          )}
        </div>

        <ErrorAlert error={payments.error} onRetry={() => payments.refetch()} />
        <DataTable
          caption={title}
          columns={columns}
          rows={payments.data?.data}
          rowKey={(p) => p.id}
          loading={payments.isFetching}
          empty={reference ? 'Aucun paiement avec cette référence.' : `Aucun ${direction === 'deposit' ? 'dépôt' : direction === 'payout' ? 'retrait' : 'paiement'} sur ces critères.`}
        />
        <Pagination meta={payments.data?.meta} onPage={(p) => update({ page: String(p) })} />
      </div>
    </section>
  );
}
