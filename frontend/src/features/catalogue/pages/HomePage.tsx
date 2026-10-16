import { Link } from 'react-router-dom';
import { useMe } from '../../auth/api/auth';
import { BettingBoard } from '../components/BettingBoard';
import { TopMatches } from '../components/TopMatches';

const STEPS = [
  { title: 'Créez votre compte', text: 'Une minute suffit. Réservé aux personnes majeures.' },
  { title: 'Recevez des fonds fictifs', text: 'La démonstration vous crédite un solde virtuel.' },
  { title: 'Choisissez vos cotes', text: 'Touchez 1, X ou 2 pour remplir votre coupon.' },
  { title: 'Suivez vos tickets', text: 'Résultats et gains calculés par la plateforme.' },
];

/** Accueil : bandeau, top matchs, matchs à venir et coupon, puis les informations. */
export function HomePage() {
  const { data: user } = useMe();

  return (
    <div className="stack">
      <BettingBoard perPage={30}>
        <section className="hero-banner" aria-labelledby="hero-title">
          <div>
            <h1 id="hero-title">{user ? `Bonjour ${user.name.split(' ')[0]}` : 'Pariez sur vos sports préférés'}</h1>
            <p>Les meilleures cotes du football et des grands championnats, mises à jour plusieurs fois par jour.</p>
          </div>
          <div className="hero-actions">
            {user ? (
              <Link to="/wallet" className="btn btn-primary">Mon portefeuille</Link>
            ) : (
              <>
                <Link to="/register" className="btn btn-primary">Créer un compte</Link>
                <Link to="/login" className="btn btn-secondary">Se connecter</Link>
              </>
            )}
          </div>
        </section>

        <TopMatches />
      </BettingBoard>

      {!user && (
        <section className="home-section" aria-labelledby="how-title">
          <h2 id="how-title">Comment ça marche</h2>
          <ol className="steps-grid">
            {STEPS.map((s, i) => (
              <li key={s.title} className="card">
                <span className="step-number" aria-hidden="true">{i + 1}</span>
                <h3>{s.title}</h3>
                <p className="muted small">{s.text}</p>
              </li>
            ))}
          </ol>
        </section>
      )}

      <section className="responsible" aria-labelledby="responsible-title">
        <h2 id="responsible-title">Jouez de manière responsable</h2>
        <p className="muted small">
          Le jeu doit rester un divertissement. Interdit aux moins de 18 ans. Fixez-vous des limites et faites une pause si besoin.
        </p>
      </section>
    </div>
  );
}
