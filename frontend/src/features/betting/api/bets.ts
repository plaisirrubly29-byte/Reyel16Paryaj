import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, newIdempotencyKey, type Envelope, type Money, type Paginated } from '../../../shared/api/client';

/** Ticket accepté par le serveur (docs/api/openapi.yaml, schéma BetTicket) : reçu immuable. */
export type BetTicketStatus = 'pending' | 'won' | 'lost' | 'void';

export type BetSelection = {
  id: string;
  event: {
    id: string;
    name: string;
    starts_at: string;
    /** Repères relus dans le catalogue (Mes paris) ; absents des anciennes réponses. */
    sport?: string | null;
    competition?: { name: string; country: string | null } | null;
    status?: string | null;
    /** Score en cours ou final ; null tant qu'il n'est pas connu. */
    score?: { home: string; away: string } | null;
  };
  market: { id?: string; key: string; name: string; line: string | null };
  /** id : sélection du catalogue (rejouer le ticket). */
  selection: { id?: string; key: string; label: string; name: string };
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

export type BetFilters = {
  status?: BetTicketStatus;
  type?: BetTicket['type'];
  /** Tickets placés depuis N jours (1, 7, 30 ou 90). */
  days?: number;
};

/** GET /bets — tickets du joueur connecté, plus récents d'abord, filtrés par le serveur. */
export function useBets(page = 1, filters: BetFilters = {}) {
  return useQuery({
    queryKey: [...BETS_KEY, page, filters],
    queryFn: ({ signal }) => api<Paginated<BetTicket>>('/bets', { signal, query: { page, ...filters } }),
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
