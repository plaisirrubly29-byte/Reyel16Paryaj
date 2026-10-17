import { BettingBoard } from '../components/BettingBoard';
import { TopMatches } from '../components/TopMatches';

/** Accueil : top matchs, tous les matchs (paginés) et coupon, puis les informations. */
export function HomePage() {
  return (
    <div className="stack">
      <h1 className="sr-only">REYEL 16 PAYAJ — paris sportifs</h1>

      <BettingBoard title="Tous les matchs" perPage={50} paginate>
        <TopMatches />
      </BettingBoard>

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
