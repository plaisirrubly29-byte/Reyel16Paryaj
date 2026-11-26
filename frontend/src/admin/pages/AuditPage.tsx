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

/** Champs connus du détail, affichés en clair ; le reste n'apparaît que dans les données techniques. */
const DETAIL_LABELS: Record<string, string> = {
  login: 'Identifiant saisi',
  reason: 'Cause',
  method: 'Méthode',
  sessions_closed: 'Appareils',
  mfa_enabled: 'MFA',
  consecutive_failures: 'Échecs consécutifs',
  logged_out: 'Session fermée',
  count: 'Nombre',
};

const DETAIL_VALUES: Record<string, string> = {
  wrong_password: 'mot de passe incorrect',
  unknown_account: 'compte inconnu',
  account_suspended: 'compte suspendu',
  account_excluded: 'compte auto-exclu',
  recovery_code: 'code de secours',
  totp: 'application d\'authentification',
};

const ICON_PROPS = { width: 14, height: 14, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true } as const;

/** Identifiant déjà masqué par le serveur (« ****1234 » ou « a***@domaine ») : présenté sans le recopier en clair. */
function maskedLogin(login: string, field: unknown) {
  return field === 'email' ? (
    <span className="audit-login">
      <svg {...ICON_PROPS}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" /></svg>
      <span className="sr-only">E-mail </span>{login}
    </span>
  ) : (
    <span className="audit-login">
      <svg {...ICON_PROPS}><rect x="6" y="2" width="12" height="20" rx="2" /><path d="M11 18h2" /></svg>
      <span className="sr-only">Téléphone </span>{login.replace(/^\*+/, '•••• ')}
    </span>
  );
}

function detailValue(key: string, value: unknown, all: Record<string, unknown>) {
  if (key === 'login' && typeof value === 'string') return maskedLogin(value, all.field);
  if (key === 'sessions_closed') return value ? 'tous déconnectés' : 'aucun';
  if (key === 'mfa_enabled') return value ? 'activé' : 'désactivé';
  if (typeof value === 'boolean') return value ? 'oui' : 'non';
  if (typeof value === 'string' || typeof value === 'number') return DETAIL_VALUES[String(value)] ?? String(value);
  return null;
}

function AuditDetail({ log }: { log: AuditLogEntry }) {
  const all: Record<string, unknown> = { ...log.before, ...log.after, ...log.metadata };
  const rows = Object.entries(all)
    .filter(([key]) => key in DETAIL_LABELS)
    .map(([key, value]) => [key, detailValue(key, value, all)] as const)
    .filter(([, value]) => value !== null);

  return (
    <details className="audit-detail">
      <summary>Détail</summary>
      <dl className="audit-facts">
        {rows.map(([key, value]) => (
          <div key={key}><dt>{DETAIL_LABELS[key]}</dt><dd>{value}</dd></div>
        ))}
        <div><dt>Adresse IP</dt><dd className="mono">{log.ip_address ?? '—'}</dd></div>
      </dl>
      <details className="audit-tech">
        <summary>Données techniques</summary>
        {log.before && <><strong className="small">Avant</strong><pre>{json(log.before)}</pre></>}
        {log.after && <><strong className="small">Après</strong><pre>{json(log.after)}</pre></>}
        {log.metadata && <><strong className="small">Contexte</strong><pre>{json(log.metadata)}</pre></>}
        <span className="muted small">Requête <span className="mono">{log.request_id ?? '—'}</span></span>
      </details>
    </details>
  );
}

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
        {(l.before || l.after || l.metadata) && <AuditDetail log={l} />}
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
        <form className="filters filters-audit" onSubmit={submit} role="search">
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
          <Button type="submit" variant="secondary" className="btn-search">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" aria-hidden="true">
              <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" />
            </svg>
            Rechercher
          </Button>
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
        <div className="audit-table">
          <DataTable
            caption="Journal d'audit"
            columns={columns}
            rows={logs.data?.data}
            rowKey={(l) => l.id}
            loading={logs.isFetching}
            empty={filtered ? 'Aucune entrée ne correspond à ces critères.' : 'Journal vide.'}
          />
        </div>
        <Pagination meta={logs.data?.meta} onPage={(p) => update({ page: String(p) })} />
      </div>
    </section>
  );
}
