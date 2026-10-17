import { Link, useParams } from 'react-router-dom';
import { ApiError } from '../../../shared/api/client';
import { Alert } from '../../../shared/components/Alert';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon } from '../../../shared/components/Icon';
import { Spinner } from '../../../shared/components/Spinner';
import { sportIcon } from '../../../shared/components/sportIcon';
import { BetSlipPanel } from '../../betting/slip/BetSlipPanel';
import { useEventMarkets, type CatalogueEvent } from '../api/catalogue';
import { formatKickoff } from '../format';
import { MarketOdds } from '../components/OddsButtons';
import { SportsSidebar } from '../components/SportsSidebar';
import { TeamBadge } from '../components/TeamBadge';

/** Page d'un match : en-tête (compétition, équipes, coup d'envoi) et tous ses marchés, avec le coupon à droite. */
export function MatchPage() {
  const { id = '' } = useParams();
  const query = useEventMarkets(id);

  return (
    <div className="sportsbook">
      <SportsSidebar />

      <div className="sportsbook-main">
        <Link to="/sports" className="back-link"><Icon name="chevron" size={16} />Tous les matchs</Link>
        <MatchContent query={query} />
      </div>

      <BetSlipPanel />
    </div>
  );
}

function MatchContent({ query }: { query: ReturnType<typeof useEventMarkets> }) {
  if (query.isPending) return <div className="table-state"><Spinner label="Chargement du match…" /></div>;

  if (!query.data) {
    if (query.error instanceof ApiError && query.error.status === 404) {
      return (
        <div className="empty-state">
          <span className="empty-icon" aria-hidden="true"><Icon name="clock" size={26} /></span>
          <strong>Ce match n'est plus proposé</strong>
          <span>Il a peut-être commencé, ou ses cotes ne sont plus disponibles.</span>
          <Link to="/sports" className="btn btn-secondary btn-sm">Voir les autres matchs</Link>
        </div>
      );
    }
    return <ErrorAlert error={query.error} onRetry={() => query.refetch()} />;
  }

  const event = query.data;

  return (
    <>
      {query.isError && (
        <Alert tone="warning">Cotes non actualisées : la connexion au serveur a échoué. Nouvel essai automatique dans une minute.</Alert>
      )}
      <MatchHeader event={event} />

      {event.markets.length === 0 ? (
        <p className="empty-state">Aucun pari ouvert sur ce match pour le moment.</p>
      ) : (
        <div className="markets">
          {event.markets.map((market) => (
            <section key={market.id} className="market-card" aria-labelledby={`market-${market.id}`}>
              <header className="market-head">
                <h2 id={`market-${market.id}`}>{market.name}</h2>
                {market.bettable === false && <span className="market-note">Cotes en cours de mise à jour</span>}
              </header>
              <MarketOdds event={event} market={market} variant="wide" />
            </section>
          ))}
        </div>
      )}
    </>
  );
}

function MatchHeader({ event }: { event: CatalogueEvent }) {
  const { day, time } = formatKickoff(event.starts_at);

  return (
    <section className="match-header" aria-labelledby="match-title">
      <p className="match-header-competition">
        <Icon name={sportIcon(event.sport.slug)} size={16} />
        {event.competition.country ? `${event.competition.country} · ` : ''}{event.competition.name}
      </p>
      <h1 id="match-title" className="match-header-teams">
        <span className="match-header-team"><TeamBadge name={event.home_team} />{event.home_team}</span>
        <span className="match-header-vs" aria-hidden="true">–</span>
        <span className="sr-only"> contre </span>
        <span className="match-header-team"><TeamBadge name={event.away_team} />{event.away_team}</span>
      </h1>
      <p className="match-header-kickoff"><Icon name="clock" size={15} />{day} · {time}</p>
    </section>
  );
}
