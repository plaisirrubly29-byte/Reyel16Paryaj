import { MutationCache, QueryCache, QueryClient, type Query } from '@tanstack/react-query';
import { ApiError } from './client';

/** Clé de la session courante (GET /me) : `null` quand personne n'est connecté. */
export const SESSION_KEY = ['me'] as const;

const isSession = (query: Pick<Query, 'queryKey'>) => query.queryKey.length === 1 && query.queryKey[0] === SESSION_KEY[0];

/**
 * Oublie tout ce qui appartient au compte (solde, tickets, appareils…) ; seule la session reste, à mettre à jour
 * par l'appelant. On ne vide pas tout le cache : clear() détacherait les composants affichés (en-tête, coupon…).
 */
export function forgetAccountData(client: QueryClient): void {
  client.removeQueries({ predicate: (query) => !isSession(query) });
}

/**
 * État serveur partagé (TanStack Query). Conventions :
 * - clé de requête = [ressource, ...paramètres], ex. ['wallet'], ['bets', { page }] ;
 * - pas de nouvelle tentative sur une erreur 4xx (la requête est invalide ou refusée) ;
 * - après une mutation, invalider les clés concernées (ex. ['wallet'] après un pari).
 *
 * Appareil partagé (revue P8-02, F11) : les données d'un compte ne survivent pas à sa session.
 * - Le compte de la session change (déconnexion, autre compte, session expirée vue par GET /me) : ses données
 *   en cache sont oubliées, quel que soit l'endroit du code qui a mis la session à jour.
 * - Le serveur répond 401 à un compte que l'écran croit connecté (session expirée ou fermée depuis un autre
 *   appareil) : la session est close ici aussi, et les pages protégées renvoient à la connexion.
 */
export function createQueryClient(): QueryClient {
  let sessionUserId: string | null | undefined;

  const endSession = () => {
    if (client.getQueryData(SESSION_KEY)) client.setQueryData(SESSION_KEY, null);
  };
  const unauthenticated = (error: unknown) => error instanceof ApiError && error.status === 401;

  const queryCache = new QueryCache({
    onError: (error, query) => { if (unauthenticated(error) && !isSession(query)) endSession(); },
  });
  const mutationCache = new MutationCache({
    onError: (error) => { if (unauthenticated(error)) endSession(); },
  });

  const client = new QueryClient({
    queryCache,
    mutationCache,
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        refetchOnWindowFocus: false,
        retry: (failureCount, error) => {
          if (error instanceof ApiError && error.status >= 400 && error.status < 500) return false;
          return failureCount < 2;
        },
      },
      mutations: { retry: false },
    },
  });

  queryCache.subscribe((event) => {
    if (event.type !== 'updated' || event.action.type !== 'success' || !isSession(event.query)) return;
    const userId = (event.query.state.data as { id?: string } | null | undefined)?.id ?? null;
    if (sessionUserId != null && sessionUserId !== userId) forgetAccountData(client);
    sessionUserId = userId;
  });

  return client;
}
