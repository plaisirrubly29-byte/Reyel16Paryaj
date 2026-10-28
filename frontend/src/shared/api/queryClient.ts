import { QueryClient } from '@tanstack/react-query';
import { ApiError } from './client';

/**
 * État serveur partagé (TanStack Query). Conventions :
 * - clé de requête = [ressource, ...paramètres], ex. ['wallet'], ['bets', { page }] ;
 * - pas de nouvelle tentative sur une erreur 4xx (la requête est invalide ou refusée) ;
 * - après une mutation, invalider les clés concernées (ex. ['wallet'] après un pari).
 */
export function createQueryClient(): QueryClient {
  return new QueryClient({
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
}
