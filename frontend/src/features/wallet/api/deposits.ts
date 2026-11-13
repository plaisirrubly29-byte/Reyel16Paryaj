import { useEffect } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { API_URL, api, type Envelope, type Money, type Paginated } from '../../../shared/api/client';

/**
 * Dépôts par un prestataire de paiement (POST /payments/deposits). Ouvrir un dépôt ne crédite rien : le joueur
 * confirme sur la page du prestataire, puis le serveur crédite le solde à réception du webhook signé.
 */
export type DepositStatus = 'pending' | 'succeeded' | 'failed' | 'expired' | 'review';

export type Deposit = {
  reference: string;
  provider: string;
  provider_label: string;
  status: DepositStatus;
  amount: Money;
  /** Page du prestataire où le joueur confirme ; seulement tant que le dépôt est en attente. */
  redirect_url: string | null;
  created_at: string;
  expires_at: string | null;
  completed_at: string | null;
};

export type DepositMethods = {
  methods: { provider: string; label: string }[];
  min_amount: Money;
  max_amount: Money;
};

export const DEPOSIT_STATUS_LABELS: Record<DepositStatus, string> = {
  pending: 'En attente',
  succeeded: 'Crédité',
  failed: 'Refusé',
  expired: 'Expiré',
  review: 'En vérification',
};

const DEPOSITS_KEY = ['deposits'] as const;

export function useDepositMethods() {
  return useQuery({
    queryKey: [...DEPOSITS_KEY, 'methods'],
    queryFn: async ({ signal }) => (await api<Envelope<DepositMethods>>('/payments/methods', { signal })).data,
    staleTime: 5 * 60_000,
  });
}

export function useDeposits(page = 1) {
  return useQuery({
    queryKey: [...DEPOSITS_KEY, 'list', page],
    queryFn: ({ signal }) => api<Paginated<Deposit>>('/payments/deposits', { signal, query: { page, per_page: 5 } }),
    placeholderData: keepPreviousData,
  });
}

/** Suivi d'un dépôt : interrogé toutes les 3 secondes tant que le prestataire ne l'a pas confirmé. */
export function useDeposit(reference: string | null) {
  const queryClient = useQueryClient();
  const query = useQuery({
    queryKey: [...DEPOSITS_KEY, 'one', reference],
    enabled: reference !== null,
    queryFn: async ({ signal }) => (await api<Envelope<Deposit>>(`/payments/deposits/${encodeURIComponent(reference!)}`, { signal })).data,
    refetchInterval: (q) => (q.state.data?.status === 'pending' ? 3_000 : false),
  });

  // Dépôt confirmé : le solde et l'historique viennent de changer côté serveur.
  const credited = query.data?.status === 'succeeded';
  useEffect(() => {
    if (credited) void queryClient.invalidateQueries({ queryKey: ['wallet'] });
  }, [credited, queryClient]);

  return query;
}

export function useStartDeposit() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: { provider: string; amountMinor: number; idempotencyKey: string }) =>
      (await api<Envelope<Deposit>>('/payments/deposits', {
        method: 'POST',
        idempotencyKey: input.idempotencyKey,
        body: { provider: input.provider, amount_minor: input.amountMinor },
      })).data,
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: [...DEPOSITS_KEY, 'list'] }),
  });
}

/** Adresse absolue de la page du prestataire ; un chemin relatif est servi par l'API (prestataire simulé). */
export function providerPageUrl(redirectUrl: string): string {
  return new URL(redirectUrl, new URL(API_URL, window.location.origin)).toString();
}

/** Quitte le site pour la page du prestataire (objet pour pouvoir l'observer dans les tests). */
export const providerNavigation = {
  open(url: string) {
    window.location.assign(url);
  },
};
