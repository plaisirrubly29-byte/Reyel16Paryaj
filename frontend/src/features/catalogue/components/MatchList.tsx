import { Link } from 'react-router-dom';
import { Alert } from '../../../shared/components/Alert';
import { Icon } from '../../../shared/components/Icon';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Pagination } from '../../../shared/components/Pagination';
import { Spinner } from '../../../shared/components/Spinner';
import { useEvents, type CatalogueEvent, type ListMarketKey } from '../api/catalogue';
import { isLiveStatus } from '../format';
import { MatchRow } from './MatchRow';

type Props = {
  sport?: string;
  competition?: string;
  /** Filtre local sur les équipes et la compétition (recherche de l'en-tête). */
  search?: string;
  /** Marché affiché sur chaque ligne (1X2 par défaut). */
  market?: ListMarketKey;
  /** N'afficher que les matchs en direct. */
  live?: boolean;
  /** Nombre de matchs par page. */
  perPage?: number;
  /** Page courante (pagination complète) ; sans onPage, seul un lien « Voir tous les matchs » est proposé. */
  page?: number;
  onPage?: (page: number) => void;
};

const normalize = (value: string) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

/** Matchs ouverts aux paris, groupés par compétition (chaque groupe est une région nommée). */
export function MatchList({ sport, competition, search, market = 'h2h', live = false, perPage, page = 1, onPage }: Props) {
  const events = useEvents({ sport, competition, page, perPage, market });

  if (events.isPending) return <div className="table-state"><Spinner label="Chargement des matchs…" /></div>;
  // Erreur au premier chargement seulement : un rafraîchissement raté garde la liste affichée.
  if (!events.data) return <ErrorAlert error={events.error} onRetry={() => events.refetch()} />;

  const { meta } = events.data;
  const needle = search ? normalize(search) : '';
  const list = events.data.data.filter((e) =>
    (!live || isLiveStatus(e.status))
    && (!needle || normalize(`${e.home_team} ${e.away_team} ${e.competition.name} ${e.competition.country ?? ''}`).includes(needle)));

  return (
    <>
      {events.isError && (
        <Alert tone="warning">Cotes non actualisées : la connexion au serveur a échoué. Nouvel essai automatique dans une minute.</Alert>
      )}

      {list.length === 0 ? (
        <p className="empty-state">
          <span className="empty-icon" aria-hidden="true"><Icon name={live ? 'live' : 'clock'} size={26} /></span>
          {live
            ? 'Aucun match en direct pour le moment. Les paris en direct arrivent bientôt.'
            : search
              ? 'Aucun match ne correspond à votre recherche.'
              : 'Aucun match ouvert aux paris pour le moment. Les cotes sont mises à jour plusieurs fois par jour : revenez bientôt.'}
        </p>
      ) : (
        <div className="match-groups">
          {groupByCompetition(list).map(({ competition: c, events: items }) => (
            <section key={c.id} className="match-group" aria-label={c.name}>
              <ul className="match-lines">
                {items.map((e) => <MatchRow key={e.id} event={e} marketKey={market} />)}
              </ul>
            </section>
          ))}
        </div>
      )}

      {onPage
        ? <Pagination meta={meta} onPage={onPage} />
        : !search && !live && meta.total > list.length && (
          <p className="see-all">
            <Link to={sport ? `/sports?sport=${encodeURIComponent(sport)}` : '/sports'} className="btn btn-secondary">
              Voir tous les matchs ({meta.total})
            </Link>
          </p>
        )}
    </>
  );
}

function groupByCompetition(events: CatalogueEvent[]) {
  const groups = new Map<string, { competition: CatalogueEvent['competition']; events: CatalogueEvent[] }>();
  for (const e of events) {
    const group = groups.get(e.competition.id) ?? { competition: e.competition, events: [] };
    group.events.push(e);
    groups.set(e.competition.id, group);
  }
  return [...groups.values()];
}
