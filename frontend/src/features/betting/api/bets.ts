import { useRef } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError, newIdempotencyKey, type Envelope, type Money, type Paginated } from '../../../shared/api/client';

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
/** label : libellé avec la ligne en vigueur (« > 3.5 ») ; absent des anciennes réponses. */
export type OddsChange = { selection_id: string; odds: string; odds_version: number; label?: string };

export type PlaceBetInput = {
  stakeMinor: number;
  currency: string;
  /** oddsVersion : version du marché vue ; le serveur refuse le pari si la ligne a changé à cote égale. */
  selections: { selectionId: string; odds: string; oddsVersion: number }[];
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
 * Résultat inconnu : la réponse n'est pas arrivée (connexion coupée, serveur en panne) ou la même demande est encore
 * en cours. Le pari a pu être placé et la mise débitée.
 */
export function isUncertainBetError(error: unknown): boolean {
  return error instanceof ApiError
    && (error.status === 0 || error.status >= 500 || error.code === 'idempotency_request_in_progress');
}

/** Même pari voulu : même mise et mêmes sélections, aux mêmes cotes et versions de marché. */
function intentOf(input: PlaceBetInput): string {
  return JSON.stringify([input.stakeMinor, input.currency, input.selections.map((s) => [s.selectionId, s.odds, s.oddsVersion])]);
}

/**
 * POST /bets — le serveur revalide tout (cote, disponibilité, limites, solde) et débite la mise.
 *
 * Une clé d'idempotence par pari voulu, pas par clic : si la réponse se perd après le débit, le joueur qui réessaie
 * renvoie la même clé et le serveur rend le ticket déjà créé au lieu d'en créer un second. La clé est oubliée dès que
 * le résultat est connu (ticket reçu ou refus du serveur) : le même pari, placé exprès une seconde fois, est un nouveau
 * pari.
 */
export function usePlaceBet() {
  const queryClient = useQueryClient();
  const keys = useRef(new Map<string, string>());

  return useMutation({
    mutationFn: async (input: PlaceBetInput) => {
      const intent = intentOf(input);
      const idempotencyKey = keys.current.get(intent) ?? newIdempotencyKey();
      keys.current.set(intent, idempotencyKey);
      return (await api<Envelope<BetTicket>>('/bets', {
        method: 'POST',
        idempotencyKey,
        body: {
          stake: { minor: input.stakeMinor, currency: input.currency },
          selections: input.selections.map((s) => ({ selection_id: Number(s.selectionId), odds: s.odds, odds_version: s.oddsVersion })),
        },
      })).data;
    },
    onSuccess: (_ticket, input) => {
      keys.current.delete(intentOf(input));
      void queryClient.invalidateQueries({ queryKey: BETS_KEY });
      void queryClient.invalidateQueries({ queryKey: ['wallet'] });
    },
    onError: (error, input) => {
      if (!isUncertainBetError(error)) {
        keys.current.delete(intentOf(input));
        return;
      }
      // Le pari a peut-être été placé : « Mes paris » et le solde sont relus pour le montrer.
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
