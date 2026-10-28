import { useRef } from 'react';
import { Link } from 'react-router-dom';
import { Icon } from '../../../shared/components/Icon';
import { sportIcon } from '../../../shared/components/sportIcon';
import { useEvents } from '../api/catalogue';
import { formatKickoff, liveLabel, startsIn } from '../format';
import { OddsButtons } from './OddsButtons';
import { TeamCrest } from './TeamCrest';

/**
 * Affiches mises en avant sur l'accueil : cartes sombres en défilement horizontal (flèches sur ordinateur,
 * glisser sur mobile). Chaque carte : compétition, coup d'envoi ou compte à rebours, face-à-face avec écussons,
 * cotes 1 / X / 2 cliquables et lien vers tous les paris du match.
 */
export function TopMatches({ count = 8 }: { count?: number }) {
  const events = useEvents({ perPage: count });
  const listRef = useRef<HTMLUListElement>(null);
  const list = events.data?.data ?? [];

  if (list.length === 0) return null;

  function scroll(direction: 1 | -1) {
    const el = listRef.current;
    if (!el) return;
    const card = el.querySelector<HTMLElement>('.match-card');
    el.scrollBy({ left: direction * ((card?.offsetWidth ?? 320) + 16), behavior: 'smooth' });
  }

  return (
    <section className="top-section" aria-labelledby="top-title">
      <div className="top-head">
        <span className="top-head-icon" aria-hidden="true"><Icon name="flame" size={24} /></span>
        <div className="top-head-text">
          <h2 id="top-title">Top matchs</h2>
          <p>Les grandes affiches du moment</p>
        </div>
        <div className="top-head-actions">
          <button type="button" className="top-nav" aria-label="Matchs précédents" onClick={() => scroll(-1)}>
            <span className="top-nav-prev"><Icon name="chevron" size={20} /></span>
          </button>
          <button type="button" className="top-nav" aria-label="Matchs suivants" onClick={() => scroll(1)}>
            <Icon name="chevron" size={20} />
          </button>
          <Link to="/sports" className="top-all">Tout voir <Icon name="arrowRight" size={18} /></Link>
        </div>
      </div>

      <ul className="top-matches" aria-label="Matchs à la une" ref={listRef}>
        {list.map((e) => {
          const { day, time } = formatKickoff(e.starts_at);
          const live = liveLabel(e.status);
          const soon = live ? null : startsIn(e.starts_at);
          return (
            <li key={e.id} className={`match-card sport-${e.sport.slug}`}>
              <div className="match-card-head">
                <span className="competition">
                  <span className="competition-icon" aria-hidden="true"><Icon name={sportIcon(e.sport.slug)} size={16} /></span>
                  <span className="competition-text">
                    <strong>{e.competition.name}</strong>
                    {e.competition.country && <span>{e.competition.country}</span>}
                  </span>
                </span>
                {live
                  ? <span className="kickoff kickoff-live"><span className="live-dot" aria-hidden="true" />{live}</span>
                  : soon && <span className="kickoff kickoff-soon"><Icon name="clock" size={14} />{soon}</span>}
              </div>

              <Link to={`/events/${e.id}`} className="match-card-teams" title="Voir tous les paris de ce match">
                <span className="card-team">
                  <TeamCrest name={e.home_team} />
                  <span className="team-name">{e.home_team}</span>
                </span>
                <span className="card-versus">
                  {live && e.score
                    ? <strong className="card-score" aria-label={`Score : ${e.score.home} à ${e.score.away}`}>{e.score.home} - {e.score.away}</strong>
                    : <span className="card-vs" aria-hidden="true">VS</span>}
                  <span className="sr-only"> contre </span>
                  {!live && <strong className="card-time">{time}</strong>}
                  {!live && <small className="card-day">{day}</small>}
                </span>
                <span className="card-team">
                  <TeamCrest name={e.away_team} />
                  <span className="team-name">{e.away_team}</span>
                </span>
              </Link>

              <OddsButtons event={e} variant="card" />

              <Link to={`/events/${e.id}`} className="match-card-more" aria-label={`Tous les paris : ${e.home_team} – ${e.away_team}`}>
                Tous les paris <Icon name="arrowRight" size={16} />
              </Link>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
