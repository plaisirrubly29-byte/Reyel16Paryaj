import type { IconName } from '../../shared/components/Icon';

/** program : rubrique ouverte par le serveur (fidélité, parrainage) ; « Bientôt » tant qu'il répond 404. */
export type AccountSection = { to: string; label: string; icon: IconName; soon?: boolean; program?: 'loyalty' | 'referral' };

/** Rubriques de l'espace joueur. « soon » : rubrique visible, fonctionnalité pas encore ouverte. */
export const ACCOUNT_SECTIONS: AccountSection[] = [
  { to: '/wallet', label: 'Solde', icon: 'wallet' },
  { to: '/bets', label: 'Mes paris', icon: 'tickets' },
  { to: '/coupons', label: 'Mes coupons', icon: 'coupon' },
  { to: '/transactions', label: 'Mes transactions', icon: 'list' },
  { to: '/loyalty', label: 'Points de fidélité', icon: 'star', program: 'loyalty' },
  { to: '/referral', label: 'Parrainage', icon: 'gift', program: 'referral' },
  { to: '/account', label: 'Mes informations', icon: 'user' },
  { to: '/verification', label: "Vérification d'identité", icon: 'check' },
  { to: '/devices', label: 'Appareils connectés', icon: 'monitor' },
  { to: '/responsible-gaming', label: 'Jeu responsable', icon: 'shield' },
  { to: '/messages', label: 'Messagerie', icon: 'chat', soon: true },
];
