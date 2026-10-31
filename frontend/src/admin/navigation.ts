import type { IconName } from '../shared/components/Icon';

/**
 * Sections de l'administration et permission requise (backend/config/permissions.php).
 * Une section s'affiche si l'utilisateur possède AU MOINS une des permissions listées.
 * Confort d'affichage : l'API refuse elle-même tout accès non autorisé (403).
 */
export type AdminSection = {
  to: string;
  label: string;
  icon: IconName;
  /** Groupe de la barre latérale. */
  group: 'Pilotage' | 'Opérations' | 'Contrôle';
  /** Phrase courte des cartes d'accès rapide. */
  description: string;
  permissions: string[];
  end?: boolean;
};

export const ADMIN_SECTIONS: AdminSection[] = [
  { to: '/admin', label: "Vue d'ensemble", icon: 'dashboard', group: 'Pilotage', description: 'Indicateurs clés et activité récente.', permissions: ['dashboard.view'], end: true },
  { to: '/admin/events', label: 'Événements & marchés', icon: 'calendar', group: 'Opérations', description: 'Matchs, cotes et suspension des marchés.', permissions: ['events.view'] },
  { to: '/admin/settlements', label: 'Résultats & règlement', icon: 'trophy', group: 'Opérations', description: 'Saisie des résultats et règlement des tickets.', permissions: ['results.enter', 'settlement.run'] },
  { to: '/admin/tickets', label: 'Tickets', icon: 'coupon', group: 'Opérations', description: 'Recherche et détail des paris placés.', permissions: ['tickets.view'] },
  { to: '/admin/users', label: 'Utilisateurs', icon: 'users', group: 'Opérations', description: 'Comptes joueurs, statuts et rôles.', permissions: ['users.view'] },
  { to: '/admin/risk', label: 'Risques & exposition', icon: 'target', group: 'Contrôle', description: 'Exposition de la maison sur les tickets en cours.', permissions: ['tickets.view'] },
  { to: '/admin/finance', label: 'Finance', icon: 'wallet', group: 'Contrôle', description: 'Grand livre, rapprochement et corrections.', permissions: ['ledger.view'] },
  { to: '/admin/audit', label: 'Audit & rapports', icon: 'shield', group: 'Contrôle', description: 'Journal des actions sensibles.', permissions: ['audit.view'] },
  { to: '/admin/system', label: 'Supervision technique', icon: 'server', group: 'Contrôle', description: 'Fournisseur sportif, planificateur et tâches en échec.', permissions: ['settings.view'] },
  { to: '/admin/settings', label: 'Paramètres', icon: 'settings', group: 'Contrôle', description: 'Règles de jeu et configuration.', permissions: ['settings.view'] },
];

export function canAccess(userPermissions: string[], required: string[]): boolean {
  return required.some((p) => userPermissions.includes(p));
}

export function visibleSections(userPermissions: string[]): AdminSection[] {
  return ADMIN_SECTIONS.filter((s) => canAccess(userPermissions, s.permissions));
}

/** Section correspondant à une URL (la plus spécifique), pour le titre de la barre supérieure. */
export function sectionFor(pathname: string): AdminSection | undefined {
  return [...ADMIN_SECTIONS]
    .sort((a, b) => b.to.length - a.to.length)
    .find((s) => (s.end ? pathname === s.to : pathname === s.to || pathname.startsWith(`${s.to}/`)));
}
