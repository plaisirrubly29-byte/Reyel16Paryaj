import type { IconName } from './Icon';

/** Icône d'un sport à partir de son identifiant (« soccer », « basketball »…) ; trophée par défaut. */
export function sportIcon(slug: string): IconName {
  switch (slug) {
    case 'soccer': return 'ball';
    case 'basketball': return 'basketball';
    case 'baseball': return 'baseball';
    case 'tennis': return 'tennis';
    case 'icehockey': return 'hockey';
    default: return 'trophy';
  }
}
