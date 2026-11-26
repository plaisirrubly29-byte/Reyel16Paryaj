import { Link } from 'react-router-dom';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon, type IconName } from '../../../shared/components/Icon';
import { Pagination } from '../../../shared/components/Pagination';
import { Spinner } from '../../../shared/components/Spinner';
import { formatMoney } from '../../../shared/format/money';
import {
  LOTTERY_STATUS_LABELS, useLotteryTickets, type LotteryTicket, type LotteryTicketPlay, type LotteryTicketStatus,
} from '../api/lottery';
import { formatResultDate } from '../format';
import { gameName, lotLabel, playLabel } from '../games';
import { LotteryBalls } from './LotteryBalls';

const STATUS_FILTERS: { value: LotteryTicketStatus | undefined; label: string }[] = [
  { value: undefined, label: 'Tout' },
  { value: 'pending', label: 'En cours' },
  { value: 'won', label: 'Gagné' },
  { value: 'lost', label: 'Perdu' },
];

const PLAY_ICONS: Record<LotteryTicketStatus, { icon: IconName; label: string }> = {
  pending: { icon: 'clock', label: 'En cours' },
  won: { icon: 'trophy', label: 'Gagnante' },
  lost: { icon: 'close', label: 'Perdante' },
  void: { icon: 'refresh', label: 'Remboursée' },
};

type Props = {
  page: number;
  status: LotteryTicketStatus | undefined;
  onStatus: (status: LotteryTicketStatus | undefined) => void;
  onPage: (page: number) => void;
};

/** Fiches de borlette du joueur dans « Mes paris » : filtrées par statut côté serveur, paginées. */
export function LotteryTickets({ page, status, onStatus, onPage }: Props) {
  const tickets = useLotteryTickets(page, status);
  const list = tickets.data?.data ?? [];

  return (
    <>
      <div className="my-bets-filters" role="group" aria-label="Statut des fiches">
        {STATUS_FILTERS.map((f) => {
          const active = status === f.value;
          return (
            <button key={f.label} type="button" className={`status-chip${active ? ' active' : ''}`} aria-pressed={active} onClick={() => onStatus(f.value)}>
              {f.label}
            </button>
          );
        })}
      </div>

      {tickets.isPending && <Spinner />}
      <ErrorAlert error={tickets.error} onRetry={() => tickets.refetch()} />

      {tickets.isSuccess && list.length === 0 ? (
        <div className="empty-state">
          <span className="empty-icon" aria-hidden="true"><Icon name="lottery" size={26} /></span>
          <strong>{status ? 'Aucune fiche pour ce statut' : 'Vous n\'avez encore aucune fiche de borlette'}</strong>
          <Link to="/borlette" className="btn btn-primary btn-sm">Jouer à la borlette</Link>
        </div>
      ) : (
        <ul className="ticket-list" aria-label="Fiches de borlette">
          {list.map((t) => <LotteryTicketCard key={t.id} ticket={t} />)}
        </ul>
      )}

      <Pagination meta={tickets.data?.meta} onPage={onPage} />
    </>
  );
}

function placedAt(iso: string): string {
  return new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function PlayRow({ play }: { play: LotteryTicketPlay }) {
  const state = PLAY_ICONS[play.status];
  const result = play.payout
    ? play.status === 'won'
      ? `Gain ${formatMoney(play.payout)}${play.winning_lot ? ` (${lotLabel(play.winning_lot)})` : ''}`
      : play.status === 'void' ? `Remboursé ${formatMoney(play.payout)}` : null
    : play.status === 'pending' ? `Gain max. ${formatMoney(play.max_payout)}` : null;

  return (
    <li className={`ticket-selection selection-${play.status}`}>
      <div className="ticket-selection-main">
        <strong className="ticket-event">{gameName(play.game)} <span className="lottery-play-numbers">{playLabel(play)}</span></strong>
        <span className="muted small">Mise {formatMoney(play.stake)}</span>
        {result && <span className="ticket-pick">{result}</span>}
      </div>
      <div className="ticket-selection-side">
        <span className={`selection-state state-${play.status}`} title={state.label} role="img" aria-label={state.label}>
          <Icon name={state.icon} size={14} />
        </span>
      </div>
    </li>
  );
}

/** Carte d'une fiche : tirage et date, lignes, mise, gains (possible ou versé), boules du résultat une fois publié. */
export function LotteryTicketCard({ ticket }: { ticket: LotteryTicket }) {
  const returnLabel = { pending: 'Gain maximal', won: 'Gain versé', lost: 'Gain', void: 'Remboursé' }[ticket.status];
  const returned = ticket.payout ?? (ticket.status === 'pending' ? ticket.max_payout : { ...ticket.stake, minor: 0 });

  return (
    <li className="ticket-card" aria-label={`Fiche ${ticket.reference}`}>
      <header className="ticket-head">
        <div className="ticket-head-text">
          <strong className="ticket-title">Borlette - {ticket.draw.name}</strong>
          <span className="muted small">Tirage du {formatResultDate(ticket.draw.date)}</span>
          <span className="muted small">{placedAt(ticket.placed_at)} | Réf. <span className="mono">{ticket.reference}</span></span>
        </div>
        <span className={`ticket-status status-${ticket.status}`}>{LOTTERY_STATUS_LABELS[ticket.status]}</span>
      </header>

      {ticket.result ? (
        <div className="lottery-ticket-result">
          <span className="muted small">Résultat</span>
          <LotteryBalls result={ticket.result} />
        </div>
      ) : ticket.status === 'pending' && (
        <p className="lottery-ticket-result muted small">Résultat pas encore publié.</p>
      )}

      <ul className="ticket-selections">
        {ticket.plays.map((p, i) => <PlayRow key={i} play={p} />)}
      </ul>

      <dl className="ticket-summary">
        <div><dt>Mise</dt><dd>{formatMoney(ticket.stake)}</dd></div>
        <div className="ticket-summary-return">
          <dt>{returnLabel}</dt>
          <dd className={ticket.status === 'won' ? 'ticket-return' : undefined}>{formatMoney(returned)}</dd>
        </div>
      </dl>
    </li>
  );
}
