import { EVENT_STATUS_LABELS, MARKET_STATUS_LABELS, type EventStatus, type MarketStatus } from '../api/events';

const MARKET_TONE: Record<MarketStatus, string> = {
  open: 'status-active',
  suspended: 'status-suspended',
  closed: 'status-closed',
  settled: '',
};

/** Statut d'un marché : libellé toujours écrit (jamais la couleur seule). */
export function MarketStatusBadge({ status }: { status: MarketStatus }) {
  return <span className={['badge', MARKET_TONE[status]].filter(Boolean).join(' ')}>{MARKET_STATUS_LABELS[status]}</span>;
}

export function EventStatusBadge({ status }: { status: EventStatus }) {
  return <span className={['badge', status === 'live' && 'status-active', status === 'cancelled' && 'status-closed'].filter(Boolean).join(' ')}>{EVENT_STATUS_LABELS[status]}</span>;
}
