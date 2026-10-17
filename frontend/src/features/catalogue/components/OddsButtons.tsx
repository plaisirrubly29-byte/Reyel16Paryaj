import { useBetSlip } from '../../betting/slip/BetSlipContext';
import type { CatalogueEvent, CatalogueMarket } from '../api/catalogue';
import { favoriteSelection, marketTitle } from '../markets';

type Props = {
  event: CatalogueEvent;
  market: CatalogueMarket;
  /** compact : cotes seules (listes) ; wide : libellé à gauche et cote à droite (page du match). */
  variant?: 'compact' | 'wide';
};

/**
 * Cotes d'un marché : chaque bouton ajoute ou retire la sélection du coupon (un seul choix par match).
 * Une cote trop ancienne reste affichée mais ne peut pas être ajoutée au coupon.
 */
export function MarketOdds({ event, market, variant = 'compact' }: Props) {
  const slip = useBetSlip();
  const match = `${event.home_team} – ${event.away_team}`;
  const locked = market.bettable === false;
  const favoriteId = favoriteSelection(market.selections);
  const wide = variant === 'wide';

  return (
    <div
      className={wide ? `market-odds cols-${Math.min(market.selections.length, 3)}` : 'match-odds'}
      role="group"
      aria-label={wide ? `${marketTitle(market)} : ${match}` : `Cotes ${match}`}
    >
      {market.selections.map((s) => {
        const selected = slip.has(s.id);
        const name = wide ? `${s.name} à ${s.odds}` : `${s.label} ${s.name} à ${s.odds}`;
        return (
          <button
            key={s.id}
            type="button"
            className={`odds-btn${wide ? ' odds-btn-wide' : ''}${selected ? ' selected' : ''}${s.id === favoriteId ? ' favorite' : ''}`}
            aria-pressed={selected}
            disabled={locked}
            title={locked ? 'Cote en cours de mise à jour' : undefined}
            aria-label={`${name}${locked ? ' (cote en cours de mise à jour)' : ''}`}
            onClick={() => slip.toggle({
              selectionId: s.id,
              eventId: event.id,
              marketId: market.id,
              oddsVersion: market.odds_version,
              odds: s.odds,
              label: s.label,
              pick: s.name,
              market: marketTitle(market),
              match,
              startsAt: event.starts_at,
            })}
          >
            {wide ? <span className="odds-name">{s.name}</span> : <span className="odds-label">{s.label}</span>}
            <span className="odds-value">{s.odds}</span>
          </button>
        );
      })}
    </div>
  );
}

/** Cotes 1 / X / 2 d'un match dans les listes (marché principal). */
export function OddsButtons({ event }: { event: CatalogueEvent }) {
  const market = event.markets.find((m) => m.key === 'h2h') ?? event.markets[0];

  return market ? <MarketOdds event={event} market={market} /> : null;
}
