import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, type Envelope, type Money, type Paginated } from '../../shared/api/client';

/**
 * Transactions des prestataires de paiement, vues par l'équipe (GET /admin/payments, ledger.view) — docs/api/openapi.yaml.
 * Suivi en lecture seule des dépôts et des retraits (V2-B03, #145) : les retraits sont versés automatiquement par le
 * prestataire, sans validation manuelle (décision de Rubly du 2026-11-17).
 */
export type PaymentDirection = 'deposit' | 'payout';
export type PaymentStatus = 'pending' | 'processing' | 'succeeded' | 'failed' | 'expired' | 'review' | 'cancelled';

export type PaymentWebhook = {
  id: string;
  event_id: string | null;
  signature_valid: boolean;
  outcome: string;
  payload: unknown;
  ip_address: string | null;
  created_at: string;
};

export type AdminPayment = {
  id: string;
  user_id: string;
  provider: string;
  direction: PaymentDirection;
  status: PaymentStatus;
  amount: Money;
  reference: string;
  provider_reference: string | null;
  ledger_entry_id: string | null;
  review_reason: string | null;
  /** Montant ou devise annoncés par le prestataire quand ils diffèrent de la demande (paiement mis en revue). */
  reported: { amount_minor?: number; currency?: string } | null;
  /** Confirmé par le prestataire après l'expiration de la demande, et appliqué. */
  late: boolean;
  expires_at: string | null;
  completed_at: string | null;
  created_at: string;
  /** Détail seulement. */
  webhooks?: PaymentWebhook[];
};

export const DIRECTION_LABELS: Record<PaymentDirection, string> = { deposit: 'Dépôt', payout: 'Retrait' };

/** Noms lisibles (config/payments.php) ; un prestataire inconnu garde son code. */
const PROVIDER_LABELS: Record<string, string> = { plopplop: 'Plop Plop', fake: 'Paiement test' };
export const providerLabel = (provider: string) => PROVIDER_LABELS[provider] ?? provider;

/** Même statut, sens différent pour le joueur : on nomme l'étape selon le sens du paiement. */
export function paymentStatusLabel(status: PaymentStatus, direction: PaymentDirection): string {
  if (direction === 'payout') {
    return {
      pending: 'Demandé',
      processing: 'Envoi au prestataire',
      succeeded: 'Versé',
      failed: 'Échoué, montant rendu',
      expired: 'Expiré',
      review: 'À vérifier',
      cancelled: 'Annulé par le joueur',
    }[status];
  }

  return {
    pending: 'En attente du paiement',
    processing: 'En cours',
    succeeded: 'Crédité',
    failed: 'Refusé',
    expired: 'Expiré',
    review: 'À vérifier',
    cancelled: 'Annulé',
  }[status];
}

/** Couleur du badge : à surveiller (attente, revue), terminé, ou en échec. */
export function paymentStatusTone(status: PaymentStatus): string {
  return {
    pending: 'status-suspended',
    processing: 'status-suspended',
    review: 'status-suspended',
    succeeded: 'status-active',
    failed: 'status-closed',
    expired: 'status-closed',
    cancelled: 'status-closed',
  }[status];
}

export const PAYMENT_STATUSES: PaymentStatus[] = ['pending', 'processing', 'review', 'succeeded', 'failed', 'expired', 'cancelled'];

const KEY = ['admin', 'payments'] as const;

export type PaymentFilters = {
  direction?: PaymentDirection;
  status?: PaymentStatus;
  userId?: string;
  reference?: string;
  from?: string;
  to?: string;
  page: number;
};

export function usePayments(filters: PaymentFilters) {
  return useQuery({
    queryKey: [...KEY, 'list', filters],
    queryFn: ({ signal }) => api<Paginated<AdminPayment>>('/admin/payments', {
      signal,
      query: {
        direction: filters.direction, status: filters.status, user_id: filters.userId, reference: filters.reference,
        from: filters.from, to: filters.to, page: filters.page, per_page: 25,
      },
    }),
    placeholderData: keepPreviousData,
  });
}

export function usePayment(id: string) {
  return useQuery({
    queryKey: [...KEY, 'detail', id],
    queryFn: async ({ signal }) => (await api<Envelope<AdminPayment>>(`/admin/payments/${encodeURIComponent(id)}`, { signal })).data,
  });
}
