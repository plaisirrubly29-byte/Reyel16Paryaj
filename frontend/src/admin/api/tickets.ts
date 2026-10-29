import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, type Envelope, type Money, type Paginated } from '../../shared/api/client';

export type TicketStatus = 'pending' | 'won' | 'lost' | 'void';
export type SelectionStatus = 'pending' | 'won' | 'lost' | 'void';

/** Ticket vu par l'administration (GET /admin/tickets) : données minimales du joueur (id, nom). */
export type AdminTicket = {
  id: string;
  reference: string;
  type: 'single' | 'multiple';
  status: TicketStatus;
  user: { id: string; name: string | null };
  wallet_id: string;
  stake: Money;
  total_odds: string;
  potential_payout: Money;
  payout: Money | null;
  rules_version: string;
  placed_at: string;
  settled_at: string | null;
};

export type AdminTicketSelection = {
  id: string;
  event: { id: string; name: string; starts_at: string };
  market: { key: string; name: string; line: string | null };
  selection: { key: string; label: string; name: string };
  odds: string;
  status: SelectionStatus;
};

export type AdminLedgerEntry = {
  id: string;
  type: 'simulated_credit' | 'stake' | 'payout' | 'refund' | 'reversal';
  amount: Money;
  balance_after: Money;
  reference: string;
  description: string | null;
  reversal_of_id: string | null;
  reversed_by_id: string | null;
  created_at: string;
};

/** Détail (GET /admin/tickets/{id}) : sélections ; écritures du ledger seulement avec ledger.view. */
export type AdminTicketDetail = AdminTicket & {
  rules_snapshot: Record<string, unknown>;
  selections: AdminTicketSelection[];
  ledger_entries?: AdminLedgerEntry[];
};

export type TicketFilters = {
  reference?: string;
  status?: TicketStatus;
  user_id?: string;
  placed_from?: string;
  placed_to?: string;
  page: number;
};

export const TICKET_STATUS_LABELS: Record<TicketStatus, string> = {
  pending: 'En cours',
  won: 'Gagné',
  lost: 'Perdu',
  void: 'Annulé',
};

export const LEDGER_TYPE_LABELS: Record<AdminLedgerEntry['type'], string> = {
  simulated_credit: 'Crédit fictif',
  stake: 'Mise',
  payout: 'Gain',
  refund: 'Remboursement',
  reversal: 'Compensation',
};

export function useAdminTickets(filters: TicketFilters) {
  return useQuery({
    queryKey: ['admin', 'tickets', filters],
    queryFn: ({ signal }) => api<Paginated<AdminTicket>>('/admin/tickets', { signal, query: { ...filters, per_page: 20 } }),
    placeholderData: keepPreviousData,
  });
}

export function useAdminTicket(id: string) {
  return useQuery({
    queryKey: ['admin', 'tickets', id],
    queryFn: async ({ signal }) => (await api<Envelope<AdminTicketDetail>>(`/admin/tickets/${encodeURIComponent(id)}`, { signal })).data,
  });
}
