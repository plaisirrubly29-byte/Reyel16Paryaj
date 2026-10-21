import type { ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Icon } from '../../../shared/components/Icon';
import { BetSlipPanel } from '../../betting/slip/BetSlipPanel';
import { LIST_MARKETS, type ListMarketKey } from '../api/catalogue';
import { MatchList } from './MatchList';
import { SportTabs } from './SportTabs';
import { SportsSidebar } from './SportsSidebar';

type Props = {
  /** Contenu affiché au-dessus de la liste des matchs (bandeau, top matchs…). */
  children?: ReactNode;
  /** Titre de la liste des matchs (lu par les lecteurs d'écran ; les onglets en tiennent lieu à l'écran). */
  title?: string;
  /** Nombre de matchs affichés (accueil) ; la page Sports pagine. */
  perPage?: number;
  paginate?: boolean;
};

/**
 * Mise en page des paris en trois colonnes : sports et compétitions | matchs | coupon.
 * Onglet (à venir / live), sport, compétition, type de pari, recherche et page restent dans l'URL.
 * Sous 1180 px la colonne des sports disparaît ; sous 960 px le coupon s'ouvre depuis la barre d'onglets du bas.
 */
export function BettingBoard({ children, title = 'Tous les matchs', perPage, paginate = false }: Props) {
  const [params, setParams] = useSearchParams();
  const sport = params.get('sport') ?? undefined;
  const competition = params.get('competition') ?? undefined;
  const search = params.get('q')?.trim() || undefined;
  const live = params.get('tab') === 'live';
  const market: ListMarketKey = LIST_MARKETS.find((m) => m.key === params.get('market'))?.key ?? 'h2h';
  const page = paginate ? Math.max(1, Number(params.get('page')) || 1) : 1;

  function update(changes: { sport?: string | null; page?: number; live?: boolean; market?: ListMarketKey }) {
    const p = new URLSearchParams(params);
    if (changes.market !== undefined) {
      if (changes.market !== 'h2h') p.set('market', changes.market); else p.delete('market');
    }
    if ('sport' in changes) {
      if (changes.sport) p.set('sport', changes.sport); else p.delete('sport');
      p.delete('competition');
      p.delete('page');
    }
    if (changes.live !== undefined) {
      if (changes.live) p.set('tab', 'live'); else p.delete('tab');
      p.delete('page');
    }
    if (changes.page !== undefined) {
      if (changes.page > 1) p.set('page', String(changes.page)); else p.delete('page');
    }
    setParams(p, { replace: !('page' in changes) });
  }

  function clearSearch() {
    const p = new URLSearchParams(params);
    p.delete('q');
    p.delete('page');
    setParams(p);
  }

  return (
    <div className="sportsbook">
      <SportsSidebar />

      <div className="sportsbook-main">
        {children}

        <section className="board" aria-labelledby="matches-title">
          <h2 id="matches-title" className="sr-only">{title}</h2>
          <div className="board-tabs" role="group" aria-label="Matchs à venir ou en direct">
            <button type="button" aria-pressed={!live} className={!live ? 'active' : undefined} onClick={() => update({ live: false })}>
              À venir
            </button>
            <button type="button" aria-pressed={live} className={live ? 'active' : undefined} onClick={() => update({ live: true })}>
              <Icon name="live" size={24} />Live
            </button>
          </div>
          <SportTabs active={sport} onChange={(s) => update({ sport: s ?? null })} />
          <div className="market-chips" role="group" aria-label="Type de pari affiché">
            {LIST_MARKETS.map((m) => (
              <button
                key={m.key}
                type="button"
                className={market === m.key ? 'active' : undefined}
                aria-pressed={market === m.key}
                title={m.name}
                onClick={() => update({ market: m.key })}
              >
                {m.short}<span className="sr-only"> — {m.name}</span>
              </button>
            ))}
          </div>
          {search && (
            <p className="board-search">
              Résultats pour « <strong>{search}</strong> »
              <button type="button" className="link-button" onClick={clearSearch}>Effacer la recherche</button>
            </p>
          )}
          {competition && (
            <p className="board-search">
              Compétition sélectionnée
              <Link to={sport ? `/sports?sport=${encodeURIComponent(sport)}` : '/sports'} className="link-button">Voir toutes les compétitions</Link>
            </p>
          )}
          <MatchList
            sport={sport}
            competition={competition}
            search={search}
            live={live}
            market={market}
            perPage={search || live ? 100 : perPage}
            page={search || live ? 1 : page}
            onPage={paginate && !search && !live ? (next) => { update({ page: next }); window.scrollTo({ top: 0 }); } : undefined}
          />
        </section>
      </div>

      <BetSlipPanel />
    </div>
  );
}
