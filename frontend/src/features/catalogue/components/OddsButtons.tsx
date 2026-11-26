import { useState } from 'react';
import { Icon } from '../../../shared/components/Icon';
import { useBetSlip } from '../../betting/slip/BetSlipContext';
import type { CatalogueEvent, CatalogueMarket } from '../api/catalogue';
import { SUSPENDED_LABELS, isLiveClosed } from '../api/liveOdds';
import { isLiveStatus } from '../format';
import { favoriteSelection, marketColumns, marketTitle, matchingSelections } from '../markets';

type Props = {
  event: CatalogueEvent;
  market: CatalogueMarket;
  selectionSearch?: string;
  /**
   * card : libellé au-dessus de la cote (cartes « Top matchs ») ; bar : libellé à gauche, cote à droite dans un bloc
   * foncé (liste des matchs) ; wide : libellé court à gauche, cote à droite (page du match).
   */
  variant?: 'card' | 'bar' | 'wide';
  /**
   * Page du match : un marché aux nombreuses lignes (buteurs, scores exacts, autres lignes de buts) n'en montre que
   * les premières, avec « + N autres » pour déplier. Tout reste visible pendant une recherche ou si une ligne cachée
   * est dans le coupon.
   */
  collapsible?: boolean;
};

/** Nombre de cotes montrées d'un marché replié : 3 rangées (5 lignes pour une colonne de noms). */
function collapsedLimit(columns: number): number {
  return columns === 1 ? 5 : columns * 3;
}

/**
 * Raison affichée sur une cote non pariable : marché en direct suspendu (score à vérifier, fin de match…), paris en
 * direct fermés, ou cote trop ancienne.
 */
function lockedReason(event: CatalogueEvent, market: CatalogueMarket): string {
  if (market.suspended_reason) return SUSPENDED_LABELS[market.suspended_reason] ?? 'Suspendu';
  return isLiveStatus(event.status) ? 'Paris fermés pendant le match' : 'Cote en cours de mise à jour';
}

/**
 * Marché en direct non pariable avec un motif (D-20) : « Suspendu » à la place des cotes pour un moment (but à vérifier),
 * « Fermé » pour la suite du match (fin de match, score plus suivi).
 */
function isSuspended(event: CatalogueEvent, market: CatalogueMarket): boolean {
  return isLiveStatus(event.status) && market.bettable === false
    && !!market.suspended_reason && !['live_closed', 'not_available'].includes(market.suspended_reason);
}

/**
 * Cotes d'un marché : chaque bouton ajoute ou retire la sélection du coupon (un choix par marché et par match).
 * Une cote non pariable (trop ancienne, ou match en direct) reste affichée mais ne peut pas être ajoutée au coupon.
 */
export function MarketOdds({ event, market, variant = 'card', selectionSearch = '', collapsible = false }: Props) {
  const slip = useBetSlip();
  const [expanded, setExpanded] = useState(false);
  const match = `${event.home_team} – ${event.away_team}`;
  const locked = market.bettable === false;
  const reason = lockedReason(event, market);
  const suspended = isSuspended(event, market);
  const favoriteId = favoriteSelection(market.selections);
  const visibleSelections = matchingSelections(market, selectionSearch);
  const wide = variant === 'wide';
  // Libellés courts seulement s'ils distinguent chaque sélection (sinon, ex. « Oui » pour chaque buteur : le nom).
  const shortLabels = new Set(market.selections.map((s) => s.label)).size === market.selections.length;
  const columns = marketColumns(market, visibleSelections.length);
  const limit = collapsedLimit(columns);
  const canCollapse = collapsible && selectionSearch.trim() === '' && visibleSelections.length > limit
    && !visibleSelections.slice(limit).some((s) => slip.has(s.id));
  const shownSelections = canCollapse && !expanded ? visibleSelections.slice(0, limit) : visibleSelections;
  const hiddenCount = visibleSelections.length - limit;

  return (
    <>
    <div
      className={(wide
        ? `market-odds cols-${columns} ${shortLabels ? 'short-labels' : 'long-names'}`
        : `match-odds match-odds-${variant}`) + (suspended ? ' odds-suspended' : '')}
      role="group"
      aria-label={wide ? `${marketTitle(market)} : ${match}` : `Cotes ${match}`}
    >
      {suspended && (
        <span className="odds-suspended-badge" title={reason}>{isLiveClosed(market.suspended_reason ?? null) ? 'Fermé' : 'Suspendu'}</span>
      )}
      {shownSelections.map((s) => {
        const selected = slip.has(s.id);
        const favorite = s.id === favoriteId;
        // Le bouton n'affiche que le libellé court (1, X, 2, 1X, > 2.5…) ; le nom complet reste dans le nom accessible
        // et l'infobulle. « Oui » / « Non » : libellé et nom identiques, dits une seule fois.
        const text = wide && !shortLabels ? s.name : s.label;
        const name = text === s.name ? `${s.name} à ${s.odds}` : `${s.label} ${s.name} à ${s.odds}`;
        return (
          <button
            key={s.id}
            type="button"
            className={`odds-btn odds-btn-${variant}${selected ? ' selected' : ''}${favorite ? ' favorite' : ''}`}
            aria-pressed={selected}
            disabled={locked}
            title={locked ? reason : s.name}
            aria-label={`${name}${locked ? ` (${reason.toLowerCase()})` : ''}`}
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
            <span className={wide ? 'odds-name' : 'odds-label'}>{text}</span>
            <span className="odds-value">{suspended ? '—' : s.odds}</span>
          </button>
        );
      })}
    </div>
    {canCollapse && (
      <button
        type="button"
        className={`market-more${expanded ? ' expanded' : ''}`}
        aria-expanded={expanded}
        onClick={() => setExpanded((value) => !value)}
      >
        {expanded ? 'Voir moins' : `+ ${hiddenCount} autre${hiddenCount > 1 ? 's' : ''}`}
        <Icon name="chevron" size={14} />
      </button>
    )}
    </>
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
