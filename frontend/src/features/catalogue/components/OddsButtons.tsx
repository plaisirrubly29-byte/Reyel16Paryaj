import { useBetSlip } from '../../betting/slip/BetSlipContext';
import type { CatalogueEvent } from '../api/catalogue';

/**
 * Cotes 1 / X / 2 d'un match : chaque bouton ajoute ou retire la sélection du coupon.
 * Une cote trop ancienne reste affichée mais ne peut pas être ajoutée au coupon.
 */
export function OddsButtons({ event }: { event: CatalogueEvent }) {
  const slip = useBetSlip();
  const market = event.markets.find((m) => m.key === 'h2h') ?? event.markets[0];
  const match = `${event.home_team} – ${event.away_team}`;

  if (!market) return null;

  const locked = market.bettable === false;

  return (
    <div className="match-odds" role="group" aria-label={`Cotes ${match}`}>
      {market.selections.map((s) => {
        const selected = slip.has(s.id);
        return (
          <button
            key={s.id}
            type="button"
            className={`odds-btn${selected ? ' selected' : ''}`}
            aria-pressed={selected}
            disabled={locked}
            title={locked ? 'Cote en cours de mise à jour' : undefined}
            aria-label={`${s.label} ${s.name} à ${s.odds}${locked ? ' (cote en cours de mise à jour)' : ''}`}
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
  );
}
