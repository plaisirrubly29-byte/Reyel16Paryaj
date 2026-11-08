import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { newIdempotencyKey } from '../../../shared/api/client';
import { useMe } from '../../auth/api/auth';
import type { VirtualBet, VirtualEvent, VirtualGameKey, VirtualResponse } from '../types';
import { findEvent, GAMES, latestResults, listBets, placeBet, upcomingEvents, type PlaceBetInput } from './simulator';

/**
 * Jeux virtuels (V2-D08) : point d'entrée unique des écrans vers les données.
 *
 * Tant que l'API du backend (#208) et le prestataire (D-11) n'existent pas, les hooks interrogent le simulateur
 * (./simulator.ts). Pour brancher le serveur, remplacer chaque `queryFn` / `mutationFn` par l'appel `api(...)`
 * correspondant (GET /virtual/events?game=, GET /virtual/events/{id}, GET /virtual/results?game=,
 * POST /virtual/bets avec Idempotency-Key, GET /virtual/bets) : les écrans ne changent pas.
 */

/**
 * Menu et pages des jeux virtuels : affichés seulement si VITE_VIRTUAL_GAMES vaut « true » (décision D-11).
 * Lu à l'appel pour pouvoir le changer en test.
 */
export function virtualGamesEnabled(): boolean {
  return import.meta.env.VITE_VIRTUAL_GAMES === 'true';
}

export const VIRTUAL_GAMES = GAMES;

export function virtualGame(key: string | null | undefined) {
  return VIRTUAL_GAMES.find((g) => g.key === key);
}

/** Rafraîchissement : les épreuves s'enchaînent toutes les quelques minutes. */
const REFRESH_MS = 10_000;

function respond<T>(build: (now: Date) => T): Promise<VirtualResponse<T>> {
  const now = new Date();
  return Promise.resolve({ data: build(now), meta: { server_time: now.toISOString() } });
}

/** Épreuves en cours et à venir d'un jeu. */
export function useVirtualEvents(game: VirtualGameKey) {
  return useQuery({
    queryKey: ['virtual', 'events', game],
    queryFn: () => respond((now) => upcomingEvents(game, now)),
    refetchInterval: REFRESH_MS,
  });
}

/** Derniers résultats d'un jeu. */
export function useVirtualResults(game: VirtualGameKey) {
  return useQuery({
    queryKey: ['virtual', 'results', game],
    queryFn: () => respond((now) => latestResults(game, now)),
    refetchInterval: REFRESH_MS,
  });
}

/** Une épreuve (data = null si l'identifiant est inconnu, comme un 404 de l'API) ; rafraîchie pour le direct. */
export function useVirtualEvent(id: string) {
  return useQuery({
    queryKey: ['virtual', 'event', id],
    queryFn: () => respond<VirtualEvent | null>((now) => findEvent(id, now)),
    refetchInterval: 3_000,
  });
}

/** Paris virtuels du joueur connecté, réglés par le serveur à la fin de chaque épreuve. */
export function useVirtualBets() {
  const me = useMe();
  const userId = me.data?.id;
  return useQuery({
    queryKey: ['virtual', 'bets', userId],
    queryFn: () => Promise.resolve<VirtualBet[]>(listBets(userId!, new Date())),
    enabled: Boolean(userId),
    refetchInterval: REFRESH_MS,
  });
}

/** Place un pari simple ; une clé d'idempotence par envoi, pour qu'un double clic ne crée pas deux paris. */
export function usePlaceVirtualBet() {
  const me = useMe();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: PlaceBetInput) => {
      if (!me.data) throw new Error('Connectez-vous pour parier.');
      return placeBet(me.data.id, input, newIdempotencyKey(), new Date());
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['virtual', 'bets'] });
    },
  });
}

/**
 * Heure du serveur, avancée chaque seconde : le décalage entre `server_time` et l'heure locale de la réponse est
 * gardé, si bien que le compte à rebours reste juste même quand l'horloge du téléphone est fausse.
 */
export function useServerNow(meta: { server_time: string } | undefined, receivedAt: number): number {
  const offset = meta ? Date.parse(meta.server_time) - receivedAt : 0;
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);

  return now + offset;
}

/** Mises encore ouvertes sur l'épreuve, à l'heure du serveur. */
export function bettingOpen(event: VirtualEvent, serverNow: number): boolean {
  return event.status === 'scheduled' && serverNow < Date.parse(event.betting_closes_at);
}
