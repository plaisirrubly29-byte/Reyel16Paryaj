import { Link, useSearchParams } from 'react-router-dom';
import { DataTable, type Column } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { MoneyText } from '../../shared/components/MoneyText';
import { Pagination } from '../../shared/components/Pagination';
import { dateTime } from '../api/events';
import { AML_RULE_LABELS, AML_STATUS_LABELS, useAmlAlerts, type AmlAlert, type AmlAlertStatus, type AmlRule } from '../api/aml';
import { PageHeader } from '../components/PageHeader';

const STATUSES = Object.keys(AML_STATUS_LABELS) as AmlAlertStatus[];
const RULES = Object.keys(AML_RULE_LABELS) as AmlRule[];
const DATE = /^\d{4}-\d{2}-\d{2}$/;

export function AmlStatusBadge({ status }: { status: AmlAlertStatus }) {
  const tone = { open: 'status-suspended', dismissed: 'status-active', reported: 'status-closed' }[status];

  return <span className={`badge ${tone}`}>{AML_STATUS_LABELS[status]}</span>;
}

const columns: Column<AmlAlert>[] = [
  {
    key: 'alert',
    header: 'Alerte',
    render: (a) => (
      <div className="cell-stack">
        <Link to={`/admin/aml/${a.id}`}>{AML_RULE_LABELS[a.rule] ?? a.rule}</Link>
        <span className="muted small">Alerte n° {a.id} · {dateTime(a.created_at)}</span>
      </div>
    ),
  },
  {
    key: 'player',
    header: 'Joueur',
    render: (a) => (
      <div className="cell-stack">
        <span>{a.user.name ?? '—'}</span>
        <Link className="small" to={`/admin/users/${a.user.id}`}>Joueur n° {a.user.id}</Link>
      </div>
    ),
  },
  { key: 'amount', header: 'Montant', align: 'right', render: (a) => <MoneyText value={a.amount} /> },
  { key: 'reviewed', header: 'Décision', render: (a) => (a.reviewed_at ? <span className="small">{a.reviewed_by?.name ?? '—'} · {dateTime(a.reviewed_at)}</span> : '—') },
  { key: 'status', header: 'Statut', render: (a) => <AmlStatusBadge status={a.status} /> },
];

/**
 * File des alertes AML (aml.view, V2-C04) : « À examiner » par défaut, les plus récentes d'abord. Adresse utilisée par
 * le centre d'alertes (/admin/aml?status=open). Filtres dans l'URL.
 */
export function AmlAlertsPage() {
  const [params, setParams] = useSearchParams();
  const statusParam = params.get('status');
  const status = statusParam === 'all' ? undefined : STATUSES.includes(statusParam as AmlAlertStatus) ? (statusParam as AmlAlertStatus) : 'open';
  const rule = RULES.includes(params.get('rule') as AmlRule) ? (params.get('rule') as AmlRule) : undefined;
  const userId = /^\d+$/.test(params.get('user_id') ?? '') ? params.get('user_id')! : undefined;
  const from = DATE.test(params.get('from') ?? '') ? params.get('from')! : undefined;
  const to = DATE.test(params.get('to') ?? '') ? params.get('to')! : undefined;
  const page = Math.max(1, Number(params.get('page')) || 1);
  const alerts = useAmlAlerts({ status, rule, userId, from, to, page });
  const total = alerts.data?.meta.total;

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
        title="Alertes AML"
        subtitle={status === 'open' && total !== undefined
          ? (total === 0
            ? 'Aucune alerte à examiner.'
            : `${total} alerte${total > 1 ? 's' : ''} à examiner : classer (justifiée) ou signaler (soupçon), avec un motif.`)
          : 'Mouvements d\'argent inhabituels repérés automatiquement : gros dépôt, dépôts cumulés, retrait sans jeu.'}
      />

      <div className="admin-card">
        <div className="filters">
          <div className="field">
            <label htmlFor="aml-status">Statut</label>
            <select id="aml-status" value={status ?? 'all'} onChange={(e) => update({ status: e.target.value })}>
              {STATUSES.map((s) => <option key={s} value={s}>{AML_STATUS_LABELS[s]}</option>)}
              <option value="all">Toutes</option>
            </select>
          </div>
          <div className="field">
            <label htmlFor="aml-rule">Règle</label>
            <select id="aml-rule" value={rule ?? ''} onChange={(e) => update({ rule: e.target.value || undefined })}>
              <option value="">Toutes les règles</option>
              {RULES.map((r) => <option key={r} value={r}>{AML_RULE_LABELS[r]}</option>)}
            </select>
          </div>
          <div className="field">
            <label htmlFor="aml-from">Du</label>
            <input id="aml-from" type="date" value={from ?? ''} onChange={(e) => update({ from: e.target.value || undefined })} />
          </div>
          <div className="field">
            <label htmlFor="aml-to">Au</label>
            <input id="aml-to" type="date" value={to ?? ''} onChange={(e) => update({ to: e.target.value || undefined })} />
          </div>
          {userId && (
            <p className="small">
              Joueur n° {userId} · <button type="button" className="link-button" onClick={() => update({ user_id: undefined })}>Tous les joueurs</button>
            </p>
          )}
        </div>

        <ErrorAlert error={alerts.error} onRetry={() => alerts.refetch()} />
        <DataTable
          caption="Alertes AML"
          columns={columns}
          rows={alerts.data?.data}
          rowKey={(a) => a.id}
          loading={alerts.isFetching}
          empty={status === 'open' ? 'Aucune alerte à examiner.' : 'Aucune alerte.'}
        />
        <Pagination meta={alerts.data?.meta} onPage={(p) => update({ page: String(p) })} />
      </div>
    </section>
  );
}
