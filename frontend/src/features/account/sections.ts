import type { IconName } from '../../shared/components/Icon';

export type AccountSection = { to: string; label: string; icon: IconName; soon?: boolean };

/** Rubriques de l'espace joueur. « soon » : rubrique visible, fonctionnalité pas encore ouverte. */
export const ACCOUNT_SECTIONS: AccountSection[] = [
  { to: '/wallet', label: 'Solde', icon: 'wallet' },
  { to: '/bets', label: 'Mes paris', icon: 'tickets' },
  { to: '/coupons', label: 'Mes coupons', icon: 'coupon' },
  { to: '/transactions', label: 'Mes transactions', icon: 'list' },
  { to: '/loyalty', label: 'Points de fidélité', icon: 'star', soon: true },
  { to: '/referral', label: 'Parrainage', icon: 'gift', soon: true },
  { to: '/account', label: 'Mes informations', icon: 'user' },
  { to: '/messages', label: 'Messagerie', icon: 'chat', soon: true },
];
