import { Link } from 'react-router-dom';
import { Icon } from '../../../shared/components/Icon';

/** Adresse inconnue : message clair et retour vers les matchs, dans la mise en page du site. */
export function NotFoundPage() {
  return (
    <section className="not-found">
      <span className="empty-icon" aria-hidden="true"><Icon name="search" size={26} /></span>
      <h1>Cette page n'existe pas</h1>
      <p>Le lien est peut-être ancien ou mal recopié.</p>
      <Link to="/" className="btn btn-primary">Retour aux matchs</Link>
    </section>
  );
}
