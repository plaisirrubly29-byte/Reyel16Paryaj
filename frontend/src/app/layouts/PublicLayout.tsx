import { Link, NavLink, Outlet, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { useEffect, useState, type FormEvent } from 'react';
import { useMe } from '../../features/auth/api/auth';
import { useWallet } from '../../features/wallet/api/wallet';
import { MoneyText } from '../../shared/components/MoneyText';
import { BetSlipProvider, useBetSlip } from '../../features/betting/slip/BetSlipContext';
import { BetSlipPanel, SLIP_COLLAPSE_QUERY } from '../../features/betting/slip/BetSlipPanel';
import { CompetitionsMenu } from '../../features/catalogue/components/CompetitionsMenu';
import { useLotteryAvailable } from '../../features/lottery/api/lottery';
import { virtualGamesEnabled } from '../../features/virtual/api/virtualGames';
import { Icon } from '../../shared/components/Icon';
import { useMediaQuery } from '../../shared/hooks/useMediaQuery';
import { CookieBanner } from '../../features/legal/components/CookieBanner';
import { SessionReminder } from '../../features/responsible/components/SessionReminder';
import { BrandLogo } from './BrandLogo';
import { GamesTab } from './GamesMenu';
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

/** Accès au solde dans l'en-tête, avec le montant fourni par l'API (rendu seulement une fois connecté). */
function HeaderBalance() {
  const wallet = useWallet();

  return (
    <NavLink to="/wallet" className="header-wallet">
      <Icon name="wallet" size={18} />
      <span>Solde</span>
      {wallet.data && <strong className="header-wallet-amount"><MoneyText value={wallet.data.balance} /></strong>}
    </NavLink>
  );
}

function PublicShell() {
  const { data: user } = useMe();
  const slip = useBetSlip();
  const { pathname } = useLocation();
  const mobile = useMediaQuery(SLIP_COLLAPSE_QUERY);
  const [params] = useSearchParams();
  const lotteryAvailable = useLotteryAvailable();
  const liveActive = params.get('tab') === 'live' && (pathname === '/' || pathname === '/sports');
  const sportsActive = !liveActive && (pathname === '/' || pathname.startsWith('/sports') || pathname.startsWith('/events'));

  // Le panneau du coupon se referme quand on change de page, et avec Échap.
  useEffect(() => { slip.setPanelOpen(false); }, [pathname]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (!slip.panelOpen) return undefined;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') slip.setPanelOpen(false); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [slip.panelOpen]); // eslint-disable-line react-hooks/exhaustive-deps

  const count = slip.selections.length;
  const countLabel = `${count} sélection${count > 1 ? 's' : ''}`;

  return (
    <div className="shell">
      {/* En-tête unique et collant : logo, navigation, recherche, compte. Sous 960 px : logo et boutons (navigation en bas). */}
      <header className="site-header">
        <div className="site-header-inner">
          <Brand />

          <nav className="main-nav" aria-label="Navigation principale">
            <Link to="/" className={sportsActive ? 'active' : undefined} aria-current={sportsActive ? 'page' : undefined}>
              <Icon name="sports" size={20} />Sports
            </Link>
            <Link to="/?tab=live" className={liveActive ? 'active' : undefined} aria-current={liveActive ? 'page' : undefined}>
              <span className="nav-live-dot" aria-hidden="true" />Live
            </Link>
            {lotteryAvailable && <NavLink to="/borlette"><Icon name="lottery" size={20} />Borlette</NavLink>}
            {virtualGamesEnabled() && (
              // Libellé court : la barre est déjà pleine sur ordinateur une fois connecté (Mes paris, Administration).
              <NavLink to="/jeux-virtuels" aria-label="Jeux virtuels" title="Jeux virtuels"><Icon name="gamepad" size={20} />Virtuels</NavLink>
            )}
            {user && <NavLink to="/bets"><Icon name="tickets" size={20} />Mes paris</NavLink>}
            {user?.is_admin && <NavLink to="/admin"><Icon name="shield" size={20} />Administration</NavLink>}
          </nav>

          <CompetitionsMenu />

          <HeaderSearch />

          <div className="header-actions">
            {user ? (
              <>
                <HeaderBalance />
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
      <CookieBanner />
      <SessionReminder />

      {/* Panier : sur ordinateur, bouton flottant qui ouvre le panneau à droite ; sur mobile, onglet de la barre du bas. */}
      <BetSlipPanel />
      {!mobile && (
        <button
          type="button"
          className={`slip-fab${count > 0 ? ' has-items' : ''}`}
          aria-expanded={slip.panelOpen}
          aria-controls="betslip"
          onClick={() => slip.setPanelOpen(!slip.panelOpen)}
        >
          <Icon name="cart" size={24} />
          <span>Panier</span>
          <span key={count} className="slip-fab-count" aria-label={countLabel}>{count}</span>
        </button>
      )}

      {/* Barre d'onglets mobile (sous 960 px), absente du DOM sur ordinateur. Pas d'onglet « Accueil » : le site
          s'ouvre sur l'accueil et le logo y ramène ; à sa place, le menu des jeux. */}
      {mobile && (
      <nav className="tab-bar" aria-label="Navigation mobile">
        <GamesTab />
        <NavLink to="/sports"><span className="tab-icon"><Icon name="ball" /></span>Sports</NavLink>
        <button
          type="button"
          aria-expanded={slip.panelOpen}
          aria-controls="betslip"
          onClick={() => slip.setPanelOpen(!slip.panelOpen)}
        >
          <span className="tab-icon"><Icon name="cart" /></span>
          Panier
          {count > 0 && <span className="tab-badge" aria-label={countLabel}>{count}</span>}
        </button>
        <NavLink to="/bets"><span className="tab-icon"><Icon name="tickets" /></span>Mes paris</NavLink>
        <NavLink to={user ? '/account' : '/login'}><span className="tab-icon"><Icon name="user" /></span>{user ? 'Compte' : 'Connexion'}</NavLink>
      </nav>
      )}
    </div>
  );
}
