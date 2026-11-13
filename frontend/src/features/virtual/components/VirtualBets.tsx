import { Link } from 'react-router-dom';
import { useMe } from '../../auth/api/auth';
import { TICKET_STATUS_LABELS } from '../../betting/api/bets';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Spinner } from '../../../shared/components/Spinner';
import { formatMoney } from '../../../shared/format/money';
import { useVirtualBets } from '../api/virtualGames';
import type { VirtualBet } from '../types';
import { formatStartTime } from '../format';

type Props = {
  /** Nombre de paris affichés (les plus récents) ; tous si absent. */
  limit?: number;
  title?: string;
};

/** Paris virtuels du joueur connecté (rien pour un visiteur), sur le modèle des tickets de « Mes paris ». */
export function VirtualBets({ limit, title = 'Mes paris virtuels' }: Props) {
  const me = useMe();
  const bets = useVirtualBets();
  if (!me.data) return null;
  const list = bets.data ?? [];
  const shown = limit ? list.slice(0, limit) : list;

  return (
    <section className="virtual-section" aria-labelledby="virtual-bets-title">
      <div className="virtual-section-head">
        <h2 id="virtual-bets-title">{title}</h2>
        {limit && list.length > limit && <Link to="/bets" className="virtual-see-all">Tout voir</Link>}
      </div>
      {bets.isPending ? (
        <Spinner label="Chargement de vos paris…" />
      ) : bets.isError ? (
        <ErrorAlert error={bets.error} onRetry={() => bets.refetch()} />
      ) : shown.length === 0 ? (
        <p className="virtual-empty">Aucun pari virtuel pour l&apos;instant. Choisissez une épreuve et placez votre premier pari.</p>
      ) : (
        <ul className="ticket-list">
          {shown.map((b) => <VirtualBetCard key={b.id} bet={b} />)}
        </ul>
      )}
    </section>
  );
}

function VirtualBetCard({ bet }: { bet: VirtualBet }) {
  const placed = new Date(bet.placed_at).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  const settled = bet.payout !== null;

  return (
    <li className="ticket-card">
      <header className="ticket-head">
        <span className={`ticket-status status-${bet.status}`}>{TICKET_STATUS_LABELS[bet.status]}</span>
        <span className="muted small">Réf. {bet.reference} · placé le {placed}</span>
      </header>

      <ul className="ticket-selections">
        <li className={`selection-${bet.status}`}>
          <div>
            <strong>{bet.selection.label === bet.selection.name ? bet.selection.name : `${bet.selection.label} · ${bet.selection.name}`}</strong>
            <div className="muted small">
              <Link to={`/jeux-virtuels/${bet.event.id}`}>{bet.event.title}</Link> · {bet.market.name} · {formatStartTime(bet.event.starts_at)}
            </div>
          </div>
          <span className="odds-value">{bet.odds}</span>
        </li>
      </ul>

      <dl className="ticket-totals">
        <div><dt>Cote</dt><dd>{bet.odds}</dd></div>
        <div><dt>Mise</dt><dd>{formatMoney(bet.stake)}</dd></div>
        <div>
          <dt>{settled ? 'Gain versé' : 'Gain potentiel'}</dt>
          <dd className="ticket-return">{formatMoney(bet.payout ?? bet.potential_payout)}</dd>
        </div>
      </dl>
    </li>
  );
}
