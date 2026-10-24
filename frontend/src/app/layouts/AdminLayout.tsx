import { useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';

// Les permissions sont vérifiées par l'API ; ce menu n'est qu'un confort d'affichage
// (il sera filtré selon les permissions reçues de l'API en P6-05).
const sections = [
  { to: '/admin', label: "Vue d'ensemble", end: true },
  { to: '/admin/events', label: 'Événements & marchés' },
  { to: '/admin/settlements', label: 'Résultats & règlement' },
  { to: '/admin/users', label: 'Utilisateurs' },
  { to: '/admin/tickets', label: 'Tickets' },
  { to: '/admin/finance', label: 'Finance' },
  { to: '/admin/settings', label: 'Paramètres' },
  { to: '/admin/audit', label: 'Audit & rapports' },
];

export function AdminLayout() {
  const [open, setOpen] = useState(false);
  const { pathname } = useLocation();
  const [lastPath, setLastPath] = useState(pathname);

  if (pathname !== lastPath) {
    setLastPath(pathname);
    setOpen(false);
  }

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
        <nav id="admin-nav" className={open ? 'open' : undefined}>
          {sections.map((s) => (
            <NavLink key={s.to} to={s.to} end={s.end}>{s.label}</NavLink>
          ))}
        </nav>
      </aside>
      <main className="content">
        <Outlet />
      </main>
    </div>
  );
}
