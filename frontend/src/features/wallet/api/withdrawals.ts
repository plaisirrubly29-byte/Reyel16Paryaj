import { useEffect, useRef } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope, type Money, type Paginated } from '../../../shared/api/client';

/**
 * Retraits du joueur (contrat V2-B03, POST /payments/withdrawals) : la demande réserve le montant sur le solde, puis
 * le prestataire (Plop Plop) verse automatiquement sur le portefeuille MonCash ou NatCash indiqué. Annulable tant que
 * le versement n'est pas parti.
 */
export type WithdrawalStatus = 'requested' | 'first_approved' | 'processing' | 'paid' | 'failed' | 'rejected' | 'cancelled' | 'review';

export type Withdrawal = {
  reference: string;
  provider: string;
  method: string;
  method_label: string;
  /** Numéro masqué sauf les 4 derniers chiffres (« ****3456 »). */
  recipient: string;
  status: WithdrawalStatus;
  amount: Money;
  cancellable: boolean;
  created_at: string;
  completed_at: string | null;
};

export const WITHDRAWAL_STATUS_LABELS: Record<WithdrawalStatus, string> = {
  requested: 'En attente de versement',
  first_approved: 'En attente de versement',
  processing: 'Versement en cours',
  paid: 'Versé',
  failed: 'Échoué, montant rendu',
  rejected: 'Refusé, montant rendu',
  cancelled: 'Annulé, montant rendu',
  review: 'En vérification',
};

const IN_PROGRESS: WithdrawalStatus[] = ['requested', 'first_approved', 'processing', 'review'];
export const isInProgress = (w: Withdrawal) => IN_PROGRESS.includes(w.status);

const KEY = ['withdrawals'] as const;

/**
 * Derniers retraits ; interrogés toutes les 10 secondes tant qu'un versement est attendu. Quand un retrait en cours se
 * termine (versé, échoué, annulé), le solde est relu : un échec rend le montant.
 */
export function useWithdrawals() {
  const queryClient = useQueryClient();
  const query = useQuery({
    queryKey: KEY,
    queryFn: ({ signal }) => api<Paginated<Withdrawal>>('/payments/withdrawals', { signal, query: { per_page: 5 } }),
    refetchInterval: (q) => (q.state.data?.data.some(isInProgress) ? 10_000 : false),
  });

  const inProgress = query.data?.data.filter(isInProgress).map((w) => w.reference).join(',') ?? '';
  const previous = useRef(inProgress);
  useEffect(() => {
    const finished = previous.current.split(',').some((ref) => ref !== '' && !inProgress.split(',').includes(ref));
    previous.current = inProgress;
    if (finished) void queryClient.invalidateQueries({ queryKey: ['wallet'] });
  }, [inProgress, queryClient]);

  return query;
}

function useRefreshAfter() {
  const queryClient = useQueryClient();
  return () => {
    void queryClient.invalidateQueries({ queryKey: KEY });
    void queryClient.invalidateQueries({ queryKey: ['wallet'] });
  };
}

export function useRequestWithdrawal() {
  const refresh = useRefreshAfter();

  return useMutation({
    mutationFn: async (input: { provider: string; method: string; recipient: string; amountMinor: number; idempotencyKey: string }) =>
      (await api<Envelope<Withdrawal>>('/payments/withdrawals', {
        method: 'POST',
        idempotencyKey: input.idempotencyKey,
        body: { provider: input.provider, method: input.method, recipient: input.recipient, amount_minor: input.amountMinor },
      })).data,
    onSettled: refresh,
  });
}

export function useCancelWithdrawal() {
  const refresh = useRefreshAfter();

  return useMutation({
    mutationFn: async (input: { reference: string; idempotencyKey: string }) =>
      (await api<Envelope<Withdrawal>>(`/payments/withdrawals/${encodeURIComponent(input.reference)}/cancel`, {
        method: 'POST',
        idempotencyKey: input.idempotencyKey,
      })).data,
    onSettled: refresh,
  });
}
