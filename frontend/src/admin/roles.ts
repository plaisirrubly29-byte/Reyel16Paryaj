/** Rôles administratifs (slugs de backend/config/permissions.php), dans l'ordre de la matrice des permissions. */
export const ADMIN_ROLES = [
  { slug: 'super_admin', label: 'Super Admin', description: 'Administration complète ; ne peut pas modifier ses propres rôles.' },
  { slug: 'operations', label: 'Opérations', description: 'Événements, marchés et résultats.' },
  { slug: 'finance', label: 'Finance', description: 'Ledger, rapprochement, règlements et corrections financières.' },
  { slug: 'support', label: 'Support', description: 'Assistance aux joueurs avec données minimales.' },
  { slug: 'compliance', label: 'Conformité', description: 'Conformité, jeu responsable et audit.' },
  { slug: 'risk_fraud', label: 'Risk & Fraud', description: 'Plafonds de risque, exposition, alertes AML et mise maximale des joueurs ; ni ledger ni corrections.' },
  { slug: 'agent_manager', label: 'Gestion des agents', description: 'Agents et commissions (consultation) ; ni ledger global ni taux de commission.' },
] as const;

/** Libellés des rôles administratifs. */
export const ROLE_LABELS: Record<string, string> = Object.fromEntries(ADMIN_ROLES.map((r) => [r.slug, r.label]));

/** Initiales d'un nom pour l'avatar (« Ana Joueuse » → « AJ »). */
export function initials(name: string | undefined): string {
  return (name ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('');
}
