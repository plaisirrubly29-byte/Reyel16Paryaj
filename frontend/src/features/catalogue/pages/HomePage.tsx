import { Link } from 'react-router-dom';
import { Icon } from '../../../shared/components/Icon';
import { sportIcon } from '../../../shared/components/sportIcon';
import { useLotteryDraws } from '../../lottery/api/lottery';
import { useSports } from '../api/catalogue';
import { BettingBoard } from '../components/BettingBoard';
import { PromoBanner } from '../components/PromoBanner';
import { TopMatches } from '../components/TopMatches';

/**
 * Accueil : bannière qui défile (paris, dépôt mobile, paris maison, borlette ouverte), rangée des sports proposés,
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
        <PromoBanner lotteryDraws={draws.length} />

        {/* Rangée réservée pendant le chargement, pour que la page ne saute pas à l'arrivée des sports (V2-E05). */}
        {sportsQuery.isPending && <div className="home-sports home-sports-placeholder" aria-hidden="true" />}
        {(sports.length > 0 || draws.length > 0) && (
          <nav className="home-sports" aria-label="Sports disponibles">
            {sports.map((s) => (
              <Link key={s.slug} to={`/sports?sport=${encodeURIComponent(s.slug)}`} className="home-sport">
                <Icon name={sportIcon(s.slug)} size={16} />
                {s.name}
                <span className="home-sport-count">{s.events_count}<span className="sr-only"> matchs</span></span>
              </Link>
            ))}
            {draws.length > 0 && (
              <Link to="/borlette" className="home-sport">
                <Icon name="lottery" size={16} />
                Borlette
                <span className="home-sport-count">{draws.length}<span className="sr-only"> tirages</span></span>
              </Link>
            )}
          </nav>
        )}
        <TopMatches />
      </BettingBoard>
    </>
  );
}
