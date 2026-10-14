import { NavLink, Outlet } from 'react-router-dom';

// Les permissions sont vérifiées par l'API ; ce menu n'est qu'un confort d'affichage.
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
  return (
    <div className="admin-shell">
      <aside className="sidebar">
        <NavLink to="/admin" className="brand">R16P Admin</NavLink>
        <nav>
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
