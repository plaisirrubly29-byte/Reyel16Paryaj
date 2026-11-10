import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope } from '../../../shared/api/client';

/** Appareil connecté au compte (GET /me/sessions) — docs/api/openapi.yaml, V2-C06. */
export type DeviceSession = {
  /** Empreinte de la session : sert seulement à désigner l'appareil à déconnecter. */
  id: string;
  ip_address: string | null;
  user_agent: string | null;
  last_active_at: string;
  /** Appareil de la requête : il se ferme avec la déconnexion, pas depuis cette liste. */
  current: boolean;
};

const KEY = ['me', 'sessions'] as const;

/** Appareils connectés, celui en cours d'abord (ordre fourni par le serveur). */
export function useSessions() {
  return useQuery({
    queryKey: KEY,
    queryFn: async ({ signal }) => (await api<Envelope<DeviceSession[]>>('/me/sessions', { signal })).data,
    // Une connexion sur un autre appareil ne prévient pas celui-ci : on relit la liste à chaque visite.
    staleTime: 0,
  });
}

/** Après une déconnexion (réussie ou refusée : appareil déjà parti), la liste du serveur fait foi. */
function useRevoke<T>(request: (input: T) => Promise<null>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: request,
    onSettled: () => queryClient.invalidateQueries({ queryKey: KEY }),
  });
}

export function useRevokeSession() {
  return useRevoke((id: string) => api<null>(`/me/sessions/${encodeURIComponent(id)}`, { method: 'DELETE' }));
}

export function useRevokeOtherSessions() {
  return useRevoke<void>(() => api<null>('/me/sessions', { method: 'DELETE' }));
}
