import { useState, type FormEvent } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useMe } from '../../features/auth/api/auth';
import { Button } from '../../shared/components/Button';
import { DataTable, type Column } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Pagination } from '../../shared/components/Pagination';
import { AUDIT_ACTION_LABELS, exportAuditLogs, useAuditLogs, type AuditFilters, type AuditLogEntry } from '../api/audit';
import { dateTime } from '../api/events';
import { PageHeader } from '../components/PageHeader';
import { ReasonForm } from '../components/ReasonForm';

const ACTIONS = Object.keys(AUDIT_ACTION_LABELS);
const DATE = /^\d{4}-\d{2}-\d{2}$/;

const json = (value: unknown) => JSON.stringify(value, null, 2);

const columns: Column<AuditLogEntry>[] = [
  {
    key: 'when',
    header: 'Date',
    render: (l) => (
      <div className="cell-stack">
        <span className="nowrap">{dateTime(l.created_at)}</span>
        <span className="muted small">n° {l.id}</span>
      </div>
    ),
  },
  {
    key: 'action',
    header: 'Action',
    render: (l) => (
      <div className="cell-stack">
        <span>{AUDIT_ACTION_LABELS[l.action] ?? l.action}</span>
        <span className="mono small muted">{l.action}</span>
      </div>
    ),
  },
  { key: 'actor', header: 'Acteur', render: (l) => (l.actor ? <span>{l.actor.email ?? '—'} <span className="muted small">(n° {l.actor.id})</span></span> : <span className="muted">Système</span>) },
  { key: 'subject', header: 'Objet', render: (l) => (l.subject ? <span className="mono small">{l.subject.type} #{l.subject.id}</span> : '—') },
  {
    key: 'reason',
    header: 'Motif et détail',
    render: (l) => (
      <div className="cell-stack">
        {l.reason && <span className="small">{l.reason}</span>}
        {(l.before || l.after || l.metadata) && (
          <details className="audit-detail">
            <summary>Détail</summary>
            {l.before && <><strong className="small">Avant</strong><pre>{json(l.before)}</pre></>}
            {l.after && <><strong className="small">Après</strong><pre>{json(l.after)}</pre></>}
            {l.metadata && <><strong className="small">Contexte</strong><pre>{json(l.metadata)}</pre></>}
            <span className="muted small">IP {l.ip_address ?? '—'} · requête <span className="mono">{l.request_id ?? '—'}</span></span>
          </details>
        )}
      </div>
    ),
  },
];

/**
 * Journal d'audit (audit.view) : consultation elle-même journalisée ; export CSV des lignes filtrées
 * (audit.export) avec motif, journalisé aussi.
 */
export function AuditPage() {
  const { data: me } = useMe();
  const [params, setParams] = useSearchParams();
  const action = ACTIONS.includes(params.get('action') ?? '') ? params.get('action')! : undefined;
  const actorId = /^\d+$/.test(params.get('actor_id') ?? '') ? params.get('actor_id')! : undefined;
  const from = DATE.test(params.get('from') ?? '') ? params.get('from')! : undefined;
  const to = DATE.test(params.get('to') ?? '') ? params.get('to')! : undefined;
  const page = Math.max(1, Number(params.get('page')) || 1);

  const filters: AuditFilters = { action, actor_id: actorId, from, to };
  const [actorDraft, setActorDraft] = useState(actorId ?? '');
  const logs = useAuditLogs({ ...filters, page });
  const canExport = Boolean(me?.permissions.includes('audit.export'));

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
    update({ actor_id: actorDraft.trim() || undefined });
  }

  const filtered = Boolean(action || actorId || from || to);
  const total = logs.data?.meta.total;

  return (
    <section className="admin-page">
      <PageHeader
        title="Audit & rapports"
        subtitle={total === undefined ? 'Journal des actions sensibles.' : `${total.toLocaleString('fr-FR')} entrée${total > 1 ? 's' : ''}${filtered ? ' correspondant aux filtres' : ''}. Votre consultation est elle-même journalisée.`}
      />

      <div className="admin-card">
        <form className="filters filters-ledger" onSubmit={submit} role="search">
          <div className="field">
            <label htmlFor="audit-action">Action</label>
            <select id="audit-action" value={action ?? ''} onChange={(e) => update({ action: e.target.value || undefined })}>
              <option value="">Toutes</option>
              {ACTIONS.map((a) => <option key={a} value={a}>{AUDIT_ACTION_LABELS[a]}</option>)}
            </select>
          </div>
          <div className="field">
            <label htmlFor="audit-actor">Acteur (n°)</label>
            <input id="audit-actor" inputMode="numeric" value={actorDraft} onChange={(e) => setActorDraft(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="audit-from">Du</label>
            <input id="audit-from" type="date" value={from ?? ''} onChange={(e) => update({ from: e.target.value || undefined })} />
          </div>
          <div className="field">
            <label htmlFor="audit-to">Au</label>
            <input id="audit-to" type="date" value={to ?? ''} onChange={(e) => update({ to: e.target.value || undefined })} />
          </div>
          <Button type="submit" variant="secondary">Rechercher</Button>
        </form>

        {filtered && (
          <div className="filter-chips">
            <button type="button" className="link-button" onClick={() => { setActorDraft(''); setParams(new URLSearchParams()); }}>
              Effacer les filtres
            </button>
          </div>
        )}

        {canExport && (
          <details className="market-action">
            <summary>Exporter ces lignes (CSV)</summary>
            <ReasonForm
              id="audit-export"
              title="Exporter le journal"
              description={`Exporte ${total === undefined ? 'les lignes' : `les ${total.toLocaleString('fr-FR')} ligne${total > 1 ? 's' : ''}`} correspondant aux filtres (10 000 au plus). L'export est journalisé avec votre motif.`}
              submitLabel="Télécharger le CSV"
              variant="secondary"
              successMessage="Export téléchargé et journalisé."
              onSubmit={(reason) => exportAuditLogs(filters, reason)}
            />
          </details>
        )}

        <ErrorAlert error={logs.error} onRetry={() => logs.refetch()} />
        <DataTable
          caption="Journal d'audit"
          columns={columns}
          rows={logs.data?.data}
          rowKey={(l) => l.id}
          loading={logs.isFetching}
          empty={filtered ? 'Aucune entrée ne correspond à ces critères.' : 'Journal vide.'}
        />
        <Pagination meta={logs.data?.meta} onPage={(p) => update({ page: String(p) })} />
      </div>
    </section>
  );
}
