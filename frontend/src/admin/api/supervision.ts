import { useQuery } from '@tanstack/react-query';
import { api } from '../../shared/api/client';

/** GET /admin/system : supervision technique en lecture seule (aucun secret). */
export type SystemStatus = {
  generated_at: string;
  scheduler: { running: boolean; last_heartbeat: string | null };
  sports_provider: {
    provider: string;
    budget_enabled: boolean;
    credits: { remaining: number; used: number; monthly: number; reserve: number; source: string; next_reset: string };
    sync_cron: string;
    last_call_at: string | null;
    last_event_sync_at: string | null;
    competitions: { key: string; name: string; last_synced_at: string | null }[];
    recent_failures: { endpoint: string; competition: string | null; status: number; called_at: string }[];
  };
  queue: {
    connection: string;
    failed_24h: number;
    recent_failures: { id: string; queue: string; job: string; failed_at: string }[];
  };
  payments: { integrated: boolean; providers: unknown[] };
  backups: { tracked: boolean };
};

export type ExposureSelection = { selection_id: string; name: string; tickets: number; stakes_minor: number; potential_payout_minor: number };

export type ExposureEvent = {
  event_id: string;
  name: string;
  starts_at: string;
  single_tickets: number;
  single_stakes_minor: number;
  worst_case_minor: number;
  markets: { market_key: string; line: string | null; stakes_minor: number; worst_case_minor: number; selections: ExposureSelection[] }[];
  multiple_tickets: number;
  multiple_potential_payout_minor: number;
};

export type SearchResults = {
  users?: { id: string; name: string; status: string }[];
  tickets?: { id: string; reference: string; status: string }[];
  events?: { id: string; name: string; starts_at: string; status: string }[];
};

export function useSystemStatus() {
  return useQuery({
    queryKey: ['admin', 'system'],
    queryFn: ({ signal }) => api<{ data: SystemStatus }>('/admin/system', { signal }).then((r) => r.data),
    refetchInterval: 60_000,
  });
}

export function useExposure() {
  return useQuery({
    queryKey: ['admin', 'risk', 'exposure'],
    queryFn: ({ signal }) =>
      api<{ data: { currency: string; total_events: number; events: ExposureEvent[] } }>('/admin/risk/exposure', { signal }).then((r) => r.data),
    refetchInterval: 60_000,
  });
}

export function useGlobalSearch(q: string) {
  const term = q.trim();
  return useQuery({
    queryKey: ['admin', 'search', term],
    queryFn: ({ signal }) => api<{ data: SearchResults }>('/admin/search', { signal, query: { q: term } }).then((r) => r.data),
    enabled: term.length >= 2,
    staleTime: 30_000,
  });
}
