import { useRouteError } from 'react-router-dom';
import { Icon } from '../shared/components/Icon';
import { isStaleAssetError } from './staleAssets';

/** Erreur d'un écran : message clair en français, au lieu de l'écran technique de React Router. */
export function RouteError() {
  const error = useRouteError();
  const stale = isStaleAssetError(error);
  const home = import.meta.env.BASE_URL;
  return (
    <section className="not-found" role="alert">
      <span className="empty-icon" aria-hidden="true"><Icon name={stale ? 'bolt' : 'help'} size={26} /></span>
      <h1>{stale ? 'Une nouvelle version du site est disponible' : 'Cette page n’a pas pu s’afficher'}</h1>
      <p>{stale ? 'Rechargez la page pour continuer.' : 'Vérifiez votre connexion internet, puis réessayez.'}</p>
      <button type="button" className="btn btn-primary" onClick={() => window.location.reload()}>Recharger la page</button>
      <a href={home} className="btn btn-ghost">Retour aux matchs</a>
    </section>
  );
}
