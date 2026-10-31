/** Libellés des rôles administratifs (slugs de backend/config/permissions.php). */
export const ROLE_LABELS: Record<string, string> = {
  super_admin: 'Super Admin',
  operations: 'Opérations',
  finance: 'Finance',
  support: 'Support',
  compliance: 'Conformité',
};

/** Initiales d'un nom pour l'avatar (« Ana Joueuse » → « AJ »). */
export function initials(name: string | undefined): string {
  return (name ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('');
}
