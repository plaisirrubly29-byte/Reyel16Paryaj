import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope, type Money } from '../../../shared/api/client';
import { signOutLocally } from '../../auth/api/auth';

/** Périodes glissantes des limites de mise : 24 dernières heures, 7 jours, 30 jours. */
export type LimitPeriod = 'day' | 'week' | 'month';

export type StakeLimit = {
  /** null : aucune limite. */
  amount: Money | null;
  used: Money;
  remaining: Money | null;
  /** Hausse ou suppression (amount null) en attente du délai de réflexion. */
  pending: { amount: Money | null; effective_at: string } | null;
};

/** GET /responsible-gaming : état calculé par le serveur (mises de la période, reste, pause). */
export type ResponsibleGaming = {
  limits: Record<LimitPeriod, StakeLimit>;
  pause: { active: boolean; ends_at: string | null };
  options: { pause_durations: string[]; exclusion_durations: string[]; limit_increase_delay_days: number };
};

export const PERIODS: { period: LimitPeriod; label: string }[] = [
  { period: 'day', label: 'Sur 24 heures' },
  { period: 'week', label: 'Sur 7 jours' },
  { period: 'month', label: 'Sur 30 jours' },
];

/** Durées proposées par le serveur (options) → libellé lisible ; code tel quel s'il est inconnu. */
const DURATION_LABELS: Record<string, string> = {
  '24h': '24 heures', '7d': '7 jours', '30d': '30 jours',
  '6m': '6 mois', '1y': '1 an', '5y': '5 ans',
};
export const durationLabel = (code: string) => DURATION_LABELS[code] ?? code;

const KEY = ['responsible-gaming'] as const;

export function useResponsibleGaming() {
  return useQuery({
    queryKey: KEY,
    queryFn: async ({ signal }) => (await api<Envelope<ResponsibleGaming>>('/responsible-gaming', { signal })).data,
  });
}

/** Chaque action renvoie le nouvel état : on le garde tel quel, sans recalcul. */
function useStateMutation<T>(request: (input: T) => Promise<Envelope<ResponsibleGaming>>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: T) => (await request(input)).data,
    onSuccess: (state) => queryClient.setQueryData(KEY, state),
  });
}

export function useSetLimit() {
  return useStateMutation(({ period, amountMinor, idempotencyKey }: { period: LimitPeriod; amountMinor: number | null; idempotencyKey: string }) =>
    api(`/responsible-gaming/limits/${period}`, { method: 'PUT', body: { amount_minor: amountMinor }, idempotencyKey }));
}

export function usePause() {
  return useStateMutation(({ duration, idempotencyKey }: { duration: string; idempotencyKey: string }) =>
    api('/responsible-gaming/pause', { method: 'POST', body: { duration }, idempotencyKey }));
}

/**
 * Auto-exclusion : le serveur ferme la session. On oublie alors les données du compte,
 * comme à la déconnexion (useLogout), sans détacher les composants affichés.
 * `onExcluded` s'exécute avant, navigation attendue : la page protégée doit être quittée, sinon elle
 * redirigerait vers /login.
 */
export function useSelfExclude(onExcluded: (excludedUntil: string) => void | Promise<void>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ duration, password, idempotencyKey }: { duration: string; password: string; idempotencyKey: string }) =>
      (await api<Envelope<{ excluded_until: string; reopening: 'on_request' }>>('/responsible-gaming/self-exclusion', {
        method: 'POST', body: { duration, password }, idempotencyKey,
      })).data,
    onSuccess: async ({ excluded_until }) => {
      await onExcluded(excluded_until);
      signOutLocally(queryClient);
    },
  });
}
