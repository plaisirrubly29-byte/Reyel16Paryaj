import { useEffect, useId, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Icon } from '../../../shared/components/Icon';
import { sportIcon } from '../../../shared/components/sportIcon';
import { useSports } from '../api/catalogue';

function normalize(value: string): string {
  return value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/**
 * Menu « Championnats » de l'en-tête : sports à gauche, compétitions du sport choisi à droite (déjà dans l'ordre
 * « top compétitions » de l'API), avec une recherche sur toutes les compétitions. Se ferme au clic extérieur,
 * avec Échap et à chaque changement de page.
 */
export function CompetitionsMenu() {
  const sports = useSports();
  const { pathname, search } = useLocation();
  const [open, setOpen] = useState(false);
  const [sportSlug, setSportSlug] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const root = useRef<HTMLDivElement>(null);
  const panelId = useId();

  const list = sports.data ?? [];
  const sport = list.find((s) => s.slug === sportSlug) ?? list[0];
  const needle = normalize(query.trim());
  const matches = needle
    ? list.flatMap((s) => s.competitions
      .filter((c) => normalize(`${c.name} ${c.country ?? ''}`).includes(needle))
      .map((c) => ({ ...c, sport: s })))
    : [];

  useEffect(() => { setOpen(false); }, [pathname, search]);

  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
    const onClick = (e: MouseEvent) => { if (!root.current?.contains(e.target as Node)) setOpen(false); };
    document.addEventListener('keydown', onKey);
    document.addEventListener('mousedown', onClick);
    return () => {
      document.removeEventListener('keydown', onKey);
      document.removeEventListener('mousedown', onClick);
    };
  }, [open]);

  const competitionLink = (slug: string, id: string) =>
    `/sports?sport=${encodeURIComponent(slug)}&competition=${encodeURIComponent(id)}`;

  return (
    <div className="competitions-menu" ref={root}>
      <button
        type="button"
        className={`competitions-toggle${open ? ' open' : ''}`}
        aria-expanded={open}
        aria-controls={panelId}
        onClick={() => setOpen(!open)}
      >
        <Icon name="trophy" size={20} />
        <span className="competitions-toggle-label">Championnats</span>
        <span className="competitions-chevron" aria-hidden="true"><Icon name="chevron" size={16} /></span>
      </button>

      {open && (
        <div className="competitions-panel" id={panelId} role="region" aria-label="Championnats">
          <label className="competitions-search">
            <Icon name="search" size={18} />
            <span className="sr-only">Rechercher un championnat</span>
            <input
              type="search"
              placeholder="Rechercher un championnat ou un pays…"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              autoFocus
            />
          </label>

          {sports.isPending ? (
            <p className="competitions-empty">Chargement des championnats…</p>
          ) : list.length === 0 ? (
            <p className="competitions-empty">Aucun championnat proposé pour le moment.</p>
          ) : needle ? (
            <ul className="competitions-list competitions-results">
              {matches.length === 0 && <li className="competitions-empty">Aucun championnat ne correspond à « {query.trim()} ».</li>}
              {matches.map((c) => (
                <li key={c.id}>
                  <Link to={competitionLink(c.sport.slug, c.id)}>
                    <span className="competitions-name">
                      {c.name}
                      <span className="competitions-sub">{c.sport.name}{c.country ? ` · ${c.country}` : ''}</span>
                    </span>
                    <span className="competitions-count">{c.events_count}</span>
                  </Link>
                </li>
              ))}
            </ul>
          ) : (
            <div className="competitions-columns">
              <ul className="competitions-sports" aria-label="Sports">
                {list.map((s) => (
                  <li key={s.slug}>
                    <button
                      type="button"
                      className={s.slug === sport?.slug ? 'active' : undefined}
                      aria-pressed={s.slug === sport?.slug}
                      onClick={() => setSportSlug(s.slug)}
                    >
                      <Icon name={sportIcon(s.slug)} size={20} />
                      <span>{s.name}</span>
                      <span className="competitions-count">{s.events_count}</span>
                    </button>
                  </li>
                ))}
              </ul>

              {sport && (
                <ul className="competitions-list" aria-label={`Championnats : ${sport.name}`}>
                  <li>
                    <Link to={`/sports?sport=${encodeURIComponent(sport.slug)}`} className="competitions-all">
                      <span className="competitions-name">Tous les matchs : {sport.name}</span>
                      <Icon name="arrowRight" size={18} />
                    </Link>
                  </li>
                  {sport.competitions.map((c) => (
                    <li key={c.id}>
                      <Link to={competitionLink(sport.slug, c.id)}>
                        <span className="competitions-name">
                          {c.name}
                          {c.country && <span className="competitions-sub">{c.country}</span>}
                        </span>
                        <span className="competitions-count">{c.events_count}</span>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
