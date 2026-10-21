import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, type Envelope, type Paginated } from '../../../shared/api/client';
import { isLiveStatus } from '../format';

/** GET /sports — sports ayant des matchs ouverts, y compris en direct. */
export type CatalogueSport = {
  slug: string;
  name: string;
  events_count: number;
  competitions: { id: string; name: string; country: string | null; events_count: number }[];
};

export type CatalogueSelection = {
  id: string;
  key: 'home' | 'draw' | 'away' | string;
  label: string;
  name: string;
  /** Cote décimale en chaîne (ex. "2.10") — jamais convertie en nombre pour un calcul. */
  odds: string;
};

export type CatalogueMarket = {
  id: string;
  key: string;
  name: string;
  odds_version: number;
  odds_updated_at: string;
  /** Ligne du marché « Nombre de buts » (ex. "2.5") ; null pour le 1X2. */
  line?: string | null;
  /** false : cote trop ancienne (règle de fraîcheur), affichée mais non pariable. */
  bettable: boolean;
  selections: CatalogueSelection[];
};

export type CatalogueEvent = {
  id: string;
  sport: { slug: string; name: string };
  competition: { id: string; name: string; country: string | null };
  home_team: string;
  away_team: string;
  starts_at: string;
  status: string;
  markets: CatalogueMarket[];
};

/** Les cotes en direct sont rafraîchies plus souvent que les matchs à venir. */
const REFRESH_MS = 60_000;
const LIVE_REFRESH_MS = 10_000;

function isLiveOrStarted(event: CatalogueEvent): boolean {
  return isLiveStatus(event.status) || Date.parse(event.starts_at) <= Date.now();
}

export function useSports() {
  return useQuery({
    queryKey: ['catalogue', 'sports'],
    queryFn: async ({ signal }) => (await api<Envelope<CatalogueSport[]>>('/sports', { signal })).data,
    refetchInterval: REFRESH_MS,
  });
}

/** GET /events/{id}/markets — un match avec tous ses marchés affichés (404 s'il n'est plus proposé). */
export function useEventMarkets(id: string) {
  return useQuery({
    queryKey: ['catalogue', 'event', id],
    queryFn: async ({ signal }) => (await api<Envelope<CatalogueEvent>>(`/events/${encodeURIComponent(id)}/markets`, { signal })).data,
    refetchInterval: (query) => query.state.data && isLiveOrStarted(query.state.data)
      ? LIVE_REFRESH_MS
      : REFRESH_MS,
  });
}

/** Marchés affichables dans la liste des matchs (GET /events?market=). */
export const LIST_MARKETS = [
  { key: 'h2h', short: '1X2', name: 'Résultat du match' },
  { key: 'double_chance', short: 'DC', name: 'Double chance' },
  { key: 'totals', short: '+/-', name: 'Plus / moins' },
  { key: 'btts', short: 'LDEM', name: 'Les deux équipes marquent' },
] as const;

export type ListMarketKey = (typeof LIST_MARKETS)[number]['key'];

export function useEvents(filters: { sport?: string; competition?: string; page?: number; perPage?: number; market?: ListMarketKey }) {
  return useQuery({
    queryKey: ['catalogue', 'events', filters],
    queryFn: ({ signal }) => api<Paginated<CatalogueEvent>>('/events', {
      signal,
      query: {
        sport: filters.sport,
        competition: filters.competition,
        page: filters.page,
        per_page: filters.perPage ?? 50,
        // Le 1X2 est le marché par défaut du serveur : pas de paramètre (même URL qu'avant).
        market: filters.market && filters.market !== 'h2h' ? filters.market : undefined,
      },
    }),
    placeholderData: keepPreviousData,
    refetchInterval: (query) => query.state.data?.data.some(isLiveOrStarted)
      ? LIVE_REFRESH_MS
      : REFRESH_MS,
  });
}
