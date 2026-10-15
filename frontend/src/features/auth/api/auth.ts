import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError, type Envelope } from '../../../shared/api/client';

/** Utilisateur connecté (GET /me) — docs/api/openapi.yaml, schéma User. */
export type User = {
  id: string;
  name: string;
  email: string;
  status: 'active' | 'suspended' | 'excluded' | 'closed';
  roles: string[];
  is_admin: boolean;
  mfa: { enabled: boolean; verified: boolean; required: boolean };
  profile: {
    first_name: string | null;
    last_name: string | null;
    birth_date: string | null;
    phone: string | null;
    country: string | null;
  } | null;
  created_at: string;
};

export type LoginInput = { email: string; password: string };

export type RegisterInput = {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  birth_date: string;
  country?: string;
};

export type ResetPasswordInput = {
  token: string;
  email: string;
  password: string;
  password_confirmation: string;
};

export const ME_KEY = ['me'] as const;

/**
 * Session courante. Renvoie null si personne n'est connecté (401) — ce n'est pas une erreur.
 * Toute autre erreur (réseau, 5xx, compte suspendu…) remonte normalement.
 */
export function useMe() {
  return useQuery({
    queryKey: ME_KEY,
    queryFn: async ({ signal }) => {
      try {
        return (await api<Envelope<User>>('/me', { signal })).data;
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) return null;
        throw e;
      }
    },
    staleTime: 5 * 60_000,
  });
}

export function useLogin() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: LoginInput) => api<Envelope<User>>('/auth/login', { method: 'POST', body: input }),
    onSuccess: ({ data }) => queryClient.setQueryData(ME_KEY, data),
  });
}

export function useRegister() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: RegisterInput) => api<Envelope<User>>('/auth/register', { method: 'POST', body: input }),
    onSuccess: ({ data }) => queryClient.setQueryData(ME_KEY, data),
  });
}

export function useLogout() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => api<null>('/auth/logout', { method: 'POST' }),
    // Session terminée (ou déjà expirée) : on oublie toutes les données de l'utilisateur.
    onSettled: () => {
      queryClient.clear();
      queryClient.setQueryData(ME_KEY, null);
    },
  });
}

export function useForgotPassword() {
  return useMutation({
    mutationFn: (email: string) => api<Envelope<{ message: string }>>('/auth/forgot-password', { method: 'POST', body: { email } }),
  });
}

export function useResetPassword() {
  return useMutation({
    mutationFn: (input: ResetPasswordInput) => api<Envelope<{ message: string }>>('/auth/reset-password', { method: 'POST', body: input }),
  });
}

/**
 * Chemin de retour après connexion (?redirect=…). Seuls les chemins internes sont acceptés,
 * pour éviter une redirection vers un site externe (open redirect).
 */
export function safeRedirect(value: string | null, fallback = '/'): string {
  if (!value || !value.startsWith('/') || value.startsWith('//') || value.startsWith('/\\')) return fallback;
  return value;
}
