import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope, type Money, type Paginated } from '../../shared/api/client';
import type { AdminLedgerEntry } from './tickets';

/** Écriture du ledger vue par la Finance (GET /admin/ledger, P6-04). */
export type FinanceLedgerEntry = AdminLedgerEntry & {
  wallet_id: string;
  user_id: string;
  related: { type: string; id: string } | null;
  created_by: string | null;
  /** Peut faire l'objet d'une correction (sinon : compensation, écriture d'un ticket ou déjà compensée). */
  correctable: boolean;
};

export type LedgerFilters = { user_id?: string; type?: AdminLedgerEntry['type']; reference?: string; from?: string; to?: string; page: number };

export function useLedger(filters: LedgerFilters) {
  return useQuery({
    queryKey: ['admin', 'ledger', filters],
    queryFn: ({ signal }) => api<Paginated<FinanceLedgerEntry>>('/admin/ledger', { signal, query: { ...filters, per_page: 20 } }),
    placeholderData: keepPreviousData,
  });
}

export type DiscrepancyKind = 'balance_chain' | 'ticket_amount' | 'orphan_entry';

export const DISCREPANCY_LABELS: Record<DiscrepancyKind, string> = {
  balance_chain: 'Chaîne de soldes rompue',
  ticket_amount: 'Montant de ticket incohérent',
  orphan_entry: 'Écriture sans ticket',
};

export type Discrepancy = {
  kind: DiscrepancyKind;
  wallet_id?: string;
  ledger_entry_id?: string;
  ticket_id?: string;
  reference?: string;
  status?: string;
  expected?: Money;
  actual?: Money;
  related?: { type: string; id: string };
};

/** Rapprochement du ledger (GET /admin/finance/reconciliation) : totaux et écarts, calculés à la demande. */
export type Reconciliation = {
  generated_at: string;
  checked: { wallets: number; ledger_entries: number; tickets: number };
  totals: { currency: string; type: AdminLedgerEntry['type']; entries: number; sum: Money }[];
  discrepancies_count: number;
  discrepancies: Discrepancy[];
  truncated: boolean;
};

export function useReconciliation() {
  return useQuery({
    queryKey: ['admin', 'finance', 'reconciliation'],
    queryFn: async ({ signal }) => (await api<Envelope<Reconciliation>>('/admin/finance/reconciliation', { signal })).data,
  });
}

export type FinanceCorrectionStatus = 'pending' | 'approved' | 'rejected';

export const FINANCE_CORRECTION_STATUS_LABELS: Record<FinanceCorrectionStatus, string> = {
  pending: 'À valider',
  approved: 'Validée',
  rejected: 'Refusée',
};

/** Correction financière à double validation (P6-04). */
export type FinanceCorrection = {
  id: string;
  status: FinanceCorrectionStatus;
  reason: string;
  ledger_entry: FinanceLedgerEntry;
  requested_by: { id: string; name: string | null };
  requested_at: string;
  decided_by: { id: string; name: string | null } | null;
  decision_reason: string | null;
  decided_at: string | null;
  reversal_entry_id: string | null;
};

export function useFinanceCorrections(filters: { status?: FinanceCorrectionStatus; page: number }) {
  return useQuery({
    queryKey: ['admin', 'finance', 'corrections', filters],
    queryFn: ({ signal }) => api<Paginated<FinanceCorrection>>('/admin/finance/corrections', { signal, query: { ...filters, per_page: 20 } }),
    placeholderData: keepPreviousData,
  });
}

export function useFinanceCorrection(id: string) {
  return useQuery({
    queryKey: ['admin', 'finance', 'corrections', id],
    queryFn: async ({ signal }) => (await api<Envelope<FinanceCorrection>>(`/admin/finance/corrections/${encodeURIComponent(id)}`, { signal })).data,
  });
}

/** Demander la compensation d'une écriture (finance.correct) : rien n'est écrit avant la validation d'un autre administrateur. */
export function useRequestFinanceCorrection() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ ledgerEntryId, reason, idempotencyKey }: { ledgerEntryId: string; reason: string; idempotencyKey: string }) =>
      (await api<Envelope<FinanceCorrection>>('/admin/finance/corrections', { method: 'POST', body: { ledger_entry_id: Number(ledgerEntryId), reason }, idempotencyKey })).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'finance', 'corrections'], exact: false }),
  });
}

/** Valider (finance.approve, jamais le demandeur) ou refuser / retirer, avec motif. */
export function useDecideFinanceCorrection(correction: FinanceCorrection) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ decision, reason, idempotencyKey }: { decision: 'approve' | 'reject'; reason: string; idempotencyKey: string }) =>
      (await api<Envelope<FinanceCorrection>>(`/admin/finance/corrections/${encodeURIComponent(correction.id)}/${decision}`, {
        method: 'POST',
        body: { reason },
        idempotencyKey,
      })).data,
    onSuccess: (updated) => {
      queryClient.setQueryData(['admin', 'finance', 'corrections', correction.id], updated);
      // Les listes changent ; la demande affichée vient d'être remplacée par la réponse (pas de rechargement).
      queryClient.invalidateQueries({ queryKey: ['admin', 'finance', 'corrections'], predicate: (q) => q.queryKey[3] !== correction.id });
      queryClient.invalidateQueries({ queryKey: ['admin', 'finance', 'reconciliation'] });
      queryClient.invalidateQueries({ queryKey: ['admin', 'ledger'], exact: false });
    },
  });
}
