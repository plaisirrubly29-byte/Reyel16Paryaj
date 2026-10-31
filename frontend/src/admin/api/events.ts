import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope, type Paginated } from '../../shared/api/client';

export type EventStatus = 'scheduled' | 'live' | 'finished' | 'postponed' | 'cancelled';
export type MarketStatus = 'open' | 'suspended' | 'closed' | 'settled';

export const EVENT_STATUS_LABELS: Record<EventStatus, string> = {
  scheduled: 'Programmé',
  live: 'En direct',
  finished: 'Terminé',
  postponed: 'Reporté',
  cancelled: 'Annulé',
};

export const MARKET_STATUS_LABELS: Record<MarketStatus, string> = {
  open: 'Ouvert',
  suspended: 'Suspendu',
  closed: 'Fermé',
  settled: 'Réglé',
};

/** Marché vu par l'administration (GET /admin/markets/{id}, P6-02) : tous statuts, origine et fraîcheur. */
export type AdminMarket = {
  id: string;
  event_id: string;
  key: string;
  name: string;
  line: string | null;
  status: MarketStatus;
  /** automatic : posée par la synchronisation (cotes périmées), levée par des cotes fraîches. */
  suspension: { automatic: boolean; reason: string } | null;
  odds_version: number;
  origin: { provider: string | null; external_id: string | null; bookmaker: string | null };
  freshness: { odds_updated_at: string | null; age_seconds: number | null; max_age_minutes: number; stale: boolean };
  bettable: boolean;
  selections?: { id: string; key: string; label: string; name: string; odds: string }[];
  updated_at: string | null;
};

/** Événement vu par l'administration (GET /admin/events, P6-02). `markets` : détail seulement. */
export type AdminEvent = {
  id: string;
  sport: { slug: string; name: string };
  competition: { id: string; name: string; country: string | null };
  home_team: string;
  away_team: string;
  starts_at: string;
  status: EventStatus;
  score: { home: string; away: string } | null;
  origin: { provider: string; external_id: string };
  last_synced_at: string | null;
  result_received_at: string | null;
  markets_count: Record<MarketStatus, number>;
  markets?: AdminMarket[];
};

export type EventFilters = { search?: string; status?: EventStatus; date?: string; market_status?: MarketStatus; page: number };

export function useAdminEvents(filters: EventFilters) {
  return useQuery({
    queryKey: ['admin', 'events', filters],
    queryFn: ({ signal }) => api<Paginated<AdminEvent>>('/admin/events', { signal, query: { ...filters, per_page: 20 } }),
    placeholderData: keepPreviousData,
  });
}

export function useAdminEvent(id: string) {
  return useQuery({
    queryKey: ['admin', 'events', id],
    queryFn: async ({ signal }) => (await api<Envelope<AdminEvent>>(`/admin/events/${encodeURIComponent(id)}`, { signal })).data,
  });
}

export type MarketChange = { status: 'open' | 'suspended'; reason: string; idempotencyKey: string };

/** Suspendre ou rouvrir un marché (permission markets.suspend, motif obligatoire, audité par le serveur). */
export function useChangeMarketStatus(market: AdminMarket) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ status, reason, idempotencyKey }: MarketChange) =>
      (await api<Envelope<AdminMarket>>(`/admin/markets/${encodeURIComponent(market.id)}`, {
        method: 'PATCH',
        body: { status, reason },
        idempotencyKey,
      })).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'events'], exact: false }),
  });
}

/** Durée lisible depuis un instant : « il y a 12 min », « il y a 3 h », « il y a 2 j ». */
export function since(iso: string | null, now = Date.now()): string {
  if (!iso) return 'jamais';
  const minutes = Math.max(0, Math.round((now - new Date(iso).getTime()) / 60_000));
  if (minutes < 1) return "à l'instant";
  if (minutes < 60) return `il y a ${minutes} min`;
  if (minutes < 48 * 60) return `il y a ${Math.round(minutes / 60)} h`;
  return `il y a ${Math.round(minutes / (24 * 60))} j`;
}

export const dateTime = (iso: string) => new Date(iso).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
