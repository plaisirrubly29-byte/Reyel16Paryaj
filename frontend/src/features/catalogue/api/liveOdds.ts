import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../../shared/api/client';
import { isLiveStatus } from '../format';
import type { CatalogueEvent, CatalogueMarket } from './catalogue';

/**
 * Paris en direct (décision D-20) : score et cotes du moment des matchs en cours (GET /live/odds), relus toutes les
 * 3 secondes. Les cotes viennent du modèle du serveur, recalculées à chaque appel : jamais mises en cache.
 */
export type LiveSuspendedReason =
  | 'live_closed' | 'not_available' | 'suspended' | 'score_stale' | 'score_changed' | 'closing' | 'decided';

export type LiveMarket = {
  id: string;
  key: string;
  line: string | null;
  odds_version: number;
  bettable: boolean;
  suspended_reason: LiveSuspendedReason | null;
  selections: { id: string; key: string; odds: string }[];
};

export type LiveEvent = {
  id: string;
  status: string;
  score: { home: string; away: string } | null;
  minute: number | null;
  markets: LiveMarket[];
};

export type LiveOddsResponse = {
  data: LiveEvent[];
  live_betting: { enabled: boolean; acceptance_delay_seconds: number; max_stake_minor: number; allow_multiple: boolean };
  server_time: string;
};

export const LIVE_POLL_MS = 3_000;

/** Libellé d'un marché en direct suspendu (affiché sur les cotes et dans le coupon). */
export const SUSPENDED_LABELS: Record<LiveSuspendedReason, string> = {
  live_closed: 'Paris fermés pendant le match',
  not_available: 'Pas de pari en direct sur ce marché',
  suspended: 'Suspendu',
  score_stale: 'Suspendu : score en cours de vérification',
  score_changed: 'Suspendu : but en cours de vérification',
  closing: 'Suspendu : fin de match',
  decided: 'Suspendu : issue presque acquise',
};

/** Score, minute et cotes du moment de ces matchs ; aucune requête sans match. */
export function useLiveOdds(eventIds: string[]) {
  const ids = useMemo(() => [...new Set(eventIds)].sort((a, b) => Number(a) - Number(b)).slice(0, 50), [eventIds]);

  return useQuery({
    queryKey: ['live-odds', ids],
    queryFn: ({ signal }) => api<LiveOddsResponse>('/live/odds', { signal, query: { event_ids: ids.join(',') } }),
    enabled: ids.length > 0,
    refetchInterval: LIVE_POLL_MS,
    refetchIntervalInBackground: false,
    staleTime: 0,
  });
}

function mergeMarket(market: CatalogueMarket, live: LiveMarket | undefined): CatalogueMarket {
  if (!live) return market;
  const odds = new Map(live.selections.map((s) => [s.id, s.odds]));
  return {
    ...market,
    bettable: live.bettable,
    suspended_reason: live.suspended_reason,
    odds_version: live.odds_version,
    line: live.line,
    selections: market.selections.map((s) => (odds.has(s.id) ? { ...s, odds: odds.get(s.id)! } : s)),
  };
}

/** Match du catalogue avec le score, la minute et les cotes du moment (s'il est en cours). */
export function withLiveOdds(event: CatalogueEvent, live: LiveEvent | undefined): CatalogueEvent {
  if (!live) return event;
  const markets = new Map(live.markets.map((m) => [m.id, m]));
  return {
    ...event,
    status: live.status,
    score: live.score ?? event.score,
    minute: live.minute,
    markets: event.markets.map((m) => mergeMarket(m, markets.get(m.id))),
  };
}

/** Matchs en cours d'une liste, relus toutes les 3 s : la liste rendue porte toujours les cotes du moment. */
export function useLiveOverlay(events: CatalogueEvent[] | undefined): CatalogueEvent[] | undefined {
  const liveIds = useMemo(() => (events ?? []).filter((e) => isLiveStatus(e.status)).map((e) => e.id), [events]);
  const { data } = useLiveOdds(liveIds);

  return useMemo(() => {
    if (!events || !data) return events;
    const byId = new Map(data.data.map((e) => [e.id, e]));
    return events.map((e) => withLiveOdds(e, byId.get(e.id)));
  }, [events, data]);
}
