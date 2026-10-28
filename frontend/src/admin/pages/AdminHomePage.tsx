import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api, type Paginated } from '../../shared/api/client';
import { Alert } from '../../shared/components/Alert';
import { DataTable } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { useMe } from '../../features/auth/api/auth';
import { ADMIN_SECTIONS, canAccess } from '../navigation';

type AuditEntry = {
  id: string;
  action: string;
  actor: { id: string; email: string | null } | null;
  subject: { type: string; id: string } | null;
  reason: string | null;
  created_at: string;
};

/** Vue d'ensemble : raccourcis selon les permissions, actions récentes, éléments à venir. */
export function AdminHomePage() {
  const { data: user } = useMe();
  const permissions = user?.permissions ?? [];
  const canReadAudit = permissions.includes('audit.view');

  const audit = useQuery({
    queryKey: ['admin', 'audit-logs', { per_page: 5 }],
    queryFn: ({ signal }) => api<Paginated<AuditEntry>>('/admin/audit-logs', { signal, query: { per_page: 5 } }),
    enabled: canReadAudit,
  });

  const shortcuts = ADMIN_SECTIONS.filter((s) => s.to !== '/admin' && canAccess(permissions, s.permissions));

  return (
    <section className="stack">
      <h1>Vue d'ensemble</h1>

      <div className="cards">
        <article className="card">
          <h2>Synchronisation des données</h2>
          <p className="muted">État du fournisseur (The Odds API), fraîcheur des cotes et marchés suspendus.</p>
          <span className="badge">Disponible avec P3-03 / P3-04</span>
        </article>
        <article className="card">
          <h2>Opérations en attente</h2>
          <p className="muted">Résultats et corrections financières en attente de double validation.</p>
          <span className="badge">Disponible avec P5-06 / P6-04</span>
        </article>
      </div>

      {shortcuts.length > 0 && (
        <nav className="shortcuts" aria-label="Raccourcis">
          {shortcuts.map((s) => <Link key={s.to} to={s.to} className="btn btn-secondary">{s.label}</Link>)}
        </nav>
      )}

      {canReadAudit && (
        <>
          <h2>Actions récentes</h2>
          <ErrorAlert error={audit.error} onRetry={() => audit.refetch()} />
          <DataTable
            caption="Actions récentes du journal d'audit"
            rows={audit.data?.data}
            rowKey={(e) => e.id}
            loading={audit.isFetching}
            empty="Aucune action journalisée."
            columns={[
              { key: 'date', header: 'Date', render: (e) => new Date(e.created_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) },
              { key: 'action', header: 'Action', render: (e) => <code>{e.action}</code> },
              { key: 'actor', header: 'Par', render: (e) => e.actor?.email ?? 'Système' },
              { key: 'reason', header: 'Motif', render: (e) => e.reason ?? '—' },
            ]}
          />
        </>
      )}

      {shortcuts.length === 0 && !canReadAudit && (
        <Alert tone="info">Aucune section n'est associée à votre rôle pour le moment.</Alert>
      )}
    </section>
  );
}
