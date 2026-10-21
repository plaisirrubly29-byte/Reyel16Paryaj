import { Link, useLocation, useSearchParams } from 'react-router-dom';
import { Icon } from '../../../shared/components/Icon';
import { sportIcon } from '../../../shared/components/sportIcon';
import { useSports } from '../api/catalogue';

/**
 * Colonne gauche (ordinateur), en deux bandes : à gauche les sports (icône + nom),
 * à droite « Top paris » puis les compétitions ouvertes, chacune avec sa flèche.
 */
export function SportsSidebar() {
  const sports = useSports();
  const [params] = useSearchParams();
  const { pathname } = useLocation();
  const activeSport = params.get('sport');
  const activeCompetition = params.get('competition');
  const list = sports.data ?? [];
  const competitions = list.flatMap((s) => s.competitions.map((c) => ({ ...c, sport: s.slug })));
  const topActive = pathname === '/' && !activeSport && !activeCompetition;

  return (
    <aside className="sports-sidebar" aria-label="Sports et compétitions">
      <nav className="sport-rail" aria-label="Sports">
        <Link to="/" className={`sport-rail-top${topActive ? ' active' : ''}`} aria-label="Top paris">
          <Icon name="flame" size={30} />
        </Link>
        {list.map((s) => (
          <Link
            key={s.slug}
            to={`/sports?sport=${encodeURIComponent(s.slug)}`}
            className={activeSport === s.slug ? 'active' : undefined}
            title={`${s.name} (${s.events_count} match${s.events_count > 1 ? 's' : ''})`}
          >
            <span className="sport-rail-icon" aria-hidden="true"><Icon name={sportIcon(s.slug)} size={26} /></span>
            <span>{s.name}</span>
          </Link>
        ))}
      </nav>

      <nav className="side-menu" aria-label="Compétitions">
        <Link to="/" className={`side-menu-top${topActive ? ' active' : ''}`}>
          <span>Top paris</span>
          <Icon name="arrowRight" size={22} />
        </Link>
        {competitions.length > 0 && (
          <>
            <p className="side-menu-title">Top compétitions</p>
            <ul className="side-menu-list">
              {competitions.map((c) => (
                <li key={c.id}>
                  <Link
                    to={`/sports?competition=${encodeURIComponent(c.id)}`}
                    className={activeCompetition === c.id ? 'active' : undefined}
                  >
                    <span className="side-menu-label">
                      {c.name}
                      {c.country && <span className="side-sub">{c.country}</span>}
                    </span>
                    <Icon name="arrowRight" size={22} />
                  </Link>
                </li>
              ))}
            </ul>
          </>
        )}
      </nav>
    </aside>
  );
}
