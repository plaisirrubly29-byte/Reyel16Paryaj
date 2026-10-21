import { Link } from 'react-router-dom';
import { formatMoney } from '../../../shared/format/money';
import { Icon } from '../../../shared/components/Icon';
import { useMe } from '../../auth/api/auth';
import { formatKickoff } from '../../catalogue/format';
import { BetCodeShare } from '../tickets/BetCodeShare';
import { loadTickets, type Ticket } from '../tickets/ticketStore';

const CURRENCY = 'HTG';

/** MES PARIS : tous les tickets du joueur connecté (page protégée par RequireAuth). */
export function MyBetsPage() {
  const { data: user } = useMe();
  const tickets = user ? loadTickets(user.id) : [];

  return (
    <section className="stack my-bets">
      <div className="my-bets-head">
        <h1>MES PARIS</h1>
        <p className="muted small">Tickets de démonstration : aucun argent réel n'est engagé.</p>
      </div>

      {tickets.length === 0 ? (
        <div className="empty-state">
          <span className="empty-icon" aria-hidden="true"><Icon name="coupon" size={26} /></span>
          <strong>Vous n'avez encore aucun pari</strong>
          <span>Choisissez vos cotes, saisissez une mise et placez votre premier pari.</span>
          <Link to="/sports" className="btn btn-primary btn-sm">Voir les matchs</Link>
        </div>
      ) : (
        <ul className="ticket-list">
          {tickets.map((t) => <TicketCard key={t.code} ticket={t} />)}
        </ul>
      )}
    </section>
  );
}

/** Carte d'un ticket : code, sélections, cote combinée, mise, gain potentiel et partage. */
export function TicketCard({ ticket }: { ticket: Ticket }) {
  const placed = new Date(ticket.placedAt).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

  return (
    <li className="ticket-card">
      <header className="ticket-head">
        <span className="ticket-status">En attente</span>
        <span className="muted small">Placé le {placed}</span>
      </header>

      <ul className="ticket-selections">
        {ticket.selections.map((s, i) => {
          const { day, time } = formatKickoff(s.startsAt);
          return (
            <li key={`${s.match}-${i}`}>
              <div>
                <strong>{s.label === s.pick ? s.pick : `${s.label} · ${s.pick}`}</strong>
                <div className="muted small">{s.match} · {s.market ?? 'Résultat du match'} · {day} {time}</div>
              </div>
              <span className="odds-value">{s.odds}</span>
            </li>
          );
        })}
      </ul>

      <dl className="ticket-totals">
        <div><dt>Cote totale</dt><dd>{ticket.combinedOdds}</dd></div>
        <div><dt>Mise</dt><dd>{formatMoney({ minor: ticket.stakeMinor, currency: CURRENCY })}</dd></div>
        <div><dt>Gain potentiel</dt><dd className="ticket-return">{formatMoney({ minor: ticket.potentialMinor, currency: CURRENCY })}</dd></div>
      </dl>

      <BetCodeShare code={ticket.code} summary={`Cote ${ticket.combinedOdds} · Mise ${formatMoney({ minor: ticket.stakeMinor, currency: CURRENCY })}`} />
    </li>
  );
}
