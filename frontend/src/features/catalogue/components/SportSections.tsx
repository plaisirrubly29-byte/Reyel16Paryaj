import { Link } from 'react-router-dom';
import { Icon } from '../../../shared/components/Icon';
import { sportIcon } from '../../../shared/components/sportIcon';
import { Spinner } from '../../../shared/components/Spinner';
import { useEvents, useSports, type ListMarketKey } from '../api/catalogue';
import { MatchGroups } from './MatchList';

/** Matchs montrés par sport avant le lien « Voir les N matchs ». */
const PER_SPORT = 10;

/**
 * Page Sports, filtre « Tous » : les matchs rangés par sport, chacun sous son titre (Football, puis Basketball…),
 * dans l'ordre des sports de l'API. Chaque section montre les prochains matchs et mène à la liste complète du sport.
 */
export function SportSections({ market }: { market: ListMarketKey }) {
  const sports = useSports();
  const list = (sports.data ?? []).filter((s) => s.events_count > 0);

  if (sports.isPending) return <div className="table-state"><Spinner label="Chargement des matchs…" /></div>;
  if (list.length === 0) {
    return (
      <div className="empty-state">
        <span className="empty-icon" aria-hidden="true"><Icon name="clock" size={26} /></span>
        <p>Aucun match ouvert aux paris pour le moment. Les cotes sont mises à jour plusieurs fois par jour : revenez bientôt.</p>
      </div>
    );
  }

  return (
    <div className="sport-sections">
      {list.map((s) => <SportSection key={s.slug} slug={s.slug} name={s.name} total={s.events_count} market={market} />)}
    </div>
  );
}

function SportSection({ slug, name, total, market }: { slug: string; name: string; total: number; market: ListMarketKey }) {
  const events = useEvents({ sport: slug, perPage: PER_SPORT, market });
  const list = events.data?.data ?? [];
  const titleId = `sport-section-${slug}`;
  const allLink = `/sports?sport=${encodeURIComponent(slug)}`;

  return (
    <section className="sport-section" aria-labelledby={titleId}>
      <div className="sport-section-head">
        <h3 id={titleId} className="sport-section-title">
          <span className="sport-section-icon" aria-hidden="true"><Icon name={sportIcon(slug)} size={18} /></span>
          {name}
          <span className="sport-section-count">{total}<span className="sr-only"> matchs</span></span>
        </h3>
        <Link to={allLink} className="sport-section-all" aria-label={`Tous les matchs : ${name}`}>
          Tout voir <Icon name="arrowRight" size={16} />
        </Link>
      </div>
      {events.isPending
        ? <div className="table-state"><Spinner label={`Chargement des matchs : ${name}…`} /></div>
        : list.length > 0 && <MatchGroups events={list} market={market} />}
      {total > list.length && list.length > 0 && (
        <p className="sport-section-more">
          <Link to={allLink} className="btn btn-secondary">Voir les {total} matchs : {name}</Link>
        </p>
      )}
    </section>
  );
}
