import { useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';

const links = [
  { to: '/sports', label: 'Sports' },
  { to: '/bets', label: 'Mes tickets' },
  { to: '/wallet', label: 'Portefeuille' },
  { to: '/login', label: 'Connexion' },
];

export function PublicLayout() {
  const [open, setOpen] = useState(false);
  const { pathname } = useLocation();
  const [lastPath, setLastPath] = useState(pathname);

  // Referme le menu mobile après une navigation.
  if (pathname !== lastPath) {
    setLastPath(pathname);
    setOpen(false);
  }

  return (
    <div className="shell">
      <header className="topbar">
        <NavLink to="/" className="brand">REYEL 16 PAYAJ</NavLink>
        <button
          type="button"
          className="menu-toggle"
          aria-expanded={open}
          aria-controls="main-nav"
          onClick={() => setOpen((o) => !o)}
        >
          <span className="sr-only">Menu</span>☰
        </button>
        <nav id="main-nav" className={open ? 'open' : undefined}>
          {links.map((l) => <NavLink key={l.to} to={l.to}>{l.label}</NavLink>)}
        </nav>
      </header>
      <div className="demo-banner" role="note">Version de démonstration — fonds fictifs uniquement.</div>
      <main className="content">
        <Outlet />
      </main>
    </div>
  );
}
