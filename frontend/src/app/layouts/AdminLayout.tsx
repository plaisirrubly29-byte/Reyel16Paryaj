import { useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { RequireAdmin } from '../../admin/components/RequireAdmin';
import { visibleSections } from '../../admin/navigation';
import { useLogout, useMe } from '../../features/auth/api/auth';

const ROLE_LABELS: Record<string, string> = {
  super_admin: 'Super Admin',
  operations: 'Opérations',
  finance: 'Finance',
  support: 'Support',
  compliance: 'Conformité',
};

export function AdminLayout() {
  return (
    <RequireAdmin>
      <AdminShell />
    </RequireAdmin>
  );
}

function AdminShell() {
  const [open, setOpen] = useState(false);
  const { pathname } = useLocation();
  const [lastPath, setLastPath] = useState(pathname);
  const { data: user } = useMe();
  const logout = useLogout();
  const navigate = useNavigate();

  if (pathname !== lastPath) {
    setLastPath(pathname);
    setOpen(false);
  }

  // Menu filtré selon les permissions reçues de l'API (GET /me).
  const sections = visibleSections(user?.permissions ?? []);

  return (
    <div className="admin-shell">
      <aside className="sidebar">
        <div className="sidebar-head">
          <NavLink to="/admin" className="brand">R16P Admin</NavLink>
          <button
            type="button"
            className="menu-toggle"
            aria-expanded={open}
            aria-controls="admin-nav"
            onClick={() => setOpen((o) => !o)}
          >
            <span className="sr-only">Menu</span>☰
          </button>
        </div>
        <nav id="admin-nav" className={open ? 'open' : undefined} aria-label="Administration">
          {sections.map((s) => (
            <NavLink key={s.to} to={s.to} end={s.end}>{s.label}</NavLink>
          ))}
          <hr />
          <span className="muted small">
            {user?.name} · {user?.roles.map((r) => ROLE_LABELS[r] ?? r).join(', ')}
          </span>
          <Link to="/">Retour au site</Link>
          <button
            type="button"
            className="link-button"
            onClick={() => { navigate('/login', { replace: true }); logout.mutate(); }}
          >
            Se déconnecter
          </button>
        </nav>
      </aside>
      <main className="content">
        <Outlet />
      </main>
    </div>
  );
}
