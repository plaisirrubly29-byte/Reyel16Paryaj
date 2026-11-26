import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope, type Money, type Paginated } from '../../shared/api/client';
import { formatMoney } from '../../shared/format/money';

/** Alerte AML vue par l'équipe (GET /admin/aml/alerts, V2-C04) — docs/api/openapi.yaml. */
export type AmlAlertStatus = 'open' | 'dismissed' | 'reported';
export type AmlRule = 'large_deposit' | 'cumulative_deposits' | 'withdrawal_without_play';

export type AmlAlert = {
  id: string;
  rule: AmlRule;
  status: AmlAlertStatus;
  user: { id: string; name: string | null };
  /** Écriture du grand livre qui a déclenché l'alerte (dépôt ou retrait). */
  ledger_entry_id: string;
  amount: Money;
  /** Seuils et montants de la règle au moment de l'alerte (backend AmlMonitor). */
  details: Record<string, number | string | null> | null;
  reviewed_by: { id: string; name: string | null } | null;
  review_reason: string | null;
  reviewed_at: string | null;
  created_at: string;
};

export const AML_STATUS_LABELS: Record<AmlAlertStatus, string> = {
  open: 'À examiner',
  dismissed: 'Classée',
  reported: 'Signalée',
};

export const AML_RULE_LABELS: Record<AmlRule, string> = {
  large_deposit: 'Gros dépôt',
  cumulative_deposits: 'Dépôts cumulés',
  withdrawal_without_play: 'Retrait sans jeu',
};

const htg = (minor: unknown, currency: string) => (typeof minor === 'number' ? formatMoney({ minor, currency }) : '—');

/**
 * Ce qui a déclenché l'alerte, en clair, d'après les détails enregistrés par le serveur au moment de l'alerte
 * (les seuils peuvent avoir changé depuis : on montre ceux d'alors).
 */
export function explainAlert(alert: Pick<AmlAlert, 'rule' | 'amount' | 'details'>): string {
  const d = alert.details ?? {};
  const currency = alert.amount.currency;
  const amount = formatMoney(alert.amount);

  switch (alert.rule) {
    case 'large_deposit':
      return `Dépôt de ${amount}, au-dessus du seuil de ${htg(d.threshold_minor, currency)} pour un seul dépôt.`;
    case 'cumulative_deposits':
      return `Avec ce dépôt de ${amount}, les dépôts des ${d.window_days ?? '?'} derniers jours atteignent `
        + `${htg(d.deposited_minor, currency)} (seuil : ${htg(d.threshold_minor, currency)}).`;
    case 'withdrawal_without_play':
      return `Retrait de ${amount} alors que les dépôts des ${d.window_hours ?? '?'} dernières heures `
        + `(${htg(d.deposited_minor, currency)}) n'ont été misés que pour ${htg(d.staked_minor, currency)}, `
        + `sous le minimum de ${d.min_stake_percent ?? '?'} %.`;
    default:
      return amount;
  }
}

const KEY = ['admin', 'aml'] as const;

export type AmlFilters = { status?: AmlAlertStatus; rule?: AmlRule; userId?: string; from?: string; to?: string; page: number };

export function useAmlAlerts(filters: AmlFilters) {
  return useQuery({
    queryKey: [...KEY, 'list', filters],
    queryFn: ({ signal }) => api<Paginated<AmlAlert>>('/admin/aml/alerts', {
      signal,
      query: {
        status: filters.status, rule: filters.rule, user_id: filters.userId, from: filters.from, to: filters.to,
        page: filters.page, per_page: 20,
      },
    }),
    placeholderData: keepPreviousData,
  });
}

export function useAmlAlert(id: string) {
  return useQuery({
    queryKey: [...KEY, 'detail', id],
    queryFn: async ({ signal }) => (await api<Envelope<AmlAlert>>(`/admin/aml/alerts/${encodeURIComponent(id)}`, { signal })).data,
  });
}

/** Classer (dismissed) ou signaler (reported) une alerte à examiner (aml.review), avec motif audité. */
export function useReviewAmlAlert(alert: Pick<AmlAlert, 'id'>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ decision, reason, idempotencyKey }: { decision: 'dismissed' | 'reported'; reason: string; idempotencyKey: string }) =>
      (await api<Envelope<AmlAlert>>(`/admin/aml/alerts/${encodeURIComponent(alert.id)}/review`, {
        method: 'POST',
        body: { decision, reason },
        idempotencyKey,
      })).data,
    onSuccess: (updated) => {
      queryClient.setQueryData([...KEY, 'detail', alert.id], updated);
      queryClient.invalidateQueries({ queryKey: [...KEY, 'list'] });
      // Le centre d'alertes (cloche) compte les alertes AML à examiner.
      queryClient.invalidateQueries({ queryKey: ['admin', 'alerts'], exact: false });
    },
  });
}
