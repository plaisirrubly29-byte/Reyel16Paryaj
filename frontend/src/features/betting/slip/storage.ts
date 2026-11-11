/**
 * Coupon enregistré dans le navigateur, avec le compte auquel il appartient (revue P8-02, point F11).
 *
 * Deux clés : la liste des sélections (format inchangé) et l'id du joueur qui l'a composée.
 * Sans propriétaire, c'est le coupon d'un visiteur : le premier compte qui se connecte le reprend.
 */
const SELECTIONS_KEY = 'r16p.betslip';
const OWNER_KEY = 'r16p.betslip.owner';

/** Émis quand le coupon est effacé du navigateur (déconnexion) : le coupon affiché se vide aussi. */
export const SLIP_FORGOTTEN_EVENT = 'r16p:betslip-forgotten';

/** `selections` est brut : à valider par l'appelant (le stockage est modifiable par l'utilisateur). */
export function readStoredSlip(): { owner: string | null; selections: unknown } {
  try {
    return {
      owner: localStorage.getItem(OWNER_KEY),
      selections: JSON.parse(localStorage.getItem(SELECTIONS_KEY) ?? '[]'),
    };
  } catch {
    return { owner: null, selections: [] };
  }
}

/** Un coupon vide ne laisse rien dans le navigateur. */
export function writeStoredSlip(owner: string | null, selections: unknown[]): void {
  try {
    if (selections.length === 0) {
      localStorage.removeItem(SELECTIONS_KEY);
      localStorage.removeItem(OWNER_KEY);
      return;
    }
    localStorage.setItem(SELECTIONS_KEY, JSON.stringify(selections));
    if (owner === null) localStorage.removeItem(OWNER_KEY);
    else localStorage.setItem(OWNER_KEY, owner);
  } catch {
    // Stockage indisponible (navigation privée) : le coupon reste en mémoire.
  }
}

/**
 * Déconnexion : le coupon quitte l'appareil, même si aucune page du site public n'est affichée
 * (déconnexion depuis l'administration).
 */
export function forgetStoredSlip(): void {
  writeStoredSlip(null, []);
  window.dispatchEvent(new Event(SLIP_FORGOTTEN_EVENT));
}
