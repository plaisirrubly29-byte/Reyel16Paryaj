import { Link } from 'react-router-dom';
import { BRAND_LOGO_URL } from '../../../app/layouts/BrandLogo';
import { Icon } from '../../../shared/components/Icon';
import { sportIcon } from '../../../shared/components/sportIcon';
import { useSports } from '../api/catalogue';
import { BettingBoard } from '../components/BettingBoard';
import { TopMatches } from '../components/TopMatches';

/** Accueil : bandeau (devise, logo et pastilles des sports proposés), top matchs, puis matchs à venir ou en direct, avec le coupon à droite. */
export function HomePage() {
  // Seuls les sports qui ont des matchs sont annoncés.
  const sports = useSports().data ?? [];

  return (
    <>
      <h1 className="sr-only">REYEL 16 PARYAJ — paris sportifs</h1>

      <BettingBoard title="Tous les matchs" perPage={50} paginate>
        <section className="hero" aria-label="Bienvenue">
          <div className="hero-main">
            <div className="hero-text">
              <p className="hero-badge"><span className="hero-badge-dot" aria-hidden="true" />Cotes mises à jour en continu</p>
              <p className="hero-title">Parye sou espò <span>ou renmen</span></p>
              <ul className="hero-motto">
                <li><Icon name="check" size={14} />Pi fasil</li>
                <li><Icon name="bolt" size={14} />Pi rapid</li>
                <li><Icon name="shield" size={14} />Pi sekirite</li>
              </ul>
            </div>
            <img className="hero-logo" src={BRAND_LOGO_URL} alt="" width={151} height={190} />
          </div>
          {sports.length > 0 && (
            <nav className="hero-sports" aria-label="Sports disponibles">
              {sports.map((s) => (
                <Link key={s.slug} to={`/sports?sport=${encodeURIComponent(s.slug)}`} className="hero-sport">
                  <span className="hero-sport-icon" aria-hidden="true"><Icon name={sportIcon(s.slug)} size={16} /></span>
                  {s.name}
                  <span className="hero-sport-count">{s.events_count}<span className="sr-only"> matchs</span></span>
                </Link>
              ))}
            </nav>
          )}
        </section>
        <TopMatches />
      </BettingBoard>
    </>
  );
}
