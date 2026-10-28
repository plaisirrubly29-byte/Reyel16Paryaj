import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, type Envelope, type Money, type Paginated } from '../../../shared/api/client';

/** GET /wallet — le solde est calculé par le serveur (somme des écritures du ledger). */
export type Wallet = {
  currency: string;
  balance: Money;
  status: 'active' | 'frozen';
  simulated: boolean;
};

export type LedgerEntryType = 'simulated_credit' | 'stake' | 'payout' | 'refund' | 'reversal';

export type LedgerEntry = {
  id: string;
  type: LedgerEntryType;
  amount: Money;
  balance_after: Money;
  related: { type: string; id: string } | null;
  description: string | null;
  created_at: string;
};

export const ENTRY_LABELS: Record<LedgerEntryType, string> = {
  simulated_credit: 'Crédit de démonstration',
  stake: 'Mise',
  payout: 'Gain',
  refund: 'Remboursement',
  reversal: 'Correction',
};

export function useWallet() {
  return useQuery({
    queryKey: ['wallet'],
    queryFn: async ({ signal }) => (await api<Envelope<Wallet>>('/wallet', { signal })).data,
  });
}

export function useWalletTransactions(params: { page: number; type?: LedgerEntryType }) {
  return useQuery({
    queryKey: ['wallet', 'transactions', params],
    queryFn: ({ signal }) => api<Paginated<LedgerEntry>>('/wallet/transactions', {
      signal,
      query: { page: params.page, type: params.type, per_page: 20 },
    }),
    placeholderData: keepPreviousData,
  });
}
