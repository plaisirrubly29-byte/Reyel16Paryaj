import { Link } from 'react-router-dom';
import { BRAND_LOGO_SMALL_URL } from '../../../app/layouts/BrandLogo';
import { Icon } from '../../../shared/components/Icon';
import { sportIcon } from '../../../shared/components/sportIcon';
import { useLotteryDraws } from '../../lottery/api/lottery';
import { useSports } from '../api/catalogue';
import { BettingBoard } from '../components/BettingBoard';
import { TopMatches } from '../components/TopMatches';

/**
 * Accueil : bandeau (devise, logo et pastilles des sports proposés, puis la borlette si elle est ouverte),
 * top matchs, puis matchs à venir ou en direct, avec le coupon à droite.
 */
export function HomePage() {
  // Seuls les sports qui ont des matchs sont annoncés ; la borlette, seulement si l'API publie des tirages.
  const sportsQuery = useSports();
  const sports = sportsQuery.data ?? [];
  const draws = useLotteryDraws().data ?? [];

  return (
    <>
      <h1 className="sr-only">REYEL 16 PARYAJ — paris sportifs</h1>

      {/* 20 matchs sur l'accueil (V2-E05) : moins d'éléments à construire sur un téléphone d'entrée de gamme ; les autres
          sont sur les pages suivantes. */}
      <BettingBoard title="Tous les matchs" perPage={20} paginate>
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
            {/* Plus grand élément visible de l'accueil : chargé en priorité (V2-E05). */}
            <img className="hero-logo" src={BRAND_LOGO_SMALL_URL} alt="" width={190} height={190} fetchPriority="high" />
          </div>
          {/* Rangée réservée pendant le chargement, pour que la page ne saute pas à l'arrivée des sports (V2-E05). */}
          {sportsQuery.isPending && <div className="hero-sports hero-sports-placeholder" aria-hidden="true" />}
          {(sports.length > 0 || draws.length > 0) && (
            <nav className="hero-sports" aria-label="Sports disponibles">
              {sports.map((s) => (
                <Link key={s.slug} to={`/sports?sport=${encodeURIComponent(s.slug)}`} className="hero-sport">
                  <span className="hero-sport-icon" aria-hidden="true"><Icon name={sportIcon(s.slug)} size={16} /></span>
                  {s.name}
                  <span className="hero-sport-count">{s.events_count}<span className="sr-only"> matchs</span></span>
                </Link>
              ))}
              {draws.length > 0 && (
                <Link to="/borlette" className="hero-sport hero-sport-lottery">
                  <span className="hero-sport-icon" aria-hidden="true"><Icon name="lottery" size={16} /></span>
                  Borlette
                  <span className="hero-sport-count">{draws.length}<span className="sr-only"> tirages</span></span>
                </Link>
              )}
            </nav>
          )}
        </section>
        <TopMatches />
      </BettingBoard>
    </>
  );
}
