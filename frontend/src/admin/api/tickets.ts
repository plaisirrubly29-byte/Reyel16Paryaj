import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope, type Money, type Paginated } from '../../shared/api/client';

/** awaiting / rejected : pari en direct en validation, puis refusé avec la mise rendue (D-20). */
export type TicketStatus = 'awaiting' | 'pending' | 'won' | 'lost' | 'void' | 'rejected';
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
  type: 'simulated_credit' | 'deposit' | 'withdrawal' | 'stake' | 'payout' | 'refund' | 'reversal';
  amount: Money;
  balance_after: Money;
  reference: string;
  description: string | null;
  reversal_of_id: string | null;
  reversed_by_id: string | null;
  created_at: string;
};

export type TicketVoidStatus = 'pending' | 'approved' | 'rejected';

export const TICKET_VOID_STATUS_LABELS: Record<TicketVoidStatus, string> = {
  pending: 'À valider',
  approved: 'Validée',
  rejected: 'Refusée',
};

/** Effet d'une annulation : statut et gain avant / après, écart au portefeuille du joueur. */
export type TicketVoidImpact = {
  from: { status: TicketStatus; payout_minor: number };
  to: { status: TicketStatus; payout_minor: number };
  ledger_delta_minor: number;
};

/** Demande d'annulation d'un ticket, à double validation (tickets.void, puis tickets.void_approve d'un autre). */
export type TicketVoid = {
  id: string;
  ticket: { id: string; reference: string; status: TicketStatus; stake: Money };
  status: TicketVoidStatus;
  ticket_status: TicketStatus;
  reason: string;
  requested_by: { id: string; name: string | null };
  decided_by: { id: string; name: string } | null;
  decision_reason: string | null;
  decided_at: string | null;
  impact: TicketVoidImpact | null;
  /** Demande en attente (détail) : ce que la validation changerait maintenant. */
  preview?: TicketVoidImpact;
  created_at: string | null;
};

/** Détail (GET /admin/tickets/{id}) : sélections ; écritures du ledger seulement avec ledger.view. */
export type AdminTicketDetail = AdminTicket & {
  rules_snapshot: Record<string, unknown>;
  selections: AdminTicketSelection[];
  ledger_entries?: AdminLedgerEntry[];
  voids?: TicketVoid[];
};

export type TicketFilters = {
  reference?: string;
  status?: TicketStatus;
  user_id?: string;
  event_id?: string;
  void_status?: TicketVoidStatus;
  placed_from?: string;
  placed_to?: string;
  page: number;
};

export const TICKET_STATUS_LABELS: Record<TicketStatus, string> = {
  awaiting: 'En validation (direct)',
  pending: 'En cours',
  won: 'Gagné',
  lost: 'Perdu',
  void: 'Annulé',
  rejected: 'Refusé (direct)',
};

export const LEDGER_TYPE_LABELS: Record<AdminLedgerEntry['type'], string> = {
  simulated_credit: 'Crédit fictif',
  deposit: 'Dépôt',
  withdrawal: 'Retrait',
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

/** Détail d'une demande d'annulation, avec l'aperçu de son effet tant qu'elle attend. */
export function useTicketVoid(id: string | undefined) {
  return useQuery({
    queryKey: ['admin', 'ticket-voids', id],
    queryFn: async ({ signal }) => (await api<Envelope<TicketVoid>>(`/admin/ticket-voids/${encodeURIComponent(id ?? '')}`, { signal })).data,
    enabled: Boolean(id),
  });
}

/** Demander l'annulation d'un ticket (tickets.void), motif obligatoire : rien ne change avant la validation. */
export function useRequestTicketVoid(ticketId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ reason, idempotencyKey }: { reason: string; idempotencyKey: string }) =>
      (await api<Envelope<TicketVoid>>(`/admin/tickets/${encodeURIComponent(ticketId)}/void`, { method: 'POST', body: { reason }, idempotencyKey })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'tickets'], exact: false });
      queryClient.invalidateQueries({ queryKey: ['admin', 'ticket-voids'], exact: false });
    },
  });
}

export type TicketVoidDecision = { decision: 'approve' | 'reject'; reason: string; idempotencyKey: string };

/** Valider (tickets.void_approve, jamais le demandeur) ou refuser / retirer une demande d'annulation, avec motif. */
export function useDecideTicketVoid(voidId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ decision, reason, idempotencyKey }: TicketVoidDecision) =>
      (await api<Envelope<TicketVoid>>(`/admin/ticket-voids/${encodeURIComponent(voidId)}/${decision}`, { method: 'POST', body: { reason }, idempotencyKey })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'tickets'], exact: false });
      queryClient.invalidateQueries({ queryKey: ['admin', 'ticket-voids'], exact: false });
    },
  });
}
