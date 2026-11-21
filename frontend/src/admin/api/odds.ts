import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope, type Paginated } from '../../shared/api/client';

export type OddsOverrideStatus = 'pending' | 'active' | 'rejected' | 'ended' | 'expired';

export const OVERRIDE_STATUS_LABELS: Record<OddsOverrideStatus, string> = {
  pending: 'À valider',
  active: 'En vigueur',
  rejected: 'Refusée',
  ended: 'Terminée',
  expired: 'Expirée',
};

/**
 * Écart avec la cote calculée (en %) au-delà duquel un second administrateur doit valider (D-18, backend
 * config/odds.php). Indicatif : sert à prévenir l'administrateur ; le serveur décide (requires_approval).
 */
export const OVERRIDE_APPROVAL_THRESHOLD_PERCENT = 20;

/** Cote fixée à la main par l'administration (V2-D03, décision D-18). */
export type OddsOverride = {
  id: string;
  market_id: string;
  selection: { id: string; key: string | null; name: string | null };
  event: { id: string; home_team: string | null; away_team: string | null; starts_at: string | null } | null;
  status: OddsOverrideStatus;
  odds: string;
  /** Cote calculée au moment de la demande. */
  computed_odds: string;
  /** Écart avec la cote calculée, en % (chaîne décimale signée, ex. "-6.54"). */
  deviation_percent: string;
  requires_approval: boolean;
  reason: string;
  /** Date limite : par défaut, le début du match. */
  expires_at: string;
  requested_by: { id: string; name: string } | null;
  requested_at: string;
  decided_by: { id: string; name: string } | null;
  decision_reason: string | null;
  decided_at: string | null;
  ended_by: { id: string; name: string } | null;
  ended_at: string | null;
};

/** Demande encore ouverte : en attente de validation ou en vigueur. */
export const isOpen = (o: Pick<OddsOverride, 'status'>) => o.status === 'pending' || o.status === 'active';

export const matchName = (o: Pick<OddsOverride, 'event'>) =>
  (o.event ? `${o.event.home_team ?? '?'} – ${o.event.away_team ?? '?'}` : 'Match');

/** Cote décimale en millièmes (entier), sans passer par un nombre à virgule. null si la chaîne n'est pas une cote. */
function thousandths(odds: string): number | null {
  const match = /^(\d{1,4})(?:\.(\d{1,3}))?$/.exec(odds.trim());
  if (!match) return null;
  return Number(match[1]) * 1000 + Number((match[2] ?? '').padEnd(3, '0'));
}

/**
 * Écart d'une cote avec la cote calculée, en centièmes de % (entier signé, tronqué comme le serveur), ou null.
 * Ex. 2.00 contre 2.14 : -654 (soit -6,54 %).
 */
export function deviationHundredths(odds: string, computed: string): number | null {
  const a = thousandths(odds);
  const c = thousandths(computed);
  if (a === null || c === null || c === 0) return null;
  return Math.trunc(((a - c) * 10_000) / c);
}

/** Centièmes de % en texte : « +12,50 % », « -6,54 % ». */
export function percentLabel(hundredths: number): string {
  const sign = hundredths > 0 ? '+' : hundredths < 0 ? '-' : '';
  const abs = Math.abs(hundredths);
  return `${sign}${Math.floor(abs / 100)},${String(abs % 100).padStart(2, '0')} %`;
}

/** Écart renvoyé par le serveur (ex. "-6.54") en texte. */
export function deviationLabel(percent: string): string {
  const match = /^(-?)(\d+)(?:\.(\d{1,2}))?$/.exec(percent.trim());
  if (!match) return `${percent} %`;
  const hundredths = Number(match[2]) * 100 + Number((match[3] ?? '').padEnd(2, '0'));
  return percentLabel(match[1] === '-' ? -hundredths : hundredths);
}

/** a > b pour deux cotes décimales en chaîne. */
export function oddsAbove(a: string, b: string): boolean {
  const x = thousandths(a);
  const y = thousandths(b);
  return x !== null && y !== null && x > y;
}

export function useOddsOverrides(filters: { status?: OddsOverrideStatus; event_id?: string; page: number }, enabled = true) {
  return useQuery({
    queryKey: ['admin', 'odds-overrides', filters],
    queryFn: ({ signal }) => api<Paginated<OddsOverride>>('/admin/odds-overrides', { signal, query: { ...filters, per_page: filters.event_id ? 100 : 20 } }),
    placeholderData: keepPreviousData,
    enabled,
  });
}

export function useOddsOverride(id: string) {
  return useQuery({
    queryKey: ['admin', 'odds-overrides', id],
    queryFn: async ({ signal }) => (await api<Envelope<OddsOverride>>(`/admin/odds-overrides/${encodeURIComponent(id)}`, { signal })).data,
  });
}

export type OverrideRequest = { selection_id: string; odds: string; expires_at: string | null; reason: string; idempotencyKey: string };

/** Fixer une cote à la main (odds.override, motif obligatoire) : en vigueur tout de suite, ou en attente d'un second administrateur. */
export function useRequestOverride(marketId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ idempotencyKey, expires_at, ...body }: OverrideRequest) =>
      (await api<Envelope<OddsOverride>>(`/admin/markets/${encodeURIComponent(marketId)}/odds-overrides`, {
        method: 'POST',
        body: expires_at ? { ...body, expires_at } : body,
        idempotencyKey,
      })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'odds-overrides'], exact: false });
      queryClient.invalidateQueries({ queryKey: ['admin', 'events'], exact: false });
    },
  });
}

export type OverrideAction = { action: 'approve' | 'reject' | 'end'; reason: string; idempotencyKey: string };

/** Valider (jamais le demandeur), refuser ou retirer une demande, ou revenir à la cote calculée ; motif obligatoire. */
export function useOverrideAction(override: Pick<OddsOverride, 'id'>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ action, reason, idempotencyKey }: OverrideAction) =>
      (await api<Envelope<OddsOverride>>(`/admin/odds-overrides/${encodeURIComponent(override.id)}/${action}`, {
        method: 'POST',
        body: { reason },
        idempotencyKey,
      })).data,
    onSuccess: (updated) => {
      queryClient.setQueryData(['admin', 'odds-overrides', override.id], updated);
      // Les listes changent ; la demande affichée vient d'être remplacée par la réponse (pas de rechargement).
      queryClient.invalidateQueries({ queryKey: ['admin', 'odds-overrides'], predicate: (q) => q.queryKey[2] !== override.id });
      queryClient.invalidateQueries({ queryKey: ['admin', 'events'], exact: false });
    },
  });
}
