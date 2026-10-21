import type { CSSProperties } from 'react';
import { useBetSlip } from '../../betting/slip/BetSlipContext';
import type { CatalogueEvent, CatalogueMarket } from '../api/catalogue';
import { favoriteSelection, impliedShares, marketTitle, matchingSelections } from '../markets';

type Props = {
  event: CatalogueEvent;
  market: CatalogueMarket;
  selectionSearch?: string;
  /**
   * card : libellé au-dessus de la cote (cartes « Top matchs ») ; bar : libellé à gauche, cote à droite dans un bloc
   * foncé, barre de probabilité dessous (liste des matchs) ; wide : nom de la sélection à gauche (page du match).
   */
  variant?: 'card' | 'bar' | 'wide';
};

/**
 * Cotes d'un marché : chaque bouton ajoute ou retire la sélection du coupon (un choix par marché et par match).
 * Une cote trop ancienne reste affichée mais ne peut pas être ajoutée au coupon.
 */
export function MarketOdds({ event, market, variant = 'card', selectionSearch = '' }: Props) {
  const slip = useBetSlip();
  const match = `${event.home_team} – ${event.away_team}`;
  const locked = market.bettable === false;
  const favoriteId = favoriteSelection(market.selections);
  const shares = impliedShares(market.selections);
  const visibleSelections = matchingSelections(market, selectionSearch);
  const wide = variant === 'wide';
  const withBar = variant !== 'card';

  return (
    <div
      className={wide ? `market-odds cols-${Math.min(visibleSelections.length, 3)}` : `match-odds match-odds-${variant}`}
      role="group"
      aria-label={wide ? `${marketTitle(market)} : ${match}` : `Cotes ${match}`}
    >
      {visibleSelections.map((s) => {
        const selected = slip.has(s.id);
        const favorite = s.id === favoriteId;
        // « Oui » / « Non » : libellé et nom identiques, dits une seule fois.
        const name = wide || s.label === s.name ? `${s.name} à ${s.odds}` : `${s.label} ${s.name} à ${s.odds}`;
        return (
          <button
            key={s.id}
            type="button"
            className={`odds-btn odds-btn-${variant}${selected ? ' selected' : ''}${favorite ? ' favorite' : ''}`}
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
            {withBar && (
              <span className="odds-prob" aria-hidden="true">
                <span style={{ '--share': `${shares[s.id] ?? 0}%` } as CSSProperties} />
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}

type OddsButtonsProps = {
  event: CatalogueEvent;
  variant?: 'bar' | 'card';
  /** Marché affiché (1X2 par défaut) ; un match qui ne le propose pas affiche un message à la place des cotes. */
  marketKey?: string;
};

/** Cotes d'un match pour un marché : « barre » dans la liste, « carte » dans les Top matchs. */
export function OddsButtons({ event, variant = 'bar', marketKey = 'h2h' }: OddsButtonsProps) {
  const market = event.markets.find((m) => m.key === marketKey)
    ?? (marketKey === 'h2h' ? event.markets[0] : undefined);

  if (!market) return <p className="odds-unavailable">Pari non proposé pour ce match</p>;

  return <MarketOdds event={event} market={market} variant={variant} />;
}
