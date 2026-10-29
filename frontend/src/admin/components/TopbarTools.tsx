import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { Icon } from '../../shared/components/Icon';
import { Spinner } from '../../shared/components/Spinner';
import { useAlerts, type AdminAlert } from '../api/dashboard';
import { useGlobalSearch } from '../api/supervision';
import { TICKET_STATUS_LABELS, type TicketStatus } from '../api/tickets';
import { STATUS_LABELS, type AccountStatus } from '../api/users';

/** Ferme un menu déroulant au clic extérieur, à Échap et au changement de page. */
function useDropdown() {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const { pathname } = useLocation();
  const [lastPath, setLastPath] = useState(pathname);

  if (pathname !== lastPath) {
    setLastPath(pathname);
    setOpen(false);
  }

  useEffect(() => {
    if (!open) return;
    const onClick = (e: MouseEvent) => { if (!ref.current?.contains(e.target as Node)) setOpen(false); };
    const onKey = (e: globalThis.KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onClick);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('mousedown', onClick); document.removeEventListener('keydown', onKey); };
  }, [open]);

  return { open, setOpen, ref };
}

function useDebounced(value: string, delay = 250) {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const id = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(id);
  }, [value, delay]);
  return debounced;
}

/** Recherche globale : comptes, tickets et matchs, selon ce que le rôle peut consulter. */
export function GlobalSearch() {
  const { open, setOpen, ref } = useDropdown();
  const [q, setQ] = useState('');
  const term = useDebounced(q);
  const search = useGlobalSearch(term);
  const listId = useId();
  const navigate = useNavigate();
  const data = search.data;
  const empty = data && !data.users?.length && !data.tickets?.length && !data.events?.length;

  function onKeyDown(e: KeyboardEvent<HTMLInputElement>) {
    // Entrée sur une référence complète : ouvre directement le ticket trouvé.
    if (e.key === 'Enter' && data?.tickets?.length === 1) {
      navigate(`/admin/tickets/${data.tickets[0]!.id}`);
      setQ('');
    }
  }

  return (
    <div className="topbar-search" ref={ref}>
      <Icon name="search" size={17} />
      <input
        type="search"
        placeholder="Joueur, téléphone, ticket, équipe…"
        aria-label="Recherche globale"
        aria-expanded={open && term.length >= 2}
        aria-controls={listId}
        value={q}
        onChange={(e) => { setQ(e.target.value); setOpen(true); }}
        onFocus={() => setOpen(true)}
        onKeyDown={onKeyDown}
      />
      {open && term.trim().length >= 2 && (
        <div className="dropdown search-results" id={listId} role="region" aria-label="Résultats de recherche">
          {search.isFetching && !data && <div className="dropdown-state"><Spinner size="sm" /></div>}
          {search.isError && <p className="dropdown-state">La recherche a échoué. Réessayez.</p>}
          {empty && <p className="dropdown-state">Aucun résultat pour « {term.trim()} ».</p>}
          {!!data?.users?.length && (
            <section>
              <h3>Comptes</h3>
              {data.users.map((u) => (
                <Link key={u.id} to={`/admin/users/${u.id}`} className="dropdown-item">
                  <Icon name="user" size={16} /><span>{u.name}</span>
                  <small>n° {u.id} · {STATUS_LABELS[u.status as AccountStatus] ?? u.status}</small>
                </Link>
              ))}
            </section>
          )}
          {!!data?.tickets?.length && (
            <section>
              <h3>Tickets</h3>
              {data.tickets.map((t) => (
                <Link key={t.id} to={`/admin/tickets/${t.id}`} className="dropdown-item">
                  <Icon name="coupon" size={16} /><span className="mono">{t.reference}</span>
                  <small>{TICKET_STATUS_LABELS[t.status as TicketStatus] ?? t.status}</small>
                </Link>
              ))}
            </section>
          )}
          {!!data?.events?.length && (
            <section>
              <h3>Matchs</h3>
              {data.events.map((e) => (
                <Link key={e.id} to="/admin/events" className="dropdown-item" title="L'écran Événements & marchés n'est pas encore disponible (P6-06)">
                  <Icon name="calendar" size={16} /><span>{e.name}</span>
                  <small>{new Date(e.starts_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })}</small>
                </Link>
              ))}
            </section>
          )}
        </div>
      )}
    </div>
  );
}

const SERVICE_CATEGORIES = ['Données sportives', 'Technique'];

/** État des services importants, déduit des alertes (pas d'appel supplémentaire). */
export function ServiceStatus() {
  const alerts = useAlerts();
  if (!alerts.data) return null;

  const service = alerts.data.filter((a) => SERVICE_CATEGORIES.includes(a.category));
  const level = service.some((a) => a.severity === 'critical') ? 'down' : service.length ? 'degraded' : 'ok';
  const label = { ok: 'Services opérationnels', degraded: 'Services dégradés', down: 'Incident en cours' }[level];

  return (
    <Link to="/admin/system" className={`service-status is-${level}`} title={service.map((a) => a.title).join(' · ') || label}>
      <span className="service-dot" aria-hidden="true" />
      <span className="service-label">{label}</span>
    </Link>
  );
}

type UserMenuProps = { name: string | undefined; initials: string; roles: string | undefined; onSignOut: () => void };

/** Identité de l'administrateur connecté, accès à son compte et déconnexion. */
export function UserMenu({ name, initials, roles, onSignOut }: UserMenuProps) {
  const { open, setOpen, ref } = useDropdown();

  return (
    <div className="topbar-user" ref={ref}>
      <button type="button" className="admin-user" aria-expanded={open} aria-haspopup="true" onClick={() => setOpen((o) => !o)}>
        <span className="admin-avatar" aria-hidden="true">{initials}</span>
        <span className="admin-user-text">
          <strong>{name}</strong>
          <span>{roles}</span>
        </span>
        <span className="sr-only">Menu du compte</span>
      </button>
      {open && (
        <div className="dropdown user-dropdown" role="menu">
          <Link to="/account" className="dropdown-item" role="menuitem"><Icon name="user" size={16} /><span>Mon compte</span></Link>
          <Link to="/" className="dropdown-item" role="menuitem"><Icon name="external" size={16} /><span>Voir le site</span></Link>
          <button type="button" className="dropdown-item" role="menuitem" onClick={onSignOut}><Icon name="logout" size={16} /><span>Se déconnecter</span></button>
        </div>
      )}
    </div>
  );
}

const SEVERITY_LABELS: Record<AdminAlert['severity'], string> = { critical: 'Critique', warning: 'Attention', info: 'Info' };

/** Cloche : les points d'attention du moment, avec lien vers l'écran concerné. */
export function AlertsBell() {
  const { open, setOpen, ref } = useDropdown();
  const alerts = useAlerts();
  const list = alerts.data ?? [];
  const urgent = list.filter((a) => a.severity !== 'info').length;

  return (
    <div className="topbar-bell" ref={ref}>
      <button type="button" className="admin-icon-btn" aria-expanded={open} aria-haspopup="true" onClick={() => setOpen((o) => !o)}>
        <Icon name="bell" size={19} />
        {urgent > 0 && <span className="bell-count" aria-hidden="true">{urgent > 9 ? '9+' : urgent}</span>}
        <span className="sr-only">Alertes{urgent > 0 ? ` (${urgent} à traiter)` : ''}</span>
      </button>
      {open && (
        <div className="dropdown alerts-dropdown" role="region" aria-label="Alertes">
          <header className="dropdown-head">
            <strong>Alertes</strong>
            <Link to="/admin">Tout voir</Link>
          </header>
          {alerts.isPending && <div className="dropdown-state"><Spinner size="sm" /></div>}
          {alerts.isError && <p className="dropdown-state">Impossible de charger les alertes.</p>}
          {alerts.data && list.length === 0 && <p className="dropdown-state">Aucune alerte : tout est en ordre.</p>}
          {list.map((a) => (
            <Link key={a.key} to={a.link ?? '/admin'} className={`dropdown-item alert-item is-${a.severity}`}>
              <span className="severity-chip">{SEVERITY_LABELS[a.severity]}</span>
              <span>{a.title}</span>
              <small>{a.category}</small>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
