import { Link } from 'react-router-dom';
import { useMe } from '../../auth/api/auth';
import { Icon, type IconName } from '../../../shared/components/Icon';
import { BettingBoard } from '../components/BettingBoard';
import { TopMatches } from '../components/TopMatches';

const HIGHLIGHTS: { icon: IconName; text: string }[] = [
  { icon: 'bolt', text: 'Cotes actualisées chaque jour' },
  { icon: 'shield', text: 'Cote revérifiée au moment du pari' },
  { icon: 'wallet', text: 'Démonstration : fonds fictifs' },
];

const STEPS = [
  { title: 'Créez votre compte', text: 'Une minute suffit. Réservé aux personnes majeures.' },
  { title: 'Recevez des fonds fictifs', text: 'La démonstration vous crédite un solde virtuel.' },
  { title: 'Choisissez vos cotes', text: 'Touchez 1, X ou 2 pour remplir votre coupon.' },
  { title: 'Suivez vos tickets', text: 'Résultats et gains calculés par la plateforme.' },
];

/** Accueil : bandeau, top matchs, tous les matchs (paginés) et coupon, puis les informations. */
export function HomePage() {
  const { data: user } = useMe();

  return (
    <div className="stack">
      <BettingBoard title="Tous les matchs" perPage={50} paginate>
        <section className="hero-banner" aria-labelledby="hero-title">
          <div className="hero-text">
            <span className="hero-eyebrow">Football · Basketball · Baseball</span>
            <h1 id="hero-title">{user ? `Bonjour ${user.name.split(' ')[0]}` : 'Pariez sur vos sports préférés'}</h1>
            <p>Premier League, Ligue des champions, LaLiga, NBA… Les cotes des grands championnats, mises à jour chaque jour.</p>
            <ul className="hero-highlights">
              {HIGHLIGHTS.map((h) => (
                <li key={h.text}><Icon name={h.icon} size={16} />{h.text}</li>
              ))}
            </ul>
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
        <span className="responsible-badge" aria-hidden="true">18+</span>
        <h2 id="responsible-title">Jouez de manière responsable</h2>
        <p className="muted small">
          Le jeu doit rester un divertissement. Interdit aux moins de 18 ans. Fixez-vous des limites et faites une pause si besoin.
        </p>
      </section>
    </div>
  );
}
