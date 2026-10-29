import { Link } from 'react-router-dom';
import { Icon, type IconName } from '../../shared/components/Icon';

type Props = {
  icon: IconName;
  title: string;
  /** Ce que la rubrique permettra de faire. */
  promise: string;
  /** Exemples concrets de ce qui sera proposé. */
  features: string[];
};

/**
 * Rubrique annoncée mais pas encore ouverte : on dit honnêtement ce qu'elle fera,
 * sans afficher de points, de filleuls ou de messages inventés.
 */
export function ComingSoonPage({ icon, title, promise, features }: Props) {
  return (
    <section className="stack">
      <h1>{title}</h1>
      <div className="coming-soon">
        <span className="coming-soon-icon" aria-hidden="true"><Icon name={icon} size={30} /></span>
        <span className="soon-tag">Bientôt disponible</span>
        <p className="coming-soon-promise">{promise}</p>
        <ul className="coming-soon-list">
          {features.map((f) => <li key={f}><Icon name="check" size={16} />{f}</li>)}
        </ul>
        <p className="muted small">Cette rubrique n’est pas encore active : rien ne vous est demandé pour l’instant.</p>
        <Link to="/sports" className="btn btn-secondary">Retour aux matchs</Link>
      </div>
    </section>
  );
}

export const LoyaltyPage = () => (
  <ComingSoonPage
    icon="star"
    title="Points de fidélité"
    promise="Gagnez des points à chaque pari et échangez-les contre des avantages."
    features={['Points crédités sur vos paris réglés', 'Paliers de fidélité', 'Récompenses à échanger']}
  />
);

export const ReferralPage = () => (
  <ComingSoonPage
    icon="gift"
    title="Parrainage"
    promise="Invitez vos amis à rejoindre REYEL 16 PARYAJ avec votre lien personnel."
    features={['Lien de parrainage personnel', 'Suivi de vos filleuls', 'Récompense quand un filleul parie']}
  />
);

export const MessagesPage = () => (
  <ComingSoonPage
    icon="chat"
    title="Messagerie"
    promise="Recevez les messages de l’équipe REYEL 16 PARYAJ et échangez avec le support."
    features={['Annonces et informations importantes', 'Réponses du support client', 'Notifications sur vos paris']}
  />
);
