import { Link } from 'react-router-dom';
import { useMe } from '../../auth/api/auth';
import { BettingBoard } from '../components/BettingBoard';

const STEPS = [
  { title: 'Créez votre compte', text: 'Inscription en une minute, réservée aux personnes majeures.' },
  { title: 'Recevez des fonds fictifs', text: 'La version de démonstration vous crédite un solde virtuel : aucun argent réel.' },
  { title: 'Choisissez vos matchs', text: 'Cliquez sur les cotes 1, X ou 2 pour composer votre coupon.' },
  { title: 'Suivez vos tickets', text: 'Résultats et gains sont calculés par la plateforme et visibles dans votre historique.' },
];

/** Accueil : les matchs et les cotes d'abord (comme les sites de paris), puis les informations. */
export function HomePage() {
  const { data: user } = useMe();

  return (
    <div className="home">
      <section className="hero hero-compact">
        <div>
          <p className="eyebrow">Paris sportifs</p>
          <h1>Les matchs du moment, les meilleures cotes.</h1>
        </div>
        <div className="hero-actions">
          {user ? (
            <Link to="/wallet" className="btn btn-secondary">Mon portefeuille</Link>
          ) : (
            <>
              <Link to="/register" className="btn btn-primary">Créer un compte</Link>
              <Link to="/login" className="btn btn-secondary">Se connecter</Link>
            </>
          )}
        </div>
      </section>

      <section aria-labelledby="matches-title" className="home-section">
        <h2 id="matches-title">Matchs à venir</h2>
        <BettingBoard perPage={30} />
      </section>

      {!user && (
        <section className="home-section" aria-labelledby="how-title">
          <h2 id="how-title">Comment ça marche</h2>
          <ol className="steps-grid">
            {STEPS.map((s, i) => (
              <li key={s.title} className="card">
                <span className="step-number" aria-hidden="true">{i + 1}</span>
                <h3>{s.title}</h3>
                <p className="muted">{s.text}</p>
              </li>
            ))}
          </ol>
        </section>
      )}

      <section className="responsible" aria-labelledby="responsible-title">
        <h2 id="responsible-title">Jouez de manière responsable</h2>
        <p>
          Le jeu doit rester un divertissement. Interdit aux moins de 18 ans. Fixez-vous des limites et
          faites une pause si vous en ressentez le besoin.
        </p>
      </section>
    </div>
  );
}
