import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import type { VirtualEvent, VirtualGameKey, VirtualResponse } from '../types';
import { DEMO_GAMES, demoEvent, demoEvents, demoResults } from './demo';

/**
 * Jeux virtuels (V2-D08) : point d'entrée unique des écrans vers les données.
 *
 * Tant que D-11 n'est pas validée (avis juridique, prestataire, contrat de l'API), il n'y a pas d'API : les hooks
 * ci-dessous renvoient les données de démonstration de ./demo.ts, avec `meta.demo = true` pour que l'interface
 * l'annonce. Pour brancher le serveur, remplacer chaque `queryFn` par l'appel `api(...)` correspondant
 * (ex. GET /virtual/events?game=football) et supprimer demo.ts : les écrans ne changent pas.
 */

/**
 * Menu et pages des jeux virtuels : masqués tant que VITE_VIRTUAL_GAMES n'est pas « true » (D-11 §6 : rien sur le
 * site, même en Staging, avant les réponses juridiques JV-1 et JV-5). Lu à l'appel pour pouvoir le changer en test.
 */
export function virtualGamesEnabled(): boolean {
  return import.meta.env.VITE_VIRTUAL_GAMES === 'true';
}

export const VIRTUAL_GAMES = DEMO_GAMES;

export function virtualGame(key: string | null | undefined) {
  return VIRTUAL_GAMES.find((g) => g.key === key);
}

/** Rafraîchissement : les épreuves s'enchaînent toutes les quelques minutes. */
const REFRESH_MS = 10_000;

function demoResponse<T>(build: (now: Date) => T): Promise<VirtualResponse<T>> {
  const now = new Date();
  return Promise.resolve({ data: build(now), meta: { server_time: now.toISOString(), demo: true } });
}

/** Épreuves en cours et à venir d'un jeu. */
export function useVirtualEvents(game: VirtualGameKey) {
  return useQuery({
    queryKey: ['virtual', 'events', game],
    queryFn: () => demoResponse((now) => demoEvents(game, now)),
    refetchInterval: REFRESH_MS,
  });
}

/** Derniers résultats d'un jeu. */
export function useVirtualResults(game: VirtualGameKey) {
  return useQuery({
    queryKey: ['virtual', 'results', game],
    queryFn: () => demoResponse((now) => demoResults(game, now)),
    refetchInterval: REFRESH_MS,
  });
}

/** Une épreuve (data = null si l'identifiant est inconnu, comme un 404 de l'API). */
export function useVirtualEvent(id: string) {
  return useQuery({
    queryKey: ['virtual', 'event', id],
    queryFn: () => demoResponse<VirtualEvent | null>((now) => demoEvent(id, now)),
    refetchInterval: 5_000,
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
