import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Button } from '../../shared/components/Button';
import { DataTable, type Column } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Pagination } from '../../shared/components/Pagination';
import { ROLE_LABELS, STATUS_LABELS, useAdminUsers, type AccountStatus, type AdminUser } from '../api/users';
import { initials } from '../roles';
import { PageHeader } from '../components/PageHeader';
import { StatusBadge } from '../components/StatusBadge';

const STATUSES = Object.keys(STATUS_LABELS) as AccountStatus[];
const ROLES = Object.keys(ROLE_LABELS);

const date = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('fr-FR') : '—');

const columns: Column<AdminUser>[] = [
  { key: 'id', header: 'N°', render: (u) => u.id },
  {
    key: 'name',
    header: 'Compte',
    render: (u) => (
      <div className="user-cell">
        <span className="user-avatar" aria-hidden="true">{initials(u.name)}</span>
        <div>
          <Link to={`/admin/users/${u.id}`}>{u.name}</Link>
          <div className="muted small">{u.email ?? 'Pas d’email'}</div>
        </div>
      </div>
    ),
  },
  { key: 'phone', header: 'Téléphone', render: (u) => <span className="mono">{u.phone}</span> },
  { key: 'type', header: 'Type', render: (u) => (u.is_admin ? 'Administrateur' : 'Joueur') },
  { key: 'status', header: 'Statut', render: (u) => <StatusBadge status={u.status} /> },
  { key: 'created', header: 'Inscription', render: (u) => date(u.created_at) },
  { key: 'login', header: 'Dernière connexion', render: (u) => date(u.last_login_at) },
];

/** Recherche de comptes (permission users.view). Filtres et page conservés dans l'URL. */
export function UsersPage() {
  const [params, setParams] = useSearchParams();
  const search = params.get('search') ?? '';
  const statusParam = params.get('status');
  const status = STATUSES.includes(statusParam as AccountStatus) ? (statusParam as AccountStatus) : undefined;
  const roleParam = params.get('role');
  const role = roleParam && ROLES.includes(roleParam) ? roleParam : undefined;
  const page = Math.max(1, Number(params.get('page')) || 1);

  const [draft, setDraft] = useState(search);
  const users = useAdminUsers({ search: search || undefined, status, role, page });

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
    update({ search: draft.trim() || undefined });
  }

  return (
    <section className="admin-page">
      <PageHeader
        title="Utilisateurs"
        subtitle={users.data ? `${users.data.meta.total.toLocaleString('fr-FR')} compte${users.data.meta.total > 1 ? 's' : ''}.` : 'Comptes joueurs et administrateurs.'}
      />

      <div className="admin-card">
        <form className="filters" onSubmit={submit} role="search">
          <div className="field">
            <label htmlFor="user-search">Rechercher</label>
            <input
              id="user-search"
              type="search"
              placeholder="Nom, email, téléphone ou n°"
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
            />
          </div>
          <div className="field">
            <label htmlFor="user-status">Statut</label>
            <select id="user-status" value={status ?? ''} onChange={(e) => update({ status: e.target.value || undefined })}>
              <option value="">Tous</option>
              {STATUSES.map((s) => <option key={s} value={s}>{STATUS_LABELS[s]}</option>)}
            </select>
          </div>
          <div className="field">
            <label htmlFor="user-role">Rôle</label>
            <select id="user-role" value={role ?? ''} onChange={(e) => update({ role: e.target.value || undefined })}>
              <option value="">Tous</option>
              {ROLES.map((r) => <option key={r} value={r}>{ROLE_LABELS[r]}</option>)}
            </select>
          </div>
          <Button type="submit" variant="secondary">Rechercher</Button>
        </form>
  
        <ErrorAlert error={users.error} onRetry={() => users.refetch()} />
        <DataTable
          caption="Comptes"
          columns={columns}
          rows={users.data?.data}
          rowKey={(u) => u.id}
          loading={users.isFetching}
          empty="Aucun compte ne correspond à ces critères."
        />
        <Pagination meta={users.data?.meta} onPage={(p) => update({ page: String(p) })} />
      </div>
    </section>
  );
}
