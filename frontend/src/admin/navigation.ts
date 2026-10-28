/**
 * Sections de l'administration et permission requise (backend/config/permissions.php).
 * Une section s'affiche si l'utilisateur possède AU MOINS une des permissions listées.
 * Confort d'affichage : l'API refuse elle-même tout accès non autorisé (403).
 */
export type AdminSection = { to: string; label: string; permissions: string[]; end?: boolean };

export const ADMIN_SECTIONS: AdminSection[] = [
  { to: '/admin', label: "Vue d'ensemble", permissions: ['dashboard.view'], end: true },
  { to: '/admin/events', label: 'Événements & marchés', permissions: ['events.view'] },
  { to: '/admin/settlements', label: 'Résultats & règlement', permissions: ['results.enter', 'settlement.run'] },
  { to: '/admin/users', label: 'Utilisateurs', permissions: ['users.view'] },
  { to: '/admin/tickets', label: 'Tickets', permissions: ['tickets.view'] },
  { to: '/admin/finance', label: 'Finance', permissions: ['ledger.view'] },
  { to: '/admin/settings', label: 'Paramètres', permissions: ['settings.view'] },
  { to: '/admin/audit', label: 'Audit & rapports', permissions: ['audit.view'] },
];

export function canAccess(userPermissions: string[], required: string[]): boolean {
  return required.some((p) => userPermissions.includes(p));
}

export function visibleSections(userPermissions: string[]): AdminSection[] {
  return ADMIN_SECTIONS.filter((s) => canAccess(userPermissions, s.permissions));
}
