import type { CatalogueEvent } from '../api/catalogue';
import { formatKickoff } from '../format';
import { OddsButtons } from './OddsButtons';

/** Ligne de match : heure, équipes et cotes 1 / X / 2 cliquables (ajout au coupon). */
export function MatchRow({ event }: { event: CatalogueEvent }) {
  const { day, time } = formatKickoff(event.starts_at);

  return (
    <li className="match-row">
      <div className="match-time">
        <span>{day}</span>
        <strong>{time}</strong>
      </div>
      <div className="match-teams">
        <span>{event.home_team}</span>
        <span>{event.away_team}</span>
      </div>
      <OddsButtons event={event} />
    </li>
  );
}
