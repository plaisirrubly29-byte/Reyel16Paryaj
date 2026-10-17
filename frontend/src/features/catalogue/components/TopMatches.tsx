import { Link } from 'react-router-dom';
import { Icon } from '../../../shared/components/Icon';
import { sportIcon } from '../../../shared/components/sportIcon';
import { useEvents } from '../api/catalogue';
import { formatKickoff } from '../format';
import { OddsButtons } from './OddsButtons';
import { TeamBadge } from './TeamBadge';

/** Cartes des prochains matchs mises en avant sur l'accueil (grille sur ordinateur, défilement horizontal sur mobile). */
export function TopMatches({ count = 3 }: { count?: number }) {
  const events = useEvents({ perPage: count });
  const list = events.data?.data ?? [];

  if (list.length === 0) return null;

  return (
    <section className="home-section" aria-labelledby="top-title">
      <div className="section-head">
        <h2 id="top-title"><span className="section-icon" aria-hidden="true"><Icon name="bolt" size={18} /></span>Top matchs</h2>
        <Link to="/sports" className="section-link">Tous les matchs <Icon name="chevron" size={16} /></Link>
      </div>
      <ul className="top-matches" aria-label="Prochains matchs">
        {list.map((e) => {
          const { day, time } = formatKickoff(e.starts_at);
          return (
            <li key={e.id} className="match-card">
              <div className="match-card-head">
                <span className="competition">
                  <Icon name={sportIcon(e.sport.slug)} size={15} />
                  {e.competition.name}
                </span>
                <span className="kickoff"><Icon name="clock" size={14} />{day} · {time}</span>
              </div>
              <Link to={`/events/${e.id}`} className="match-card-teams" title="Voir tous les paris de ce match">
                <span className="team"><TeamBadge name={e.home_team} /><span className="team-name">{e.home_team}</span></span>
                <span className="team"><TeamBadge name={e.away_team} /><span className="team-name">{e.away_team}</span></span>
              </Link>
              <OddsButtons event={e} />
            </li>
          );
        })}
      </ul>
    </section>
  );
}
