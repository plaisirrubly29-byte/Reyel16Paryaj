import { TICKET_STATUS_LABELS, type TicketStatus } from '../api/tickets';

/** Statut d'un ticket ou d'une sélection : pastille colorée + libellé (jamais la couleur seule). */
export function TicketStatusBadge({ status }: { status: TicketStatus }) {
  return (
    <span className={`badge ticket-status is-${status}`}>
      <span className={`status-dot is-${status}`} aria-hidden="true" />
      {TICKET_STATUS_LABELS[status]}
    </span>
  );
}
