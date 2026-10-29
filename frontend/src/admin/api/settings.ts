import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope, type Paginated } from '../../shared/api/client';

export type SettingType = 'money' | 'integer' | 'boolean' | 'choice';
export type SettingValue = number | boolean | string;

/** Paramètre modifiable (P6-08) ; type money : montant en unité mineure. */
export type Setting = {
  key: string;
  group: string;
  label: string;
  type: SettingType;
  help: string | null;
  options: string[] | null;
  currency: string | null;
  value: SettingValue;
  /** Valeur de la configuration du serveur (.env), en vigueur sans version. */
  default: SettingValue;
};

export type PlatformSettings = {
  /** 0 : aucune version enregistrée. */
  version: number;
  updated_at: string | null;
  updated_by: { id: string; name: string | null } | null;
  items: Setting[];
};

export type SettingVersion = {
  id: string;
  version: number;
  changes: { key: string; label: string; type: SettingType | null; from: SettingValue; to: SettingValue }[];
  reason: string;
  created_by: { id: string; name: string | null };
  created_at: string;
};

export function usePlatformSettings() {
  return useQuery({
    queryKey: ['admin', 'settings'],
    queryFn: async ({ signal }) => (await api<Envelope<PlatformSettings>>('/admin/settings', { signal })).data,
  });
}

export function useSettingVersions(page: number) {
  return useQuery({
    queryKey: ['admin', 'settings', 'versions', page],
    queryFn: ({ signal }) => api<Paginated<SettingVersion>>('/admin/settings/versions', { signal, query: { page, per_page: 10 } }),
    placeholderData: keepPreviousData,
  });
}

/** { "betting.min_stake_minor": 2500 } → { betting: { min_stake_minor: 2500 } } (format de PUT /admin/settings). */
export function nestValues(values: Record<string, SettingValue>): Record<string, Record<string, SettingValue>> {
  const nested: Record<string, Record<string, SettingValue>> = {};
  for (const [key, value] of Object.entries(values)) {
    const [group, ...rest] = key.split('.');
    (nested[group] ??= {})[rest.join('.')] = value;
  }
  return nested;
}

/** Nouvelle version (settings.update) ; base_version : version affichée, refusée (409) si une autre est passée entre-temps. */
export function useSaveSettings() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ values, baseVersion, reason, idempotencyKey }: { values: Record<string, SettingValue>; baseVersion: number; reason: string; idempotencyKey: string }) =>
      (await api<Envelope<PlatformSettings>>('/admin/settings', {
        method: 'PUT',
        body: { values: nestValues(values), base_version: baseVersion, reason },
        idempotencyKey,
      })).data,
    onSuccess: (updated) => {
      queryClient.setQueryData(['admin', 'settings'], updated);
      queryClient.invalidateQueries({ queryKey: ['admin', 'settings', 'versions'], exact: false });
    },
  });
}
