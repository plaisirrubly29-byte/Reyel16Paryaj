import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { RequireAdmin } from '../../admin/components/RequireAdmin';
import { AlertsBell, GlobalSearch, ServiceStatus, UserMenu } from '../../admin/components/TopbarTools';
import { sectionFor, visibleSections, type AdminSection } from '../../admin/navigation';
import { initials, ROLE_LABELS } from '../../admin/roles';
import { useLogout, useMe } from '../../features/auth/api/auth';
import { Icon } from '../../shared/components/Icon';
import { BrandLogo } from './BrandLogo';

const COLLAPSED_KEY = 'r16p.admin.sidebar-collapsed';

function readCollapsed(): boolean {
  try {
    return localStorage.getItem(COLLAPSED_KEY) === '1';
  } catch {
    return false;
  }
}

export function AdminLayout() {
  return (
    <RequireAdmin>
      <AdminShell />
    </RequireAdmin>
  );
}

function AdminShell() {
  const { pathname } = useLocation();
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(readCollapsed);
  const [lastPath, setLastPath] = useState(pathname);
  const { data: user } = useMe();
  const logout = useLogout();
  const navigate = useNavigate();

  // Le tiroir mobile se referme à chaque changement de page.
  if (pathname !== lastPath) {
    setLastPath(pathname);
    setDrawerOpen(false);
  }

  useEffect(() => {
    try {
      localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0');
    } catch {
      // Stockage indisponible (navigation privée) : la préférence reste en mémoire.
    }
  }, [collapsed]);

  useEffect(() => {
    if (!drawerOpen) return;
    const close = (e: KeyboardEvent) => e.key === 'Escape' && setDrawerOpen(false);
    window.addEventListener('keydown', close);
    return () => window.removeEventListener('keydown', close);
  }, [drawerOpen]);

  // Menu filtré selon les permissions reçues de l'API (GET /me), regroupé par thème.
  const sections = visibleSections(user?.permissions ?? []);
  const groups = sections.reduce<Record<string, AdminSection[]>>((acc, s) => ({ ...acc, [s.group]: [...(acc[s.group] ?? []), s] }), {});
  const current = sectionFor(pathname);
  const roles = user?.roles.map((r) => ROLE_LABELS[r] ?? r).join(', ');

  function signOut() {
    navigate('/admin/login', { replace: true });
    logout.mutate();
  }

  return (
    <div className={['admin-shell', collapsed && 'is-collapsed', drawerOpen && 'drawer-open'].filter(Boolean).join(' ')}>
      <aside className="admin-sidebar" aria-label="Barre latérale">
        <div className="admin-brand">
          <NavLink to="/admin" className="admin-brand-link" aria-label="Administration — vue d'ensemble">
            <BrandLogo />
            <span className="admin-brand-mini" aria-hidden="true">R16</span>
          </NavLink>
          <span className="admin-brand-tag">Admin</span>
        </div>

        <nav id="admin-nav" className="admin-nav" aria-label="Administration">
          {Object.entries(groups).map(([group, items]) => (
            <div key={group} className="admin-nav-group">
              <p className="admin-nav-title">{group}</p>
              {items.map((s) => (
                <NavLink key={s.to} to={s.to} end={s.end} title={collapsed ? s.label : undefined}>
                  <Icon name={s.icon} size={19} />
                  <span className="admin-nav-label">{s.label}</span>
                </NavLink>
              ))}
            </div>
          ))}
        </nav>

        <div className="admin-sidebar-foot">
          <Link to="/" className="admin-nav-link" title={collapsed ? 'Voir le site' : undefined}>
            <Icon name="external" size={19} />
            <span className="admin-nav-label">Voir le site</span>
          </Link>
          <button type="button" className="admin-nav-link" onClick={signOut} title={collapsed ? 'Se déconnecter' : undefined}>
            <Icon name="logout" size={19} />
            <span className="admin-nav-label">Se déconnecter</span>
          </button>
        </div>
      </aside>

      <button type="button" className="admin-scrim" aria-label="Fermer le menu" tabIndex={-1} onClick={() => setDrawerOpen(false)} />

      <div className="admin-main">
        <header className="admin-topbar">
          <button
            type="button"
            className="admin-icon-btn admin-menu-btn"
            aria-expanded={drawerOpen}
            aria-controls="admin-nav"
            onClick={() => setDrawerOpen((o) => !o)}
          >
            <Icon name="menu" size={20} />
            <span className="sr-only">Menu</span>
          </button>
          <button
            type="button"
            className="admin-icon-btn admin-collapse-btn"
            aria-pressed={collapsed}
            onClick={() => setCollapsed((c) => !c)}
          >
            <Icon name="sidebar" size={20} />
            <span className="sr-only">{collapsed ? 'Déplier la barre latérale' : 'Replier la barre latérale'}</span>
          </button>

          <div className="admin-topbar-title">
            <span className="admin-crumb">Administration</span>
            <strong>{current?.label ?? 'Administration'}</strong>
          </div>

          <GlobalSearch />

          <div className="admin-topbar-tools">
            <ServiceStatus />
            <AlertsBell />
            <UserMenu name={user?.name} initials={initials(user?.name)} roles={roles} onSignOut={signOut} />
          </div>
        </header>

        <main className="admin-content">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
