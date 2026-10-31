import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope, type Paginated } from '../../shared/api/client';

export type AccountStatus = 'active' | 'suspended' | 'excluded' | 'closed';

/**
 * Compte vu par l'administration (GET /admin/users). Données minimales par défaut ;
 * `roles`, `mfa_enabled` et `profile` seulement avec la permission users.view_personal_data.
 */
export type AdminUser = {
  id: string;
  name: string;
  /** Identifiant de connexion (numéro haïtien, 8 chiffres). */
  phone: string;
  email: string | null;
  status: AccountStatus;
  status_changed_at: string | null;
  is_admin: boolean;
  created_at: string;
  last_login_at: string | null;
  roles?: string[];
  mfa_enabled?: boolean;
  profile?: {
    first_name: string | null;
    last_name: string | null;
    birth_date: string | null;
    country: string | null;
  };
};

export type UserFilters = { search?: string; status?: AccountStatus; role?: string; page: number };

export const STATUS_LABELS: Record<AccountStatus, string> = {
  active: 'Actif',
  suspended: 'Suspendu',
  excluded: 'Auto-exclu',
  closed: 'Fermé',
};

export const ROLE_LABELS: Record<string, string> = {
  super_admin: 'Super Admin',
  operations: 'Opérations',
  finance: 'Finance',
  support: 'Support',
  compliance: 'Conformité',
};

export function useAdminUsers(filters: UserFilters) {
  return useQuery({
    queryKey: ['admin', 'users', filters],
    queryFn: ({ signal }) => api<Paginated<AdminUser>>('/admin/users', { signal, query: { ...filters, per_page: 20 } }),
    placeholderData: keepPreviousData,
  });
}

/** Consultation d'une fiche : journalisée par le serveur si elle inclut les données personnelles. */
export function useAdminUser(id: string) {
  return useQuery({
    queryKey: ['admin', 'users', id],
    queryFn: async ({ signal }) => (await api<Envelope<AdminUser>>(`/admin/users/${encodeURIComponent(id)}`, { signal })).data,
  });
}

export type StatusChange = { status: 'active' | 'suspended'; reason: string; idempotencyKey: string };

/** Suspension / réactivation (motif obligatoire, auditée par le serveur). */
export function useChangeUserStatus(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ status, reason, idempotencyKey }: StatusChange) =>
      (await api<Envelope<AdminUser>>(`/admin/users/${encodeURIComponent(id)}`, {
        method: 'PATCH',
        body: { status, reason },
        idempotencyKey,
      })).data,
    onSuccess: (user) => {
      queryClient.setQueryData(['admin', 'users', id], user);
      queryClient.invalidateQueries({ queryKey: ['admin', 'users'], exact: false });
    },
  });
}
