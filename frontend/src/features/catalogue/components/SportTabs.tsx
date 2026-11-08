import { Link } from 'react-router-dom';
import { Icon } from '../../../shared/components/Icon';
import { sportIcon } from '../../../shared/components/sportIcon';
import { useLotteryDraws } from '../../lottery/api/lottery';
import { useSports } from '../api/catalogue';

type Props = { active?: string; onChange: (sport: string | undefined) => void };

/**
 * Bande des sports ouverts aux paris, avec le nombre de matchs (défilement horizontal sur mobile), puis la borlette
 * (nombre de tirages) quand elle est proposée.
 */
export function SportTabs({ active, onChange }: Props) {
  const sports = useSports();
  const lottery = useLotteryDraws().data ?? [];
  const list = sports.data ?? [];
  const total = list.reduce((sum, s) => sum + s.events_count, 0);

  if (list.length === 0) return null;

  return (
    <nav className="sport-tabs" aria-label="Sports">
      <button type="button" className={!active ? 'active' : undefined} aria-pressed={!active} onClick={() => onChange(undefined)}>
        <Icon name="trophy" size={20} /> Tous <span className="count">{total}</span>
      </button>
      {list.map((s) => (
        <button
          key={s.slug}
          type="button"
          className={active === s.slug ? 'active' : undefined}
          aria-pressed={active === s.slug}
          onClick={() => onChange(s.slug)}
        >
          <Icon name={sportIcon(s.slug)} size={20} /> {s.name} <span className="count">{s.events_count}</span>
        </button>
      ))}
      {lottery.length > 0 && (
        <Link to="/borlette" className="sport-tab-link">
          <Icon name="lottery" size={20} /> Borlette <span className="count">{lottery.length}</span>
        </Link>
      )}
    </nav>
  );
}
