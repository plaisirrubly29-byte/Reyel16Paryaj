import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { SportsSidebar } from '../../catalogue/components/SportsSidebar';
import { Icon } from '../../../shared/components/Icon';
import { Spinner } from '../../../shared/components/Spinner';
import { bettingOpen, useServerNow, useVirtualEvent, virtualGame } from '../api/virtualGames';
import { DemoNotice } from '../components/DemoNotice';
import { VirtualCoupon, type VirtualPick } from '../components/VirtualCoupon';
import { formatClock, formatStartTime } from '../format';
import type { VirtualEvent } from '../types';

/**
 * Page d'une épreuve virtuelle : en-tête avec compte à rebours (heure du serveur), zone du flux du prestataire
 * (pour l'instant mode sans vidéo : tableau de score ou partants), marchés et coupon. Le résultat s'affiche à la fin.
 */
export function VirtualEventPage() {
  const { id = '' } = useParams();
  const query = useVirtualEvent(id);
  const serverNow = useServerNow(query.data?.meta, query.dataUpdatedAt);
  const event = query.data?.data;
  const game = virtualGame(event?.game);

  return (
    <div className="sportsbook">
      <SportsSidebar />

      <div className="sportsbook-main virtual">
        <Link to={`/jeux-virtuels${event ? `?jeu=${event.game}` : ''}`} className="back-link">
          <Icon name="chevron" size={16} />{game ? game.name : 'Jeux virtuels'}
        </Link>

        {query.isPending ? (
          <div className="table-state"><Spinner label="Chargement de l'épreuve…" /></div>
        ) : !event ? (
          <div className="empty-state">
            <span className="empty-icon" aria-hidden="true"><Icon name="clock" size={26} /></span>
            <strong>Cette épreuve n&apos;existe pas</strong>
            <span>Le lien est peut-être ancien. Choisissez une des prochaines épreuves.</span>
            <Link to="/jeux-virtuels" className="btn btn-secondary btn-sm">Voir les jeux virtuels</Link>
          </div>
        ) : (
          <>
            <DemoNotice />
            <EventContent key={event.id} event={event} serverNow={serverNow} />
          </>
        )}
      </div>
    </div>
  );
}

function Countdown({ event, serverNow }: { event: VirtualEvent; serverNow: number }) {
  const closes = Date.parse(event.betting_closes_at);
  const starts = Date.parse(event.starts_at);
  const ends = Date.parse(event.ends_at);

  if (event.status === 'finished') return <p className="virtual-countdown is-finished">Terminé</p>;
  if (event.status === 'running' || serverNow >= starts) {
    return <p className="virtual-countdown is-running"><span className="nav-live-dot" aria-hidden="true" /> En cours · fin dans {formatClock(ends - serverNow)}</p>;
  }
  if (serverNow >= closes) return <p className="virtual-countdown is-closed">Mises fermées · départ dans {formatClock(starts - serverNow)}</p>;
  return (
    <p className="virtual-countdown is-open">
      Fin des mises dans <strong className="virtual-clock" role="timer">{formatClock(closes - serverNow)}</strong>
    </p>
  );
}

function EventContent({ event, serverNow }: { event: VirtualEvent; serverNow: number }) {
  const [pick, setPick] = useState<VirtualPick | null>(null);
  const open = bettingOpen(event, serverNow);

  return (
    <>
      <section className="match-header virtual-header" aria-labelledby="virtual-event-title">
        <p className="match-header-competition">
          {event.game === 'football' ? event.name : virtualGame(event.game)?.name} · départ {formatStartTime(event.starts_at)}
        </p>
        {event.game === 'football' ? (
          <h1 id="virtual-event-title" className="match-header-teams">
            <span className="match-header-team">{event.home_team}</span>
            {event.result?.kind === 'football'
              ? <span className="match-header-score" aria-label={`Score : ${event.result.home} à ${event.result.away}`}>{event.result.home} - {event.result.away}</span>
              : <span className="match-header-vs" aria-hidden="true">-</span>}
            <span className="sr-only"> contre </span>
            <span className="match-header-team">{event.away_team}</span>
          </h1>
        ) : (
          <h1 id="virtual-event-title" className="match-header-teams">{event.name}</h1>
        )}
        <Countdown event={event} serverNow={serverNow} />
      </section>

      <section className="virtual-stage" aria-label="Épreuve">
        <p className="virtual-stage-note"><Icon name="eye" size={16} /> Vidéo du prestataire à venir · affichage sans vidéo</p>
        {event.game === 'courses' && (
          <ol className="virtual-runners" aria-label={event.result?.kind === 'courses' ? 'Arrivée' : 'Partants'}>
            {(event.result?.kind === 'courses' ? event.result.podium : event.runners).map((r, i) => (
              <li key={r.number}>
                {event.result && <span className="virtual-runner-place">{i === 0 ? '1er' : `${i + 1}e`}</span>}
                <span className="virtual-runner-number">{r.number}</span>
                <span className="virtual-runner-name">{r.name}</span>
              </li>
            ))}
          </ol>
        )}
        {event.game === 'football' && (
          <p className="virtual-stage-text">
            {event.result ? 'Match terminé.' : open ? 'Les mises sont ouvertes jusqu’au compte à rebours.' : 'Le match va commencer.'}
          </p>
        )}
      </section>

      <div className="virtual-detail">
        <div className="markets">
          {event.markets.map((market) => (
            <section key={market.id} className="market-card" aria-labelledby={`market-${market.id}`}>
              <header className="market-head">
                <h2 id={`market-${market.id}`}>{market.name}</h2>
                {!open && <span className="market-note">Mises fermées</span>}
              </header>
              <div
                className={`market-odds cols-${market.selections.length === 3 ? 3 : 2} ${market.selections.length > 3 ? 'long-names' : 'short-labels'}`}
                role="group"
                aria-label={market.name}
              >
                {market.selections.map((s) => {
                  const selected = pick?.selection.id === s.id;
                  const text = market.selections.length > 3 ? `${s.label}. ${s.name}` : s.label;
                  return (
                    <button
                      key={s.id}
                      type="button"
                      className={`odds-btn odds-btn-wide${selected ? ' selected' : ''}`}
                      aria-pressed={selected}
                      disabled={!open}
                      title={s.name}
                      aria-label={`${s.name} à ${s.odds}`}
                      onClick={() => setPick(selected ? null : { market, selection: s })}
                    >
                      <span className="odds-name">{text}</span>
                      <span className="odds-value">{s.odds}</span>
                    </button>
                  );
                })}
              </div>
            </section>
          ))}
        </div>

        <VirtualCoupon event={event} pick={pick} open={open} onClear={() => setPick(null)} />
      </div>
    </>
  );
}
