import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, newIdempotencyKey, type Envelope, type Money, type Paginated } from '../../../shared/api/client';

/** Ticket accepté par le serveur (docs/api/openapi.yaml, schéma BetTicket) : reçu immuable. */
export type BetTicketStatus = 'pending' | 'won' | 'lost' | 'void';

export type BetSelection = {
  id: string;
  event: { id: string; name: string; starts_at: string };
  market: { key: string; name: string; line: string | null };
  selection: { key: string; label: string; name: string };
  /** Cote acceptée, figée sur le ticket. */
  odds: string;
  status: BetTicketStatus;
};

export type BetTicket = {
  id: string;
  /** Référence du reçu (ex. « R16-7KQ2M9XAPB »), aussi utilisée comme betcode à partager. */
  reference: string;
  type: 'single' | 'multiple';
  status: BetTicketStatus;
  stake: Money;
  total_odds: string;
  /** Calculé par le serveur à l'acceptation : affiché tel quel. */
  potential_payout: Money;
  payout: Money | null;
  placed_at: string;
  settled_at: string | null;
  selections: BetSelection[];
};

/** Détail de l'erreur 409 odds_changed : la cote actuelle de chaque sélection qui a bougé. */
export type OddsChange = { selection_id: string; odds: string; odds_version: number };

export type PlaceBetInput = {
  stakeMinor: number;
  currency: string;
  selections: { selectionId: string; odds: string }[];
};

export const BETS_KEY = ['bets'] as const;

/** GET /bets — tickets du joueur connecté, plus récents d'abord. */
export function useBets(page = 1) {
  return useQuery({
    queryKey: [...BETS_KEY, page],
    queryFn: ({ signal }) => api<Paginated<BetTicket>>('/bets', { signal, query: { page } }),
    placeholderData: keepPreviousData,
  });
}

/**
 * POST /bets — le serveur revalide tout (cote, disponibilité, limites, solde) et débite la mise.
 * Une clé d'idempotence par tentative : un double envoi de la même tentative ne crée qu'un ticket.
 */
export function usePlaceBet() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: PlaceBetInput) => (await api<Envelope<BetTicket>>('/bets', {
      method: 'POST',
      idempotencyKey: newIdempotencyKey(),
      body: {
        stake: { minor: input.stakeMinor, currency: input.currency },
        selections: input.selections.map((s) => ({ selection_id: Number(s.selectionId), odds: s.odds })),
      },
    })).data,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: BETS_KEY });
      void queryClient.invalidateQueries({ queryKey: ['wallet'] });
    },
  });
}

export const TICKET_STATUS_LABELS: Record<BetTicketStatus, string> = {
  pending: 'En attente',
  won: 'Gagné',
  lost: 'Perdu',
  void: 'Remboursé',
};
