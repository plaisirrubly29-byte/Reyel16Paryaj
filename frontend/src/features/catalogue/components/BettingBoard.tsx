import type { ReactNode } from 'react';
import { useSearchParams } from 'react-router-dom';
import { BetSlipPanel } from '../../betting/slip/BetSlipPanel';
import { MatchList } from './MatchList';
import { SportTabs } from './SportTabs';
import { SportsSidebar } from './SportsSidebar';

type Props = {
  /** Contenu affiché au-dessus de la liste des matchs (bandeau, top matchs…). */
  children?: ReactNode;
  /** Titre de la liste des matchs. */
  title?: string;
  /** Nombre de matchs affichés (accueil) ; la page Sports pagine. */
  perPage?: number;
  paginate?: boolean;
};

/**
 * Mise en page des paris en trois colonnes : sports et compétitions | matchs | coupon.
 * Sport, compétition et page restent dans l'URL. Sous 1180 px la colonne des sports devient
 * une barre d'onglets ; sous 960 px le coupon s'ouvre depuis la barre d'onglets du bas.
 */
export function BettingBoard({ children, title = 'Matchs à venir', perPage, paginate = false }: Props) {
  const [params, setParams] = useSearchParams();
  const sport = params.get('sport') ?? undefined;
  const competition = params.get('competition') ?? undefined;
  const page = paginate ? Math.max(1, Number(params.get('page')) || 1) : 1;

  function update(changes: { sport?: string | null; page?: number }) {
    const p = new URLSearchParams(params);
    if ('sport' in changes) {
      if (changes.sport) p.set('sport', changes.sport); else p.delete('sport');
      p.delete('competition');
      p.delete('page');
    }
    if (changes.page !== undefined) {
      if (changes.page > 1) p.set('page', String(changes.page)); else p.delete('page');
    }
    setParams(p, { replace: !('page' in changes) });
  }

  return (
    <div className="sportsbook">
      <SportsSidebar />

      <div className="sportsbook-main">
        {children}

        <section className="home-section" aria-labelledby="matches-title">
          <h2 id="matches-title">{title}</h2>
          <SportTabs active={sport} onChange={(s) => update({ sport: s ?? null })} />
          <MatchList
            sport={sport}
            competition={competition}
            perPage={perPage}
            page={page}
            onPage={paginate ? (next) => { update({ page: next }); window.scrollTo({ top: 0 }); } : undefined}
          />
        </section>
      </div>

      <BetSlipPanel />
    </div>
  );
}
