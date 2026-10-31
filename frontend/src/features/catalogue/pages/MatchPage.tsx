import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ApiError } from '../../../shared/api/client';
import { Alert } from '../../../shared/components/Alert';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon } from '../../../shared/components/Icon';
import { Spinner } from '../../../shared/components/Spinner';
import { sportIcon } from '../../../shared/components/sportIcon';
import { BetSlipPanel } from '../../betting/slip/BetSlipPanel';
import { useEventMarkets, type CatalogueEvent } from '../api/catalogue';
import { formatKickoff, isLiveStatus, liveLabel } from '../format';
import { MarketOdds } from '../components/OddsButtons';
import { marketCategory, marketMatchesSearch, type MarketCategory } from '../markets';
import { SportsSidebar } from '../components/SportsSidebar';

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
  const [category, setCategory] = useState<MarketCategory | 'Tous'>('Tous');
  const [search, setSearch] = useState('');

  if (query.isPending) return <div className="table-state"><Spinner label="Chargement du match…" /></div>;

  if (!query.data) {
    if (query.error instanceof ApiError && query.error.status === 404) {
      return (
        <div className="empty-state">
          <span className="empty-icon" aria-hidden="true"><Icon name="clock" size={26} /></span>
          <strong>Ce match n'est plus proposé</strong>
          <span>Il a peut-être commencé sans marché en direct disponible, ou ses cotes ne sont plus proposées.</span>
          <Link to="/sports" className="btn btn-secondary btn-sm">Voir les autres matchs</Link>
        </div>
      );
    }
    return <ErrorAlert error={query.error} onRetry={() => query.refetch()} />;
  }

  const event = query.data;
  const presentCategories = new Set(event.markets.map(marketCategory));
  const categories = (['Résultat', 'Buts', 'Score exact', 'Joueurs', 'Autres'] as const)
    .filter((item) => presentCategories.has(item));
  const visibleMarkets = event.markets.filter((market) =>
    (category === 'Tous' || marketCategory(market) === category)
    && marketMatchesSearch(market, search));

  return (
    <>
      {query.isError && (
        <Alert tone="warning">Cotes non actualisées : la connexion au serveur a échoué. Nouvel essai automatique dans une minute.</Alert>
      )}
      <MatchHeader event={event} />

      {event.markets.length === 0 ? (
        <p className="empty-state">Aucun pari ouvert sur ce match pour le moment.</p>
      ) : (
        <>
          <div className="market-tools">
            <label className="market-search">
              <span>Rechercher un marché ou un joueur</span>
              <input
                type="search"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Ex. score exact, Mbappé"
              />
            </label>
            <div className="market-filters" role="group" aria-label="Filtrer les marchés">
              <button type="button" aria-pressed={category === 'Tous'} onClick={() => setCategory('Tous')}>Tous</button>
              {categories.map((item) => (
                <button key={item} type="button" aria-pressed={category === item} onClick={() => setCategory(item)}>
                  {item}
                </button>
              ))}
            </div>
          </div>
          {visibleMarkets.length === 0 ? (
            <p className="empty-state">Aucun marché ni joueur ne correspond à votre recherche.</p>
          ) : (
            <div className="markets">
              {visibleMarkets.map((market) => (
                <section key={market.id} className="market-card" aria-labelledby={`market-${market.id}`}>
                  <header className="market-head">
                    <h2 id={`market-${market.id}`}><span className="market-help" aria-hidden="true"><Icon name="help" size={24} /></span>{market.name}</h2>
                    {market.bettable === false && (
                      <span className="market-note">{isLiveStatus(event.status) ? 'Paris fermés pendant le match' : 'Cotes en cours de mise à jour'}</span>
                    )}
                  </header>
                  <MarketOdds event={event} market={market} variant="wide" selectionSearch={search} />
                </section>
              ))}
            </div>
          )}
        </>
      )}
    </>
  );
}

function MatchHeader({ event }: { event: CatalogueEvent }) {
  const { day, time } = formatKickoff(event.starts_at);
  const live = liveLabel(event.status);

  return (
    <section className="match-header" aria-labelledby="match-title">
      <p className="match-header-competition">
        {event.competition.country ? `${event.competition.country} - ` : ''}{event.competition.name}
      </p>
      <h1 id="match-title" className="match-header-teams">
        <span className="match-header-icon" aria-hidden="true"><Icon name={sportIcon(event.sport.slug)} size={30} /></span>
        <span className="match-header-team">{event.home_team}</span>
        {live && event.score
          ? <span className="match-header-score" aria-label={`Score : ${event.score.home} à ${event.score.away}`}>{event.score.home} - {event.score.away}</span>
          : <span className="match-header-vs" aria-hidden="true">-</span>}
        <span className="sr-only"> contre </span>
        <span className="match-header-team">{event.away_team}</span>
      </h1>
      <p className="match-header-kickoff">
        {live ? <span className="live-badge live-indicator"><Icon name="live" size={14} />{live}</span> : `${day} - ${time}`}
      </p>
    </section>
  );
}
