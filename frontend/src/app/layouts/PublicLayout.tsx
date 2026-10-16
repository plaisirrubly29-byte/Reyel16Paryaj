import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { useEffect } from 'react';
import { useMe } from '../../features/auth/api/auth';
import { BetSlipProvider, useBetSlip } from '../../features/betting/slip/BetSlipContext';
import { SLIP_COLLAPSE_QUERY } from '../../features/betting/slip/BetSlipPanel';
import { Icon } from '../../shared/components/Icon';
import { useMediaQuery } from '../../shared/hooks/useMediaQuery';
import { SiteFooter } from './SiteFooter';

export function PublicLayout() {
  return (
    <BetSlipProvider>
      <PublicShell />
    </BetSlipProvider>
  );
}

function Brand() {
  return (
    <NavLink to="/" className="brand" end aria-label="REYEL 16 PAYAJ — accueil">
      <span className="brand-mark" aria-hidden="true">R16</span>
      <span className="brand-name">Reyel 16 <span>Payaj</span></span>
    </NavLink>
  );
}

function PublicShell() {
  const { data: user } = useMe();
  const slip = useBetSlip();
  const { pathname } = useLocation();
  const mobile = useMediaQuery(SLIP_COLLAPSE_QUERY);

  // Le panneau du coupon (mobile) se referme quand on change de page.
  useEffect(() => { slip.setPanelOpen(false); }, [pathname]); // eslint-disable-line react-hooks/exhaustive-deps

  const count = slip.selections.length;

  return (
    <div className="shell">
      <header className="site-header">
        <div className="site-header-inner">
          <Brand />
          <nav className="main-nav" aria-label="Navigation principale">
            <NavLink to="/" end>Accueil</NavLink>
            <NavLink to="/sports">Sports</NavLink>
            {user && <NavLink to="/bets">Mes tickets</NavLink>}
            {user && <NavLink to="/wallet">Portefeuille</NavLink>}
            {user?.is_admin && <NavLink to="/admin">Administration</NavLink>}
          </nav>
          <div className="header-actions">
            {user ? (
              <NavLink to="/account" className="header-user" aria-label={`Mon compte (${user.name})`}>
                <span className="avatar" aria-hidden="true">{user.name.charAt(0).toUpperCase()}</span>
                <span className="user-name">{user.name}</span>
              </NavLink>
            ) : (
              <>
                <NavLink to="/login" className="btn btn-secondary btn-sm">Connexion</NavLink>
                <NavLink to="/register" className="btn btn-primary btn-sm">Inscription</NavLink>
              </>
            )}
          </div>
        </div>
      </header>
      <div className="demo-banner" role="note">Version de démonstration — fonds fictifs uniquement.</div>

      <main className="content content-wide">
        <Outlet />
      </main>
      <SiteFooter />

      {/* Barre d'onglets mobile (sous 960 px), absente du DOM sur ordinateur. */}
      {mobile && (
      <nav className="tab-bar" aria-label="Navigation mobile">
        <NavLink to="/" end><span className="tab-icon"><Icon name="home" /></span>Accueil</NavLink>
        <NavLink to="/sports"><span className="tab-icon"><Icon name="ball" /></span>Sports</NavLink>
        <button
          type="button"
          aria-expanded={slip.panelOpen}
          aria-controls="betslip"
          onClick={() => slip.setPanelOpen(!slip.panelOpen)}
        >
          <span className="tab-icon"><Icon name="coupon" /></span>
          Coupon
          {count > 0 && <span className="tab-badge" aria-label={`${count} sélection${count > 1 ? 's' : ''}`}>{count}</span>}
        </button>
        <NavLink to="/bets"><span className="tab-icon"><Icon name="tickets" /></span>Tickets</NavLink>
        <NavLink to={user ? '/account' : '/login'}><span className="tab-icon"><Icon name="user" /></span>{user ? 'Compte' : 'Connexion'}</NavLink>
      </nav>
      )}
    </div>
  );
}
