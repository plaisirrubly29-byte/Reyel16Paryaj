import { Link, useSearchParams } from 'react-router-dom';
import { formatMoney } from '../../../shared/format/money';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon } from '../../../shared/components/Icon';
import { Pagination } from '../../../shared/components/Pagination';
import { Spinner } from '../../../shared/components/Spinner';
import { formatKickoff } from '../../catalogue/format';
import { TICKET_STATUS_LABELS, useBets, type BetTicket } from '../api/bets';
import { BetCodeShare } from '../tickets/BetCodeShare';

/** MES PARIS : les tickets du joueur connecté, enregistrés par le serveur (page protégée par RequireAuth). */
export function MyBetsPage() {
  const [params, setParams] = useSearchParams();
  const page = Math.max(1, Number(params.get('page')) || 1);
  const bets = useBets(page);
  const tickets = bets.data?.data ?? [];

  return (
    <section className="stack my-bets">
      <div className="my-bets-head">
        <h1>MES PARIS</h1>
        <p className="muted small">Fonds fictifs : aucun argent réel n'est engagé.</p>
      </div>

      {bets.isPending && <Spinner />}
      <ErrorAlert error={bets.error} onRetry={() => bets.refetch()} />

      {bets.isSuccess && tickets.length === 0 ? (
        <div className="empty-state">
          <span className="empty-icon" aria-hidden="true"><Icon name="coupon" size={26} /></span>
          <strong>Vous n'avez encore aucun pari</strong>
          <span>Choisissez vos cotes, saisissez une mise et placez votre premier pari.</span>
          <Link to="/sports" className="btn btn-primary btn-sm">Voir les matchs</Link>
        </div>
      ) : (
        <ul className="ticket-list">
          {tickets.map((t) => <TicketCard key={t.id} ticket={t} />)}
        </ul>
      )}

      <Pagination meta={bets.data?.meta} onPage={(next) => { setParams({ page: String(next) }); window.scrollTo({ top: 0 }); }} />
    </section>
  );
}

/** Carte d'un ticket : statut, sélections, cote totale, mise, gain (potentiel ou versé) et betcode à partager. */
export function TicketCard({ ticket }: { ticket: BetTicket }) {
  const placed = new Date(ticket.placed_at).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  const settled = ticket.payout !== null;

  return (
    <li className="ticket-card">
      <header className="ticket-head">
        <span className={`ticket-status status-${ticket.status}`}>{TICKET_STATUS_LABELS[ticket.status]}</span>
        <span className="muted small">{ticket.type === 'multiple' ? 'Combiné' : 'Simple'} · placé le {placed}</span>
      </header>

      <ul className="ticket-selections">
        {ticket.selections.map((s) => {
          const { day, time } = formatKickoff(s.event.starts_at);
          return (
            <li key={s.id} className={`selection-${s.status}`}>
              <div>
                <strong>{s.selection.label === s.selection.name ? s.selection.name : `${s.selection.label} · ${s.selection.name}`}</strong>
                <div className="muted small">{s.event.name} · {s.market.name} · {day} {time}</div>
              </div>
              <span className="odds-value">{s.odds}</span>
            </li>
          );
        })}
      </ul>

      <dl className="ticket-totals">
        <div><dt>Cote totale</dt><dd>{ticket.total_odds}</dd></div>
        <div><dt>Mise</dt><dd>{formatMoney(ticket.stake)}</dd></div>
        <div>
          <dt>{settled ? 'Gain versé' : 'Gain potentiel'}</dt>
          <dd className="ticket-return">{formatMoney(ticket.payout ?? ticket.potential_payout)}</dd>
        </div>
      </dl>

      <BetCodeShare code={ticket.reference} summary={`Cote ${ticket.total_odds} · Mise ${formatMoney(ticket.stake)}`} />
    </li>
  );
}
