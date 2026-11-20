/**
 * Après un déploiement, les fichiers de l'ancienne version (noms à empreinte) sont effacés du serveur. Un onglet resté
 * ouvert sur l'ancienne page ne peut plus charger ses écrans à la demande : on recharge la page une fois, pour prendre
 * la nouvelle version, au lieu d'afficher une erreur. Garde-fou : pas plus d'un rechargement automatique par minute
 * (hors ligne, ou si le serveur est vraiment en panne, l'erreur s'affiche normalement).
 */
const KEY = 'reyel16:reload-after-deploy';
const MIN_DELAY_MS = 60_000;

/** Échec de téléchargement d'un module (Chrome, Firefox, Safari) ou page HTML reçue à la place du script. */
export function isStaleAssetError(error: unknown): boolean {
  const message = error instanceof Error ? error.message : String(error ?? '');
  return /dynamically imported module|Importing a module script failed|module script|Unable to preload CSS|text\/html/i.test(message);
}

/** Recharge la page si aucun rechargement automatique n'a eu lieu dans la dernière minute ; true si rechargée. */
export function reloadOnce(now = Date.now()): boolean {
  try {
    const last = Number(sessionStorage.getItem(KEY) ?? 0);
    if (now - last < MIN_DELAY_MS) return false;
    sessionStorage.setItem(KEY, String(now));
  } catch {
    return false; // stockage bloqué : pas de rechargement sans garde-fou contre une boucle
  }
  window.location.reload();
  return true;
}

/** Charge un module ; s'il appartient à une version effacée, recharge la page (et reste en attente pendant ce temps). */
export async function importOrReload<M>(load: () => Promise<M>): Promise<M> {
  try {
    return await load();
  } catch (error) {
    if (isStaleAssetError(error) && reloadOnce()) return new Promise<M>(() => {});
    throw error;
  }
}

/** Styles ou dépendances préchargés par Vite introuvables : même traitement. */
export function reloadOnStalePreload(): void {
  window.addEventListener('vite:preloadError', (event) => {
    if (reloadOnce()) event.preventDefault();
  });
}
