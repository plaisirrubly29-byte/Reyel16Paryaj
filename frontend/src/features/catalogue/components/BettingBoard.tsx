import { useSearchParams } from 'react-router-dom';
import { BetSlipPanel } from '../../betting/slip/BetSlipPanel';
import { MatchList } from './MatchList';
import { SportTabs } from './SportTabs';

type Props = {
  /** Nombre de matchs affichés (accueil) ; la page Sports pagine. */
  perPage?: number;
  paginate?: boolean;
};

/** Tableau des paris : barre des sports + matchs groupés par compétition + coupon. Sport et page restent dans l'URL. */
export function BettingBoard({ perPage, paginate = false }: Props) {
  const [params, setParams] = useSearchParams();
  const sport = params.get('sport') ?? undefined;
  const page = paginate ? Math.max(1, Number(params.get('page')) || 1) : 1;

  function update(changes: { sport?: string | null; page?: number }) {
    const p = new URLSearchParams(params);
    if ('sport' in changes) {
      if (changes.sport) p.set('sport', changes.sport); else p.delete('sport');
      p.delete('page');
    }
    if (changes.page !== undefined) {
      if (changes.page > 1) p.set('page', String(changes.page)); else p.delete('page');
    }
    setParams(p, { replace: !('page' in changes) });
  }

  return (
    <div className="betting-layout">
      <div className="betting-main">
        <SportTabs active={sport} onChange={(s) => update({ sport: s ?? null })} />
        <MatchList
          sport={sport}
          perPage={perPage}
          page={page}
          onPage={paginate ? (next) => { update({ page: next }); window.scrollTo({ top: 0 }); } : undefined}
        />
      </div>
      <BetSlipPanel />
    </div>
  );
}
