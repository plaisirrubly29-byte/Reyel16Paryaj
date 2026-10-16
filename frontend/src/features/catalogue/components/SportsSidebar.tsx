import { Link, useSearchParams } from 'react-router-dom';
import { useSports } from '../api/catalogue';

/** Colonne gauche (ordinateur) : sports ouverts et compétitions, avec le nombre de matchs. */
export function SportsSidebar() {
  const sports = useSports();
  const [params] = useSearchParams();
  const activeSport = params.get('sport');
  const activeCompetition = params.get('competition');
  const list = sports.data ?? [];
  const competitions = list.flatMap((s) => s.competitions);
  const total = list.reduce((sum, s) => sum + s.events_count, 0);

  return (
    <aside className="sports-sidebar" aria-label="Sports et compétitions">
      <nav className="side-block" aria-label="Sports">
        <p className="side-title">Sports</p>
        <ul className="side-list">
          <li>
            <Link to="/sports" className={!activeSport && !activeCompetition ? 'active' : undefined}>
              Tous les sports <span className="count">{total}</span>
            </Link>
          </li>
          {list.map((s) => (
            <li key={s.slug}>
              <Link to={`/sports?sport=${encodeURIComponent(s.slug)}`} className={activeSport === s.slug ? 'active' : undefined}>
                {s.name} <span className="count">{s.events_count}</span>
              </Link>
            </li>
          ))}
        </ul>
      </nav>

      {competitions.length > 0 && (
        <nav className="side-block" aria-label="Compétitions">
          <p className="side-title">Top compétitions</p>
          <ul className="side-list">
            {competitions.map((c) => (
              <li key={c.id}>
                <Link to={`/sports?competition=${encodeURIComponent(c.id)}`} className={activeCompetition === c.id ? 'active' : undefined}>
                  {c.name} <span className="count">{c.events_count}</span>
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      )}
    </aside>
  );
}
