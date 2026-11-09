import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, newIdempotencyKey, type Envelope, type Money, type Paginated } from '../../../shared/api/client';
import type { LotteryPlay } from '../games';

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

/**
 * Vente des fiches : le backend (étape 2 du module Lottery) n'existe pas encore. Tant que VITE_LOTTERY_SALES n'est pas
 * « true », la fiche se remplit mais ne s'envoie pas.
 */
export const LOTTERY_SALES_OPEN = import.meta.env.VITE_LOTTERY_SALES === 'true';

/** Fiche acceptée par le serveur (contrat proposé pour l'étape 2). */
export type LotteryTicket = {
  id: string;
  reference: string;
  draw: { key: string; name: string; date: string };
  stake: Money;
  plays: { game: string; option: number | null; numbers: string[]; stake: Money }[];
  status: 'pending' | 'won' | 'lost' | 'void';
  placed_at: string;
};

/**
 * POST /lottery/tickets — contrat proposé au backend (étape 2) :
 * { draw: "NYM", plays: [{ game: "bolet", option: null, numbers: ["25"], stake: { minor: 5000, currency: "HTG" } }] }.
 * Le serveur revalide tout (vente ouverte, plafonds par boule, solde) et débite le total.
 */
export function usePlaceLotteryTicket() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { draw: string; plays: LotteryPlay[]; currency: string }) => api<Envelope<LotteryTicket>>('/lottery/tickets', {
      method: 'POST',
      idempotencyKey: newIdempotencyKey(),
      body: {
        draw: input.draw,
        plays: input.plays.map((p) => ({
          game: p.game,
          option: p.option,
          numbers: p.numbers,
          // Gourdes entières saisies → unité mineure (2 décimales pour la gourde).
          stake: { minor: p.stake * 100, currency: input.currency },
        })),
      },
    }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['wallet'] });
    },
  });
}
