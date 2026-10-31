import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, downloadFile, type Paginated } from '../../shared/api/client';

/** Entrée du journal d'audit (GET /admin/audit-logs, P6-01). */
export type AuditLogEntry = {
  id: string;
  action: string;
  actor: { id: string; email: string | null } | null;
  subject: { type: string; id: string | null } | null;
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  reason: string | null;
  metadata: Record<string, unknown> | null;
  ip_address: string | null;
  request_id: string | null;
  created_at: string;
};

export type AuditFilters = { action?: string; actor_id?: string; subject_type?: string; subject_id?: string; from?: string; to?: string };

/** Libellés des actions (backend : App\Modules\Audit\Enums\AuditAction). */
export const AUDIT_ACTION_LABELS: Record<string, string> = {
  'roles.updated': 'Rôles modifiés',
  'user.suspended': 'Compte suspendu',
  'user.reactivated': 'Compte réactivé',
  'user.self_excluded': 'Auto-exclusion',
  'user.self_exclusion_ended': 'Fin d\'auto-exclusion',
  'responsible_gaming.viewed': 'Jeu responsable consulté',
  'mfa.reset': 'MFA réinitialisé',
  'mfa.failed': 'Échec MFA',
  'mfa.recovery_codes_regenerated': 'Codes de secours régénérés',
  'user.personal_data_viewed': 'Données personnelles consultées',
  'market.suspended': 'Marché suspendu',
  'market.reopened': 'Marché rouvert',
  'finance.correction_requested': 'Correction financière demandée',
  'finance.correction_approved': 'Correction financière validée',
  'finance.correction_rejected': 'Correction financière refusée',
  'result.correction_requested': 'Correction de résultat demandée',
  'result.correction_approved': 'Correction de résultat validée',
  'result.correction_rejected': 'Correction de résultat refusée',
  'settings.updated': 'Paramètres modifiés',
  'audit.viewed': 'Journal consulté',
  'audit.exported': 'Journal exporté',
};

/** Les dates du formulaire (AAAA-MM-JJ) couvrent la journée entière. */
function toQuery(filters: AuditFilters) {
  return { ...filters, from: filters.from && `${filters.from} 00:00:00`, to: filters.to && `${filters.to} 23:59:59` };
}

export function useAuditLogs(filters: AuditFilters & { page: number }) {
  return useQuery({
    queryKey: ['admin', 'audit-logs', filters],
    queryFn: ({ signal }) => api<Paginated<AuditLogEntry>>('/admin/audit-logs', { signal, query: { ...toQuery(filters), page: filters.page, per_page: 25 } }),
    placeholderData: keepPreviousData,
  });
}

/** Export CSV (audit.export) avec motif, journalisé par le serveur avant l'envoi du fichier. */
export function exportAuditLogs(filters: AuditFilters, reason: string) {
  const body = Object.fromEntries(Object.entries({ ...toQuery(filters), reason }).filter(([, v]) => v !== undefined && v !== ''));
  return downloadFile('/admin/audit-logs/export', body);
}
