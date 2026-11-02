import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope, type Money, type Paginated } from '../../shared/api/client';
import type { AdminLedgerEntry } from './tickets';

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
  /** Mise maximale par ticket posée par un administrateur (V2-D05), en unité mineure ; null = limite générale. */
  max_stake_minor: number | null;
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

/** Solde d'un compte (GET /admin/users/{id}/wallet, permission ledger.view). */
export type AdminUserWallet = { user_id: string; currency: string; balance: Money; status: 'active' | 'frozen' };

export function useAdminUserWallet(id: string, enabled: boolean) {
  return useQuery({
    queryKey: ['admin', 'users', id, 'wallet'],
    queryFn: async ({ signal }) => (await api<Envelope<AdminUserWallet>>(`/admin/users/${encodeURIComponent(id)}/wallet`, { signal })).data,
    enabled,
  });
}

export type WalletAdjustment = { type: 'deposit' | 'withdrawal'; amount_minor: number; reason: string; idempotencyKey: string };

/** Dépôt ou retrait reçu hors plateforme (Super Admin, motif obligatoire, audité par le serveur). */
export function useAdjustUserWallet(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ idempotencyKey, ...body }: WalletAdjustment) =>
      (await api<Envelope<AdminLedgerEntry>>(`/admin/users/${encodeURIComponent(id)}/wallet/adjustments`, {
        method: 'POST',
        body,
        idempotencyKey,
      })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'users', id, 'wallet'] });
      queryClient.invalidateQueries({ queryKey: ['admin', 'ledger'], exact: false });
    },
  });
}
