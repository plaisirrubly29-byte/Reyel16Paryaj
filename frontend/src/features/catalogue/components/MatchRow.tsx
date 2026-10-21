import { Link } from 'react-router-dom';
import { Icon } from '../../../shared/components/Icon';
import type { CatalogueEvent } from '../api/catalogue';
import { formatKickoff, liveLabel } from '../format';
import { OddsButtons } from './OddsButtons';

/**
 * Ligne d'un match : « Domicile - Extérieur » et la compétition à gauche, l'heure et le jour à droite,
 * puis les cotes 1 / X / 2 cliquables (ajout au coupon) et le lien vers tous les paris du match.
 */
export function MatchRow({ event, marketKey = 'h2h' }: { event: CatalogueEvent; marketKey?: string }) {
  const { day, time } = formatKickoff(event.starts_at);
  const live = liveLabel(event.status);
  const { competition } = event;

  return (
    <li className="match-line">
      <div className="match-line-head">
        <div className="match-line-info">
          <Link to={`/events/${event.id}`} className="match-line-teams" title="Voir tous les paris de ce match">
            <span className="team-name">{event.home_team}</span>
            <span className="match-line-sep" aria-hidden="true"> - </span>
            <span className="sr-only"> contre </span>
            <span className="team-name">{event.away_team}</span>
          </Link>
          <p className="match-line-competition">
            {competition.country ? `${competition.country} - ` : ''}{competition.name}
            {live && <span className="live-badge"><Icon name="live" size={12} />{live}</span>}
          </p>
        </div>
        <div className="match-line-time">
          <strong className={live ? 'live-indicator' : undefined}>{live ? 'En cours' : time}</strong>
          <span>{day}</span>
          <Link to={`/events/${event.id}`} className="match-line-more" aria-label={`Voir tous les paris : ${event.home_team} – ${event.away_team}`}>
            Plus de paris <Icon name="chevron" size={18} />
          </Link>
        </div>
      </div>
      <OddsButtons event={event} marketKey={marketKey} />
    </li>
  );
}
