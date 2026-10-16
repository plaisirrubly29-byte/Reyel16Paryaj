import { Link } from 'react-router-dom';
import { Alert } from '../../../shared/components/Alert';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Pagination } from '../../../shared/components/Pagination';
import { Spinner } from '../../../shared/components/Spinner';
import { useEvents, type CatalogueEvent } from '../api/catalogue';
import { MatchRow } from './MatchRow';

type Props = {
  sport?: string;
  competition?: string;
  /** Nombre de matchs par page. */
  perPage?: number;
  /** Page courante (pagination complète) ; sans onPage, seul un lien « Voir tous les matchs » est proposé. */
  page?: number;
  onPage?: (page: number) => void;
};

/** Matchs à venir groupés par compétition, dans l'ordre chronologique. */
export function MatchList({ sport, competition, perPage, page = 1, onPage }: Props) {
  const events = useEvents({ sport, competition, page, perPage });

  if (events.isPending) return <div className="table-state"><Spinner label="Chargement des matchs…" /></div>;
  // Erreur au premier chargement seulement : un rafraîchissement raté garde la liste affichée.
  if (!events.data) return <ErrorAlert error={events.error} onRetry={() => events.refetch()} />;

  const { data: list, meta } = events.data;

  return (
    <>
      {events.isError && (
        <Alert tone="warning">Cotes non actualisées : la connexion au serveur a échoué. Nouvel essai automatique dans une minute.</Alert>
      )}

      {list.length === 0 ? (
        <p className="empty-state">
          Aucun match ouvert aux paris pour le moment. Les cotes sont mises à jour plusieurs fois par jour : revenez bientôt.
        </p>
      ) : (
        <div className="match-groups">
          {groupByCompetition(list).map(({ competition: c, events: items }) => (
            <section key={c.id} className="match-group" aria-label={c.name}>
              <header className="match-group-head">
                <span className="sport-chip">{items[0].sport.name}</span>
                <h3>{c.name}</h3>
                {c.country && <span className="muted small">{c.country}</span>}
                <span className="odds-head" aria-hidden="true"><span>1</span><span>X</span><span>2</span></span>
              </header>
              <ul className="match-rows">
                {items.map((e) => <MatchRow key={e.id} event={e} />)}
              </ul>
            </section>
          ))}
        </div>
      )}

      {onPage
        ? <Pagination meta={meta} onPage={onPage} />
        : meta.total > list.length && (
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
