/**
 * Version des textes d'information (conditions, confidentialité, règlement, jeu responsable).
 * Envoyée à l'inscription (terms_version) : le serveur enregistre l'acceptation avec cette version (V2-E01).
 * Tout changement de fond d'un texte = nouvelle version, ici et côté serveur.
 */
export const LEGAL_VERSION = '2026-11-02';

/** Date d'entrée en vigueur affichée en tête de chaque page. */
export const LEGAL_UPDATED_AT = '2 novembre 2026';

export const LEGAL_PAGES = [
  { to: '/conditions', title: "Conditions d'utilisation" },
  { to: '/reglement', title: 'Règlement des paris' },
  { to: '/confidentialite', title: 'Confidentialité et cookies' },
  { to: '/jeu-responsable', title: 'Jeu responsable' },
] as const;
