import { Link, useSearchParams } from 'react-router-dom';
import { SportsSidebar } from '../../catalogue/components/SportsSidebar';
import { Icon, type IconName } from '../../../shared/components/Icon';
import { Spinner } from '../../../shared/components/Spinner';
import { useServerNow, useVirtualEvents, useVirtualResults, virtualGame, VIRTUAL_GAMES } from '../api/virtualGames';
import { DemoNotice } from '../components/DemoNotice';
import { eventState, eventTitle, formatStartTime, resultLabel } from '../format';
import type { VirtualEvent, VirtualGameKey } from '../types';

const GAME_ICONS: Record<VirtualGameKey, IconName> = { football: 'ball', courses: 'flag' };

/** Épreuves regroupées par heure de départ (une journée de football = plusieurs matchs en même temps). */
function byStart(events: VirtualEvent[]): { startsAt: string; events: VirtualEvent[] }[] {
  const groups = new Map<string, VirtualEvent[]>();
  for (const e of events) groups.set(e.starts_at, [...(groups.get(e.starts_at) ?? []), e]);
  return [...groups].map(([startsAt, list]) => ({ startsAt, events: list }));
}

/** Les cotes les plus basses d'un marché (favoris d'une course). */
function favourites(event: VirtualEvent, count = 3) {
  return [...(event.markets[0]?.selections ?? [])].sort((a, b) => Number(a.odds) - Number(b.odds)).slice(0, count);
}

/**
 * Accueil des jeux virtuels : choix du jeu (football, courses ; gardé dans l'URL ?jeu=), prochaines épreuves avec
 * leur compte à rebours, puis derniers résultats. Une épreuve ouvre sa page pour parier.
 */
export function VirtualGamesPage() {
  const [params, setParams] = useSearchParams();
  const game = virtualGame(params.get('jeu')) ?? VIRTUAL_GAMES[0];

  function select(key: VirtualGameKey) {
    const p = new URLSearchParams(params);
    p.set('jeu', key);
    setParams(p, { replace: true });
  }

  return (
    <div className="sportsbook">
      <SportsSidebar />

      <div className="sportsbook-main virtual">
        <section className="virtual-hero" aria-labelledby="virtual-title">
          <span className="virtual-hero-icon" aria-hidden="true"><Icon name="gamepad" size={30} /></span>
          <div>
            <h1 id="virtual-title">Jeux virtuels</h1>
            <p>Football et courses simulés, une épreuve toutes les quelques minutes, à toute heure.</p>
          </div>
        </section>

        <DemoNotice />

        <div className="virtual-games" role="group" aria-label="Jeu">
          {VIRTUAL_GAMES.map((g) => (
            <button
              key={g.key}
              type="button"
              className={`virtual-game virtual-game-${g.key}${g.key === game.key ? ' active' : ''}`}
              aria-pressed={g.key === game.key}
              onClick={() => select(g.key)}
            >
              <span className="virtual-game-icon" aria-hidden="true"><Icon name={GAME_ICONS[g.key]} size={24} /></span>
              <span className="virtual-game-text">
                <strong>{g.name}</strong>
                <span>{g.description}</span>
              </span>
            </button>
          ))}
        </div>

        <UpcomingEvents game={game.key} />
        <LatestResults game={game.key} />
      </div>
    </div>
  );
}

function UpcomingEvents({ game }: { game: VirtualGameKey }) {
  const query = useVirtualEvents(game);
  const serverNow = useServerNow(query.data?.meta, query.dataUpdatedAt);
  const groups = byStart(query.data?.data ?? []);

  return (
    <section className="virtual-section" aria-labelledby="virtual-upcoming-title">
      <h2 id="virtual-upcoming-title">Prochaines épreuves</h2>
      {query.isPending ? (
        <Spinner label="Chargement des épreuves…" />
      ) : groups.length === 0 ? (
        <p className="virtual-empty">Aucune épreuve programmée pour le moment.</p>
      ) : (
        <ol className="virtual-rounds">
          {groups.map(({ startsAt, events }) => {
            const state = eventState(events[0], serverNow);
            return (
              <li key={startsAt} className="virtual-round">
                <header className="virtual-round-head">
                  <span className="virtual-round-time"><Icon name="clock" size={14} /> {formatStartTime(startsAt)}</span>
                  <span className="virtual-round-name">{events[0].name}</span>
                  <span className={`virtual-state is-${state.tone}`}>{state.label}</span>
                </header>
                <ul className="virtual-events">
                  {events.map((e) => (
                    <li key={e.id}>
                      <Link to={`/jeux-virtuels/${e.id}`} className="virtual-event">
                        <span className="virtual-event-title">{e.game === 'football' ? eventTitle(e) : `${e.runners.length} partants · favoris`}</span>
                        <span className="virtual-event-odds" aria-label={e.game === 'football' ? 'Cotes 1, X, 2' : 'Favoris'}>
                          {(e.game === 'football' ? e.markets[0]?.selections ?? [] : favourites(e)).map((s) => (
                            <span key={s.id} className="virtual-chip">
                              <span className="virtual-chip-label">{s.label}</span>
                              <span className="odds-value">{s.odds}</span>
                            </span>
                          ))}
                        </span>
                        <Icon name="chevron" size={18} />
                      </Link>
                    </li>
                  ))}
                </ul>
              </li>
            );
          })}
        </ol>
      )}
    </section>
  );
}

function LatestResults({ game }: { game: VirtualGameKey }) {
  const query = useVirtualResults(game);
  const list = query.data?.data ?? [];

  return (
    <section className="virtual-section" aria-labelledby="virtual-results-title">
      <h2 id="virtual-results-title">Derniers résultats</h2>
      {query.isPending ? (
        <Spinner label="Chargement des résultats…" />
      ) : list.length === 0 ? (
        <p className="virtual-empty">Aucun résultat publié pour l&apos;instant.</p>
      ) : (
        <ul className="virtual-results">
          {list.map((e) => (
            <li key={e.id}>
              <Link to={`/jeux-virtuels/${e.id}`} className="virtual-result">
                <span className="virtual-result-time">{formatStartTime(e.starts_at)}</span>
                {e.game === 'football' && e.result?.kind === 'football' ? (
                  <span className="virtual-result-match">
                    <span>{e.home_team}</span>
                    <strong className="virtual-score">{resultLabel(e.result)}</strong>
                    <span>{e.away_team}</span>
                  </span>
                ) : (
                  <span className="virtual-result-match">
                    <span>{e.name}</span>
                    {e.result?.kind === 'courses' && (
                      <span className="virtual-podium" aria-label={`Arrivée : ${resultLabel(e.result)}`}>
                        {e.result.podium.map((r, i) => (
                          <span key={r.number} className={`virtual-podium-place place-${i + 1}`} title={r.name}>{r.number}</span>
                        ))}
                      </span>
                    )}
                  </span>
                )}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
