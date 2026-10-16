import { useBetSlip } from '../../betting/slip/BetSlipContext';
import type { CatalogueEvent } from '../api/catalogue';
import { formatKickoff } from '../format';

/** Ligne de match : heure, équipes et cotes 1 / X / 2 cliquables (ajout au coupon). */
export function MatchRow({ event }: { event: CatalogueEvent }) {
  const slip = useBetSlip();
  const market = event.markets.find((m) => m.key === 'h2h') ?? event.markets[0];
  const { day, time } = formatKickoff(event.starts_at);
  const match = `${event.home_team} – ${event.away_team}`;

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
      <div className="match-odds" role="group" aria-label={`Cotes ${match}`}>
        {market?.selections.map((s) => {
          const selected = slip.has(s.id);
          return (
            <button
              key={s.id}
              type="button"
              className={`odds-btn${selected ? ' selected' : ''}`}
              aria-pressed={selected}
              aria-label={`${s.label} ${s.name} à ${s.odds}`}
              onClick={() => slip.toggle({
                selectionId: s.id,
                eventId: event.id,
                marketId: market.id,
                oddsVersion: market.odds_version,
                odds: s.odds,
                label: s.label,
                pick: s.name,
                match,
                startsAt: event.starts_at,
              })}
            >
              <span className="odds-label">{s.label}</span>
              <span className="odds-value">{s.odds}</span>
            </button>
          );
        })}
      </div>
    </li>
  );
}
