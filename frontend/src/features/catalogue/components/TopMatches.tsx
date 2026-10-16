import { Link } from 'react-router-dom';
import { useEvents } from '../api/catalogue';
import { formatKickoff } from '../format';
import { OddsButtons } from './OddsButtons';

/** Cartes des prochains matchs mises en avant sur l'accueil (grille sur ordinateur, défilement horizontal sur mobile). */
export function TopMatches({ count = 3 }: { count?: number }) {
  const events = useEvents({ perPage: count });
  const list = events.data?.data ?? [];

  if (list.length === 0) return null;

  return (
    <section className="home-section" aria-labelledby="top-title">
      <div className="section-head">
        <h2 id="top-title">Top matchs</h2>
        <Link to="/sports" className="link-button">Tous les matchs</Link>
      </div>
      <ul className="top-matches" aria-label="Prochains matchs">
        {list.map((e) => {
          const { day, time } = formatKickoff(e.starts_at);
          return (
            <li key={e.id} className="match-card">
              <div className="match-card-head">
                <span className="competition">{e.competition.name}</span>
                <span>{day} · {time}</span>
              </div>
              <div className="match-card-teams">
                <span>{e.home_team}</span>
                <span>{e.away_team}</span>
              </div>
              <OddsButtons event={e} />
            </li>
          );
        })}
      </ul>
    </section>
  );
}
