import { useMe } from '../../auth/api/auth';
import { BettingBoard } from '../components/BettingBoard';
import { TopMatches } from '../components/TopMatches';

const STEPS = [
  { title: 'Créez votre compte', text: 'Une minute suffit. Réservé aux personnes majeures.' },
  { title: 'Recevez des fonds fictifs', text: 'La démonstration vous crédite un solde virtuel.' },
  { title: 'Choisissez vos cotes', text: 'Touchez 1, X ou 2 pour remplir votre coupon.' },
  { title: 'Suivez vos tickets', text: 'Résultats et gains calculés par la plateforme.' },
];

/** Accueil : top matchs, tous les matchs (paginés) et coupon, puis les informations. */
export function HomePage() {
  const { data: user } = useMe();

  return (
    <div className="stack">
      <h1 className="sr-only">REYEL 16 PAYAJ — paris sportifs</h1>

      <BettingBoard title="Tous les matchs" perPage={50} paginate>
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
