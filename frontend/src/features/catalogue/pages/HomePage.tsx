import { BRAND_LOGO_URL } from '../../../app/layouts/BrandLogo';
import { BettingBoard } from '../components/BettingBoard';
import { TopMatches } from '../components/TopMatches';

/** Accueil : bandeau (logo et devise), top matchs, puis matchs à venir ou en direct, avec le coupon à droite. */
export function HomePage() {
  return (
    <>
      <h1 className="sr-only">REYEL 16 PARYAJ — paris sportifs</h1>

      <BettingBoard title="Tous les matchs" perPage={50} paginate>
        <section className="hero" aria-label="Bienvenue">
          <div className="hero-text">
            <p className="hero-kicker">Football · Basketball · Baseball</p>
            <p className="hero-title">Parye sou espò ou renmen</p>
            <p className="hero-motto"><span>Pi fasil</span><span>Pi rapid</span><span>Pi sekirite</span></p>
            <p className="hero-sub">Cotes mises à jour toute la journée.</p>
          </div>
          <img className="hero-logo" src={BRAND_LOGO_URL} alt="" width={151} height={190} />
        </section>
        <TopMatches />
      </BettingBoard>
    </>
  );
}
