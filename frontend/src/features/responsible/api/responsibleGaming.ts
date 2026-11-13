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

/**
 * Limite de mise (P7-02), de dépôt (V2-B06) ou de pertes (V2-E06) : même règle, baisse immédiate, hausse après le
 * délai de réflexion.
 */
export type LimitKind = 'stake' | 'deposit' | 'loss';

/** GET /responsible-gaming : état calculé par le serveur (montants de la période, reste, pause, rappel). */
export type ResponsibleGaming = {
  limits: Record<LimitPeriod, StakeLimit>;
  /** Limites de dépôt (V2-B06) ; `used` = dépôts de la période. Absentes d'un serveur antérieur à V2-B06. */
  deposit_limits?: Record<LimitPeriod, StakeLimit>;
  /** Limites de pertes (V2-E06) ; `used` = perte nette de la période. Absentes d'un serveur antérieur à V2-E06. */
  loss_limits?: Record<LimitPeriod, StakeLimit>;
  /** Rappel de durée de session (V2-E06) : toutes les `reminder_minutes` depuis la connexion ; null = désactivé. */
  session?: { reminder_minutes: number | null; started_at: string | null };
  pause: { active: boolean; ends_at: string | null };
  options: {
    pause_durations: string[];
    exclusion_durations: string[];
    limit_increase_delay_days: number;
    /** Intervalles proposés pour le rappel (V2-E06). */
    session_reminder_minutes?: number[];
  };
};

/** Totaux d'une période de l'historique des dépenses (V2-E06) ; tous positifs sauf `net`. */
export type SpendingTotals = { deposits: Money; withdrawals: Money; stakes: Money; payouts: Money; refunds: Money; net: Money };

/** GET /responsible-gaming/history : 30 derniers jours et 12 derniers mois, du plus récent au plus ancien. */
export type SpendingHistory = {
  timezone: string;
  days: (SpendingTotals & { date: string })[];
  months: (SpendingTotals & { month: string })[];
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

/** `enabled` : à false sans joueur connecté (l'API répondrait 401). */
export function useResponsibleGaming(enabled = true) {
  return useQuery({
    queryKey: KEY,
    queryFn: async ({ signal }) => (await api<Envelope<ResponsibleGaming>>('/responsible-gaming', { signal })).data,
    enabled,
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

const LIMIT_PATHS: Record<LimitKind, string> = { stake: 'limits', deposit: 'deposit-limits', loss: 'loss-limits' };

/** Fixer, relever ou supprimer (amountMinor null) une limite de mise ou de dépôt. */
export function useSetLimit(kind: LimitKind = 'stake') {
  return useStateMutation(({ period, amountMinor, idempotencyKey }: { period: LimitPeriod; amountMinor: number | null; idempotencyKey: string }) =>
    api(`/responsible-gaming/${LIMIT_PATHS[kind]}/${period}`, { method: 'PUT', body: { amount_minor: amountMinor }, idempotencyKey }));
}

/** Limites d'un type dans l'état du serveur ; undefined pour des limites que le serveur ne gère pas encore. */
export function limitsOf(state: ResponsibleGaming, kind: LimitKind): Record<LimitPeriod, StakeLimit> | undefined {
  return { stake: state.limits, deposit: state.deposit_limits, loss: state.loss_limits }[kind];
}

/** Intervalle du rappel de durée de session (V2-E06) ; null le désactive. S'applique tout de suite. */
export function useSetSessionReminder() {
  return useStateMutation(({ minutes, idempotencyKey }: { minutes: number | null; idempotencyKey: string }) =>
    api('/responsible-gaming/session-reminder', { method: 'PUT', body: { minutes }, idempotencyKey }));
}

/** Historique des dépenses du joueur connecté (V2-E06). */
export function useSpendingHistory() {
  return useQuery({
    queryKey: [...KEY, 'history'],
    queryFn: async ({ signal }) => (await api<Envelope<SpendingHistory>>('/responsible-gaming/history', { signal })).data,
  });
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
