import { Link } from 'react-router-dom';
import type { CatalogueEvent } from '../api/catalogue';
import { formatKickoff } from '../format';
import { OddsButtons } from './OddsButtons';
import { TeamBadge } from './TeamBadge';

/** Ligne de match : heure, équipes et cotes 1 / X / 2 cliquables (ajout au coupon). */
export function MatchRow({ event }: { event: CatalogueEvent }) {
  const { day, time } = formatKickoff(event.starts_at);

  return (
    <li className="match-row">
      <div className="match-time">
        <strong>{time}</strong>
        <span>{day}</span>
      </div>
      <Link to={`/events/${event.id}`} className="match-teams" title="Voir tous les paris de ce match">
        <span className="team"><TeamBadge name={event.home_team} /><span className="team-name">{event.home_team}</span></span>
        <span className="team"><TeamBadge name={event.away_team} /><span className="team-name">{event.away_team}</span></span>
      </Link>
      <OddsButtons event={event} />
    </li>
  );
}
