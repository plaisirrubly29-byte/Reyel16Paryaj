import { Link } from 'react-router-dom';
import { useMe } from '../../auth/api/auth';

const STEPS = [
  { title: 'Créez votre compte', text: 'Inscription en une minute, réservée aux personnes majeures.' },
  { title: 'Recevez des fonds fictifs', text: 'La version de démonstration vous crédite un solde virtuel : aucun argent réel.' },
  { title: 'Choisissez vos matchs', text: 'Parcourez les compétitions, comparez les cotes et composez votre coupon.' },
  { title: 'Suivez vos tickets', text: 'Résultats et gains sont calculés par la plateforme et visibles dans votre historique.' },
];

const FEATURES = [
  { title: 'Cotes à jour', text: 'Les cotes sont synchronisées avec notre fournisseur ; un marché trop ancien est suspendu automatiquement.' },
  { title: 'Validation côté serveur', text: 'La cote et la mise sont revérifiées au moment exact où votre pari est accepté.' },
  { title: 'Compte sécurisé', text: 'Mot de passe protégé, sessions révocables et double authentification pour l\'équipe.' },
];

/** Page d'accueil publique — affichable même si l'API est indisponible. */
export function HomePage() {
  const { data: user } = useMe();

  return (
    <div className="home">
      <section className="hero">
        <p className="eyebrow">Paris sportifs</p>
        <h1>Pariez sur vos sports préférés, en toute simplicité.</h1>
        <p className="hero-text">
          Suivez les matchs, comparez les cotes et placez vos paris depuis votre téléphone ou votre ordinateur.
        </p>
        <div className="hero-actions">
          {user ? (
            <>
              <Link to="/sports" className="btn btn-primary">Voir les matchs</Link>
              <Link to="/wallet" className="btn btn-secondary">Mon portefeuille</Link>
            </>
          ) : (
            <>
              <Link to="/register" className="btn btn-primary">Créer un compte</Link>
              <Link to="/login" className="btn btn-secondary">Se connecter</Link>
            </>
          )}
        </div>
        {user && <p className="muted">Bon retour, {user.name} !</p>}
      </section>

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

      <section className="home-section" aria-labelledby="matches-title">
        <h2 id="matches-title">Matchs et cotes</h2>
        <div className="card coming-soon">
          <p>
            Le catalogue des compétitions et des cotes est en cours d'intégration.
            Il sera alimenté en direct par notre fournisseur de données sportives.
          </p>
          <Link to="/sports" className="link-button">Voir la page Sports</Link>
        </div>
      </section>

      <section className="home-section" aria-labelledby="why-title">
        <h2 id="why-title">Une plateforme fiable</h2>
        <div className="cards">
          {FEATURES.map((f) => (
            <article key={f.title} className="card">
              <h3>{f.title}</h3>
              <p className="muted">{f.text}</p>
            </article>
          ))}
        </div>
      </section>

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
