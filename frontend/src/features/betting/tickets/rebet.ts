import { api, type Envelope } from '../../../shared/api/client';
import type { CatalogueEvent } from '../../catalogue/api/catalogue';
import { marketTitle } from '../../catalogue/markets';
import type { BetTicket } from '../api/bets';
import type { SlipSelection } from '../slip/BetSlipContext';

/** Un match se rejoue tant qu'il n'a pas commencé (les paris en direct passent par la page du match). */
export function canRebet(ticket: BetTicket, now = Date.now()): boolean {
  return ticket.selections.some((s) => s.selection.id && Date.parse(s.event.starts_at) > now);
}

/**
 * Recompose le coupon d'un ticket avec les cotes DU MOMENT, relues dans le catalogue (jamais celles du ticket) :
 * une sélection n'est reprise que si son match n'a pas commencé et que son marché est encore pariable.
 * Le serveur revalidera tout au moment du pari.
 */
export async function rebetSelections(ticket: BetTicket, now = Date.now()): Promise<{ selections: SlipSelection[]; skipped: number }> {
  const eventIds = [...new Set(ticket.selections.map((s) => s.event.id))];
  const events = new Map<string, CatalogueEvent>();

  await Promise.all(eventIds.map(async (id) => {
    try {
      events.set(id, (await api<Envelope<CatalogueEvent>>(`/events/${encodeURIComponent(id)}/markets`)).data);
    } catch {
      // Match retiré du catalogue (terminé, annulé) : sa sélection est simplement ignorée.
    }
  }));

  const selections: SlipSelection[] = [];
  for (const s of ticket.selections) {
    const event = events.get(s.event.id);
    if (!event || !s.selection.id || Date.parse(event.starts_at) <= now) continue;

    const market = event.markets.find((m) => m.selections.some((c) => c.id === s.selection.id));
    const current = market?.selections.find((c) => c.id === s.selection.id);
    if (!market || !current || !market.bettable) continue;

    selections.push({
      selectionId: current.id,
      eventId: event.id,
      marketId: market.id,
      oddsVersion: market.odds_version,
      odds: current.odds,
      label: current.label,
      pick: current.name,
      market: marketTitle(market),
      match: `${event.home_team} – ${event.away_team}`,
      startsAt: event.starts_at,
    });
  }

  return { selections, skipped: ticket.selections.length - selections.length };
}
