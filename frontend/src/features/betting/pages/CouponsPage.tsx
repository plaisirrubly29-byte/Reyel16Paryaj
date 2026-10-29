import { Link, useSearchParams } from 'react-router-dom';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Pagination } from '../../../shared/components/Pagination';
import { Spinner } from '../../../shared/components/Spinner';
import { formatMoney } from '../../../shared/format/money';
import { TICKET_STATUS_LABELS, useBets } from '../api/bets';
import { BetCodeShare } from '../tickets/BetCodeShare';

/**
 * Mes coupons : le betcode de chaque ticket placé, prêt à être copié ou partagé
 * (WhatsApp, Facebook). Les données viennent de GET /bets, rien n'est recalculé.
 */
export function CouponsPage() {
  const [params, setParams] = useSearchParams();
  const page = Math.max(1, Number(params.get('page')) || 1);
  const bets = useBets(page);

  return (
    <section className="stack">
      <div>
        <h1>Mes coupons</h1>
        <p className="muted small">Le betcode de chacun de vos tickets, à partager avec vos amis.</p>
      </div>

      <ErrorAlert error={bets.error} onRetry={() => bets.refetch()} />
      {bets.isPending && <Spinner />}

      {bets.data?.data.length === 0 && (
        <div className="empty-state">
          <p>Vous n’avez encore placé aucun pari : vos coupons apparaîtront ici.</p>
          <Link to="/sports" className="btn btn-green">Voir les matchs</Link>
        </div>
      )}

      {!!bets.data?.data.length && (
        <ul className="coupon-list">
          {bets.data.data.map((t) => (
            <li key={t.id} className="coupon-card">
              <header className="coupon-head">
                <span className={`ticket-status status-${t.status}`}>{TICKET_STATUS_LABELS[t.status]}</span>
                <span className="muted small">
                  {new Date(t.placed_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })}
                  {' · '}{t.selections.length} sélection{t.selections.length > 1 ? 's' : ''}
                </span>
              </header>
              <p className="coupon-figures">
                Cote <strong>{t.total_odds}</strong> · Mise <strong>{formatMoney(t.stake)}</strong>
              </p>
              <BetCodeShare code={t.reference} summary={`Cote ${t.total_odds} · Mise ${formatMoney(t.stake)}`} />
            </li>
          ))}
        </ul>
      )}

      <Pagination meta={bets.data?.meta} onPage={(p) => setParams({ page: String(p) })} />
    </section>
  );
}
