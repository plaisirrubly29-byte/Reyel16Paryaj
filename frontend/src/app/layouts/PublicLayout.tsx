import { Link, NavLink, Outlet, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { useEffect, useState, type FormEvent } from 'react';
import { useMe } from '../../features/auth/api/auth';
import { BetSlipProvider, useBetSlip } from '../../features/betting/slip/BetSlipContext';
import { SLIP_COLLAPSE_QUERY } from '../../features/betting/slip/BetSlipPanel';
import { Icon } from '../../shared/components/Icon';
import { useMediaQuery } from '../../shared/hooks/useMediaQuery';
import { BrandLogo } from './BrandLogo';
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
    <Link to="/" className="brand" aria-label="REYEL 16 PARYAJ — accueil">
      <BrandLogo />
    </Link>
  );
}

/** Recherche d'équipe ou de compétition : ouvre la liste des matchs filtrée (?q=). */
function HeaderSearch() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [value, setValue] = useState(params.get('q') ?? '');

  function submit(e: FormEvent) {
    e.preventDefault();
    const q = value.trim();
    navigate(q ? `/sports?q=${encodeURIComponent(q)}` : '/sports');
  }

  return (
    <form className="header-search" role="search" onSubmit={submit}>
      <label className="sr-only" htmlFor="header-search">Rechercher une équipe ou une compétition</label>
      <button type="submit" className="header-search-btn" aria-label="Lancer la recherche"><Icon name="search" size={18} /></button>
      <input id="header-search" type="search" placeholder="Équipe, championnat…" value={value} onChange={(e) => setValue(e.target.value)} />
    </form>
  );
}

function PublicShell() {
  const { data: user } = useMe();
  const slip = useBetSlip();
  const { pathname } = useLocation();
  const mobile = useMediaQuery(SLIP_COLLAPSE_QUERY);
  const [params] = useSearchParams();
  const liveActive = params.get('tab') === 'live' && (pathname === '/' || pathname === '/sports');
  const sportsActive = !liveActive && (pathname === '/' || pathname.startsWith('/sports') || pathname.startsWith('/events'));

  // Le panneau du coupon (mobile) se referme quand on change de page.
  useEffect(() => { slip.setPanelOpen(false); }, [pathname]); // eslint-disable-line react-hooks/exhaustive-deps

  const count = slip.selections.length;

  return (
    <div className="shell">
      {/* En-tête unique et collant : logo, navigation, recherche, compte. Sous 960 px : logo et boutons (navigation en bas). */}
      <header className="site-header">
        <div className="site-header-inner">
          <Brand />
          <span className="demo-pill" title="Version de démonstration — fonds fictifs uniquement">
            Démo<span className="sr-only"> : version de démonstration, fonds fictifs uniquement</span>
          </span>

          <nav className="main-nav" aria-label="Navigation principale">
            <Link to="/" className={sportsActive ? 'active' : undefined} aria-current={sportsActive ? 'page' : undefined}>
              <Icon name="sports" size={20} />Sports
            </Link>
            <Link to="/?tab=live" className={liveActive ? 'active' : undefined} aria-current={liveActive ? 'page' : undefined}>
              <span className="nav-live-dot" aria-hidden="true" />Live
            </Link>
            {user && <NavLink to="/bets"><Icon name="tickets" size={20} />Mes paris</NavLink>}
            {user?.is_admin && <NavLink to="/admin"><Icon name="shield" size={20} />Administration</NavLink>}
          </nav>

          <HeaderSearch />

          <div className="header-actions">
            {user ? (
              <>
                <NavLink to="/wallet" className="header-wallet"><Icon name="wallet" size={18} />Portefeuille</NavLink>
                <NavLink to="/account" className="header-user" aria-label={`Mon compte (${user.name})`}>
                  <span className="avatar" aria-hidden="true">{user.name.charAt(0).toUpperCase()}</span>
                  <span className="user-name">{user.name}</span>
                </NavLink>
              </>
            ) : (
              <>
                <NavLink to="/login" className="btn btn-sm header-login-btn">Connexion</NavLink>
                <NavLink to="/register" className="btn btn-sm btn-green header-register">S&apos;inscrire</NavLink>
              </>
            )}
          </div>
        </div>
      </header>

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
          <span className="tab-icon"><Icon name="cart" /></span>
          Panier
          {count > 0 && <span className="tab-badge" aria-label={`${count} sélection${count > 1 ? 's' : ''}`}>{count}</span>}
        </button>
        <NavLink to="/bets"><span className="tab-icon"><Icon name="tickets" /></span>Mes paris</NavLink>
        <NavLink to={user ? '/account' : '/login'}><span className="tab-icon"><Icon name="user" /></span>{user ? 'Compte' : 'Connexion'}</NavLink>
      </nav>
      )}
    </div>
  );
}
