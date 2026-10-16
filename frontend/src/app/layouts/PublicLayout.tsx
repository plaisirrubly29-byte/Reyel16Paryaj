import { useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { useMe } from '../../features/auth/api/auth';
import { BetSlipProvider } from '../../features/betting/slip/BetSlipContext';
import { SiteFooter } from './SiteFooter';

export function PublicLayout() {
  const [open, setOpen] = useState(false);
  const { pathname } = useLocation();
  const [lastPath, setLastPath] = useState(pathname);
  const { data: user } = useMe();

  // Referme le menu mobile après une navigation.
  if (pathname !== lastPath) {
    setLastPath(pathname);
    setOpen(false);
  }

  return (
    <BetSlipProvider>
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
          <NavLink to="/sports">Sports</NavLink>
          {user ? (
            <>
              <NavLink to="/bets">Mes tickets</NavLink>
              <NavLink to="/wallet">Portefeuille</NavLink>
              {user.is_admin && <NavLink to="/admin">Administration</NavLink>}
              <NavLink to="/account">{user.name}</NavLink>
            </>
          ) : (
            <>
              <NavLink to="/login">Connexion</NavLink>
              <NavLink to="/register">Inscription</NavLink>
            </>
          )}
        </nav>
      </header>
      <div className="demo-banner" role="note">Version de démonstration — fonds fictifs uniquement.</div>
      <main className="content">
        <Outlet />
      </main>
      <SiteFooter />
    </div>
    </BetSlipProvider>
  );
}
