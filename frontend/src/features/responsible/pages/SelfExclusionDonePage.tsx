import { useEffect } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Icon } from '../../../shared/components/Icon';

/** Après l'auto-exclusion : le joueur est déconnecté, on lui dit jusqu'à quand et comment rouvrir son compte. */
export function SelfExclusionDonePage() {
  const until = (useLocation().state as { until?: string } | null)?.until;
  const date = until
    ? new Date(until).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
    : null;

  // Le formulaire était en bas de l'espace joueur : la confirmation commence en haut.
  useEffect(() => window.scrollTo({ top: 0 }), []);

  return (
    <section className="not-found">
      <span className="empty-icon" aria-hidden="true"><Icon name="lock" size={26} /></span>
      <h1>Votre auto-exclusion est enregistrée</h1>
      <p>
        {date ? <>Votre compte est fermé au jeu jusqu'au <strong>{date}</strong>.</> : 'Votre compte est fermé au jeu.'}{' '}
        Vous avez été déconnecté.
      </p>
      <p>À cette date, il restera fermé : pour le rouvrir, il faudra en faire la demande au support.</p>
      <Link to="/jeu-responsable" className="btn btn-secondary">Conseils et aide</Link>
    </section>
  );
}
