import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, type Envelope, type Paginated } from '../../../shared/api/client';

/** GET /sports — sports ayant des matchs ouverts (docs/api/openapi.yaml). */
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

/** Les cotes changent : rafraîchissement régulier tant que la page est ouverte. */
const REFRESH_MS = 60_000;

export function useSports() {
  return useQuery({
    queryKey: ['catalogue', 'sports'],
    queryFn: async ({ signal }) => (await api<Envelope<CatalogueSport[]>>('/sports', { signal })).data,
    refetchInterval: REFRESH_MS,
  });
}

export function useEvents(filters: { sport?: string; competition?: string; page?: number; perPage?: number }) {
  return useQuery({
    queryKey: ['catalogue', 'events', filters],
    queryFn: ({ signal }) => api<Paginated<CatalogueEvent>>('/events', {
      signal,
      query: { sport: filters.sport, competition: filters.competition, page: filters.page, per_page: filters.perPage ?? 50 },
    }),
    placeholderData: keepPreviousData,
    refetchInterval: REFRESH_MS,
  });
}
