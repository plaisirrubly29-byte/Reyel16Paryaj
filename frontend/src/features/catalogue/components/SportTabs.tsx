import { useSports } from '../api/catalogue';

type Props = { active?: string; onChange: (sport: string | undefined) => void };

/** Barre des sports ouverts aux paris, avec le nombre de matchs (défilement horizontal sur mobile). */
export function SportTabs({ active, onChange }: Props) {
  const sports = useSports();
  const list = sports.data ?? [];
  const total = list.reduce((sum, s) => sum + s.events_count, 0);

  if (list.length === 0) return null;

  return (
    <nav className="sport-tabs" aria-label="Sports">
      <button type="button" className={!active ? 'active' : undefined} aria-pressed={!active} onClick={() => onChange(undefined)}>
        Tous <span className="count">{total}</span>
      </button>
      {list.map((s) => (
        <button
          key={s.slug}
          type="button"
          className={active === s.slug ? 'active' : undefined}
          aria-pressed={active === s.slug}
          onClick={() => onChange(s.slug)}
        >
          {s.name} <span className="count">{s.events_count}</span>
        </button>
      ))}
    </nav>
  );
}
