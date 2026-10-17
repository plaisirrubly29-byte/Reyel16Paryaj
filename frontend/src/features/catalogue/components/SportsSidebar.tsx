import { Link, useSearchParams } from 'react-router-dom';
import { Icon } from '../../../shared/components/Icon';
import { sportIcon } from '../../../shared/components/sportIcon';
import { useSports } from '../api/catalogue';

/** Colonne gauche (ordinateur) : sports ouverts et compétitions, avec le nombre de matchs. */
export function SportsSidebar() {
  const sports = useSports();
  const [params] = useSearchParams();
  const activeSport = params.get('sport');
  const activeCompetition = params.get('competition');
  const list = sports.data ?? [];
  const total = list.reduce((sum, s) => sum + s.events_count, 0);

  return (
    <aside className="sports-sidebar" aria-label="Sports et compétitions">
      <nav className="side-block" aria-label="Sports">
        <p className="side-title">Sports</p>
        <ul className="side-list">
          <li>
            <Link to="/sports" className={!activeSport && !activeCompetition ? 'active' : undefined}>
              <span className="side-icon" aria-hidden="true"><Icon name="trophy" size={18} /></span>
              <span className="side-label">Tous les sports</span>
              <span className="count">{total}</span>
            </Link>
          </li>
          {list.map((s) => (
            <li key={s.slug}>
              <Link to={`/sports?sport=${encodeURIComponent(s.slug)}`} className={activeSport === s.slug ? 'active' : undefined}>
                <span className="side-icon" aria-hidden="true"><Icon name={sportIcon(s.slug)} size={18} /></span>
                <span className="side-label">{s.name}</span>
                <span className="count">{s.events_count}</span>
              </Link>
            </li>
          ))}
        </ul>
      </nav>

      {list.some((s) => s.competitions.length > 0) && (
        <nav className="side-block" aria-label="Compétitions">
          <p className="side-title">Top compétitions</p>
          {list.map((s) => s.competitions.length > 0 && (
            <ul key={s.slug} className="side-list side-list-compact">
              {s.competitions.map((c) => (
                <li key={c.id}>
                  <Link to={`/sports?competition=${encodeURIComponent(c.id)}`} className={activeCompetition === c.id ? 'active' : undefined}>
                    <span className="side-label">
                      {c.name}
                      {c.country && <span className="side-sub">{c.country}</span>}
                    </span>
                    <span className="count">{c.events_count}</span>
                  </Link>
                </li>
              ))}
            </ul>
          ))}
        </nav>
      )}
    </aside>
  );
}
