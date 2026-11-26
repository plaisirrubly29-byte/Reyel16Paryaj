import { useRef } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError, newIdempotencyKey, type Envelope, type Money, type Paginated } from '../../../shared/api/client';

/** Résultat publié d'un tirage (GET /lottery/results, docs/api/openapi.yaml, tag Lottery). */
export type LotteryResult = {
  draw: { key: string; name: string };
  /** Date locale du tirage, "YYYY-MM-DD". */
  date: string;
  drawn_at: string;
  /** Trois lots de deux chiffres, toujours en chaînes (« 07 »). */
  lots: [string, string, string];
  pick3: string | null;
  pick4: string | null;
  published_at: string | null;
};

/** Tirage actif (GET /lottery/draws). */
export type LotteryDraw = {
  key: string;
  name: string;
  /** Heure locale du tirage (fin des ventes), "HH:MM". */
  draw_time: string;
  timezone: string;
  /** Jours ISO (1 = lundi … 7 = dimanche) ; null = tous les jours. */
  days: number[] | null;
  /** Prochain tirage, en UTC ; null si aucun n'est prévu. */
  next_draw_at: string | null;
  latest_result: LotteryResult | null;
};

const REFRESH_MS = 60_000;

/**
 * GET /lottery/draws — 404 tant que la borlette est coupée côté serveur (LOTTERY_ENABLED=false) : la requête échoue
 * sans nouvel essai (4xx) et l'entrée « Borlette » reste masquée dans les menus.
 */
export function useLotteryDraws() {
  return useQuery({
    queryKey: ['lottery', 'draws'],
    queryFn: async ({ signal }) => (await api<Envelope<LotteryDraw[]>>('/lottery/draws', { signal })).data,
    refetchInterval: REFRESH_MS,
  });
}

/** La borlette est-elle proposée (serveur activé et au moins un tirage) ? */
export function useLotteryAvailable(): boolean {
  const draws = useLotteryDraws();
  return (draws.data?.length ?? 0) > 0;
}

/** GET /lottery/results?draw= — derniers résultats d'un tirage, du plus récent au plus ancien. */
export function useLotteryResults(draw: string | undefined, perPage = 10) {
  return useQuery({
    queryKey: ['lottery', 'results', draw, perPage],
    queryFn: ({ signal }) => api<Paginated<LotteryResult>>('/lottery/results', { signal, query: { draw, per_page: perPage } }),
    enabled: Boolean(draw),
    placeholderData: keepPreviousData,
    refetchInterval: REFRESH_MS,
  });
}

export type LotteryGameKey = 'bolet' | 'maryaj' | 'loto3' | 'loto4' | 'loto5';

/** Jeu en vente et son barème (GET /lottery/games, schéma LotteryGame). */
export type LotteryGameOffer = {
  key: LotteryGameKey;
  /** Chiffres de chaque numéro, et numéros par ligne (maryaj : 2). */
  digits: number;
  count: number;
  /** Options proposées (loto4 : 1, 2, 3 ; loto5 : 1, 2) ; vide sinon. */
  options: number[];
  min_stake: Money;
  max_stake: Money;
  /** Multiplicateurs en chaînes ; bolet : un par lot (1, 2, 3), autres jeux : un seul (lot null). */
  payouts: { lot: number | null; multiplier: string }[];
};

/** Conditions de vente : le serveur décide si la vente est ouverte, et de tout le barème. */
export type LotterySales = {
  sales_open: boolean;
  /** Fin des ventes, en minutes avant l'heure du tirage. */
  close_before_minutes: number;
  max_plays_per_ticket: number;
  games: LotteryGameOffer[];
};

/** GET /lottery/games — vente ouverte ou non, jeux en vente, mises min/max et barème. */
export function useLotteryGames() {
  return useQuery({
    queryKey: ['lottery', 'games'],
    queryFn: async ({ signal }) => (await api<Envelope<LotterySales>>('/lottery/games', { signal })).data,
    refetchInterval: REFRESH_MS,
  });
}

export type LotteryTicketStatus = 'pending' | 'won' | 'lost' | 'void';

/** Ligne d'une fiche vendue : mise et barème figés à la vente ; statut et gain évoluent au règlement. */
export type LotteryTicketPlay = {
  game: LotteryGameKey;
  option: number | null;
  numbers: string[];
  stake: Money;
  max_payout: Money;
  status: LotteryTicketStatus;
  /** Bolèt : lot gagnant (1, 2 ou 3) ; null sinon. */
  winning_lot: number | null;
  payout: Money | null;
};

/** Fiche de borlette (schéma LotteryTicket) : reçu immuable. */
export type LotteryTicket = {
  id: string;
  /** Référence à communiquer au support (« B16-… »). */
  reference: string;
  draw: { key: string; name: string; date: string; draw_at: string };
  status: LotteryTicketStatus;
  stake: Money;
  max_payout: Money;
  payout: Money | null;
  plays: LotteryTicketPlay[];
  result: LotteryResult | null;
  placed_at: string;
  settled_at: string | null;
};

export const LOTTERY_TICKETS_KEY = ['lottery', 'tickets'] as const;

/** GET /lottery/tickets — fiches du joueur connecté, plus récentes d'abord. */
export function useLotteryTickets(page = 1, status?: LotteryTicketStatus) {
  return useQuery({
    queryKey: [...LOTTERY_TICKETS_KEY, page, status ?? null],
    queryFn: ({ signal }) => api<Paginated<LotteryTicket>>('/lottery/tickets', { signal, query: { page, status } }),
    placeholderData: keepPreviousData,
  });
}

/** GET /lottery/tickets/{id} — une fiche, avec le résultat du tirage une fois publié. */
export function useLotteryTicket(id: string | undefined) {
  return useQuery({
    queryKey: [...LOTTERY_TICKETS_KEY, 'detail', id],
    queryFn: async ({ signal }) => (await api<Envelope<LotteryTicket>>(`/lottery/tickets/${encodeURIComponent(id!)}`, { signal })).data,
    enabled: Boolean(id),
  });
}

/** Ligne envoyée au serveur (mise en unité mineure). */
export type LotteryPlayInput = { game: LotteryGameKey; option: number | null; numbers: string[]; stake: Money };

export type PlaceLotteryTicketInput = {
  draw: string;
  /** Date locale du tirage visée : le joueur ne joue jamais pour un autre jour sans le savoir. */
  date: string;
  plays: LotteryPlayInput[];
};

/** Résultat inconnu (connexion coupée, panne, même demande encore en cours) : la fiche a pu être vendue. */
export function isUncertainLotteryError(error: unknown): boolean {
  return error instanceof ApiError
    && (error.status === 0 || error.status >= 500 || error.code === 'idempotency_request_in_progress');
}

/** Même fiche voulue : même tirage, même date, mêmes lignes et mises. */
function intentOf(input: PlaceLotteryTicketInput): string {
  return JSON.stringify([input.draw, input.date, input.plays.map((p) => [p.game, p.option, p.numbers, p.stake.minor, p.stake.currency])]);
}

/**
 * POST /lottery/tickets — le serveur revalide tout (vente ouverte, tirage, mises, boules bloquées, plafonds, solde,
 * jeu responsable) et débite le total. Tout ou rien.
 *
 * Comme pour un pari sportif : une clé d'idempotence par fiche voulue, pas par clic. Si la réponse se perd après le
 * débit, renvoyer la même fiche renvoie la même clé et le serveur rend la fiche déjà vendue. La clé est oubliée dès que
 * le résultat est connu (fiche reçue ou refus).
 */
export function usePlaceLotteryTicket() {
  const queryClient = useQueryClient();
  const keys = useRef(new Map<string, string>());

  return useMutation({
    mutationFn: async (input: PlaceLotteryTicketInput) => {
      const intent = intentOf(input);
      const idempotencyKey = keys.current.get(intent) ?? newIdempotencyKey();
      keys.current.set(intent, idempotencyKey);
      return (await api<Envelope<LotteryTicket>>('/lottery/tickets', {
        method: 'POST',
        idempotencyKey,
        body: { draw: input.draw, date: input.date, plays: input.plays },
      })).data;
    },
    onSuccess: (_ticket, input) => {
      keys.current.delete(intentOf(input));
      void queryClient.invalidateQueries({ queryKey: LOTTERY_TICKETS_KEY });
      void queryClient.invalidateQueries({ queryKey: ['wallet'] });
    },
    onError: (error, input) => {
      if (error instanceof ApiError && error.code === 'lottery_sales_closed') {
        void queryClient.invalidateQueries({ queryKey: ['lottery', 'games'] });
      }
      if (!isUncertainLotteryError(error)) {
        keys.current.delete(intentOf(input));
        return;
      }
      // La fiche a peut-être été vendue : « Mes paris » et le solde sont relus pour le montrer.
      void queryClient.invalidateQueries({ queryKey: LOTTERY_TICKETS_KEY });
      void queryClient.invalidateQueries({ queryKey: ['wallet'] });
    },
  });
}

export const LOTTERY_STATUS_LABELS: Record<LotteryTicketStatus, string> = {
  pending: 'En cours',
  won: 'Gagné',
  lost: 'Perdu',
  void: 'Remboursé',
};
