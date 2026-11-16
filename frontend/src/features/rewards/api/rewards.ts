import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, api, type Envelope, type Money, type Paginated } from '../../../shared/api/client';

/**
 * Fidélité et parrainage (V2-E07, règles de D-19) — /me/loyalty, /me/referral, docs/api/openapi.yaml (tag Rewards).
 * Tant que le serveur n'a pas activé un programme, il répond 404 : la rubrique s'affiche « Bientôt ».
 */

export type LoyaltyTierKey = 'bronze' | 'silver' | 'gold' | 'diamond';

export type LoyaltyTier = {
  key: LoyaltyTierKey;
  label: string;
  /** Points gagnés sur 12 mois pour atteindre le palier. */
  min_points: number;
  /** Bonus obtenu pour 100 points à ce palier. */
  rate: Money;
};

/** Conditions de mise de tout bonus (D-19 §4) : miser multiplicateur × bonus, cote minimale, délai. */
export type BonusTerms = { wagering_multiplier: number; min_odds: string; days: number };

/** Conditions de mise d'un bonus en cours (D-19 §4). */
export type BonusWagering = {
  amount: Money;
  required: Money;
  wagered: Money;
  min_odds: string;
  expires_at: string;
};

export type LoyaltyState = {
  points: number;
  points_12_months: number;
  tier: LoyaltyTier;
  next_tier: LoyaltyTier | null;
  tiers: LoyaltyTier[];
  earn_rule: { stake_step: Money; points_per_step: number };
  exchange: { min_points: number; step: number };
  points_expiring: { points: number; expires_at: string } | null;
  bonus_balance: Money;
  wagering: BonusWagering[];
  bonus_terms: BonusTerms;
};

export type LoyaltyEntry = {
  id: string;
  type: 'earned' | 'redeemed' | 'expired' | 'adjusted';
  points: number;
  label: string;
  bonus: Money | null;
  created_at: string;
};

export type ReferralStatus = 'pending' | 'qualified' | 'rewarded' | 'expired' | 'blocked';

export type Referral = {
  id: string;
  name: string;
  joined_at: string;
  status: ReferralStatus;
  steps: { kyc: boolean; deposit: boolean; stake: boolean };
  qualify_before: string;
};

export type ReferralState = {
  code: string;
  link: string;
  reward: Money;
  /** false : identité du parrain non vérifiée ou compte restreint (récompenses en attente). */
  eligible: boolean;
  rules: { qualify_days: number; min_deposit: Money; min_stake: Money; monthly_limit: number };
  rewarded_this_month: number;
  total_rewards: Money;
  bonus_terms: BonusTerms;
  referrals: Referral[];
};

export const LOYALTY_KEY = ['loyalty'] as const;
export const REFERRAL_KEY = ['referral'] as const;

/** Programme pas encore ouvert par le serveur (404) : à afficher « Bientôt », pas comme une panne. */
export const isNotOpen = (error: unknown) => error instanceof ApiError && error.status === 404;

export function useLoyalty() {
  return useQuery({
    queryKey: LOYALTY_KEY,
    queryFn: async ({ signal }) => (await api<Envelope<LoyaltyState>>('/me/loyalty', { signal })).data,
  });
}

export function useLoyaltyHistory(page = 1, enabled = true) {
  return useQuery({
    queryKey: [...LOYALTY_KEY, 'history', page],
    queryFn: ({ signal }) => api<Paginated<LoyaltyEntry>>('/me/loyalty/history', { signal, query: { page, per_page: 10 } }),
    enabled,
  });
}

/** Échange de points contre un bonus ; l'état renvoyé remplace celui en cache, l'historique et le solde sont relus. */
export function useRedeemPoints() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ points, idempotencyKey }: { points: number; idempotencyKey: string }) =>
      (await api<Envelope<LoyaltyState>>('/me/loyalty/redeem', { method: 'POST', body: { points }, idempotencyKey })).data,
    onSuccess: (state) => {
      queryClient.setQueryData(LOYALTY_KEY, state);
      void queryClient.invalidateQueries({ queryKey: [...LOYALTY_KEY, 'history'] });
      void queryClient.invalidateQueries({ queryKey: ['wallet'] });
    },
  });
}

export function useReferral() {
  return useQuery({
    queryKey: REFERRAL_KEY,
    queryFn: async ({ signal }) => (await api<Envelope<ReferralState>>('/me/referral', { signal })).data,
  });
}
