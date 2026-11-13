import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { formatMoney } from '../../../shared/format/money';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon, type IconName } from '../../../shared/components/Icon';
import { Pagination } from '../../../shared/components/Pagination';
import { Spinner } from '../../../shared/components/Spinner';
import { sportIcon } from '../../../shared/components/sportIcon';
import { formatKickoff } from '../../catalogue/format';
import { useBets, type BetFilters, type BetSelection, type BetTicket, type BetTicketStatus } from '../api/bets';
import { useBetSlip } from '../slip/BetSlipContext';
import { BetCodeShare } from '../tickets/BetCodeShare';
import { canRebet, rebetSelections } from '../tickets/rebet';
import { virtualGamesEnabled } from '../../virtual/api/virtualGames';
import { VirtualBets } from '../../virtual/components/VirtualBets';

/** Statut d'un ticket vu par le joueur (« En cours » tant qu'il n'est pas réglé). */
const STATUS_LABELS: Record<BetTicketStatus, string> = { pending: 'En cours', won: 'Gagné', lost: 'Perdu', void: 'Remboursé' };

const STATUS_FILTERS: { value: BetTicketStatus | null; label: string }[] = [
  { value: null, label: 'Tout' },
  { value: 'pending', label: 'En cours' },
  { value: 'won', label: 'Gagné' },
  { value: 'lost', label: 'Perdu' },
];

const PERIODS = [
  { value: 1, label: "Aujourd'hui" },
  { value: 7, label: '7 derniers jours' },
  { value: 30, label: '30 derniers jours' },
  { value: 90, label: '90 derniers jours' },
];
const DEFAULT_DAYS = 30;

const SELECTION_ICONS: Record<BetTicketStatus, { icon: IconName; label: string }> = {
  pending: { icon: 'clock', label: 'En cours' },
  won: { icon: 'trophy', label: 'Gagnée' },
  lost: { icon: 'close', label: 'Perdue' },
  void: { icon: 'refresh', label: 'Remboursée' },
};

/** Sélections affichées avant « Voir plus ». */
const VISIBLE_SELECTIONS = 3;

function isStatus(value: string | null): value is BetTicketStatus {
  return value === 'pending' || value === 'won' || value === 'lost' || value === 'void';
}

/** MES PARIS : les tickets du joueur connecté, filtrés par le serveur (page protégée par RequireAuth). */
export function MyBetsPage() {
  const [params, setParams] = useSearchParams();
  const page = Math.max(1, Number(params.get('page')) || 1);
  const product = params.get('produit') === 'virtuels' && virtualGamesEnabled() ? 'virtual' : 'sport';
  const rawStatus = params.get('statut');
  const status = isStatus(rawStatus) ? rawStatus : undefined;
  const rawType = params.get('type');
  const type = rawType === 'single' || rawType === 'multiple' ? rawType : undefined;
  const days = PERIODS.some((p) => String(p.value) === params.get('jours')) ? Number(params.get('jours')) : DEFAULT_DAYS;
  const filters: BetFilters = { status, type, days };

  const bets = useBets(page, filters);
  const tickets = bets.data?.data ?? [];
  const filtered = status !== undefined || type !== undefined || days !== DEFAULT_DAYS;

  /** Change un filtre et revient à la première page. */
  function update(changes: Record<string, string | null>) {
    const next = new URLSearchParams(params);
    for (const [key, value] of Object.entries(changes)) {
      if (value === null) next.delete(key);
      else next.set(key, value);
    }
    next.delete('page');
    setParams(next);
  }

  return (
    <section className="stack my-bets">
      <h1 className="my-bets-title"><Icon name="trophy" size={22} /> MES PARIS</h1>

      <nav className="my-bets-products" aria-label="Produits">
        <button type="button" className={product === 'sport' ? 'active' : undefined} aria-pressed={product === 'sport'} onClick={() => update({ produit: null })}>
          <Icon name="trophy" size={20} /> Sport
        </button>
        {virtualGamesEnabled() && (
          <button type="button" className={product === 'virtual' ? 'active' : undefined} aria-pressed={product === 'virtual'} onClick={() => update({ produit: 'virtuels' })}>
            <Icon name="gamepad" size={20} /> Jeux virtuels
          </button>
        )}
      </nav>

      {product === 'virtual' ? <VirtualBets title="Jeux virtuels" /> : (
        <>
          <div className="my-bets-filters" role="group" aria-label="Statut des paris">
            {STATUS_FILTERS.map((f) => {
              const active = status === (f.value ?? undefined);
              return (
                <button key={f.label} type="button" className={`status-chip${active ? ' active' : ''}`} aria-pressed={active}
                  onClick={() => update({ statut: f.value })}>
                  {f.label}
                </button>
              );
            })}
          </div>

          <div className="my-bets-selects">
            <label className="sr-only" htmlFor="bets-type">Type de pari</label>
            <select id="bets-type" value={type ?? ''} onChange={(e) => update({ type: e.target.value || null })}>
              <option value="">Tous</option>
              <option value="single">Simples</option>
              <option value="multiple">Multiples</option>
            </select>
            <label className="sr-only" htmlFor="bets-period">Période</label>
            <select id="bets-period" value={days} onChange={(e) => update({ jours: Number(e.target.value) === DEFAULT_DAYS ? null : e.target.value })}>
              {PERIODS.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
            </select>
          </div>

          {bets.isPending && <Spinner />}
          <ErrorAlert error={bets.error} onRetry={() => bets.refetch()} />

          {bets.isSuccess && tickets.length === 0 ? (
            filtered ? (
              <div className="empty-state">
                <span className="empty-icon" aria-hidden="true"><Icon name="coupon" size={26} /></span>
                <strong>Aucun pari pour ces critères</strong>
                <button type="button" className="btn btn-secondary btn-sm" onClick={() => setParams({})}>Voir tous mes paris</button>
              </div>
            ) : (
              <div className="empty-state">
                <span className="empty-icon" aria-hidden="true"><Icon name="coupon" size={26} /></span>
                <strong>Vous n'avez encore aucun pari</strong>
                <span>Choisissez vos cotes, saisissez une mise et placez votre premier pari.</span>
                <Link to="/sports" className="btn btn-primary btn-sm">Voir les matchs</Link>
              </div>
            )
          ) : (
            <ul className="ticket-list">
              {tickets.map((t) => <TicketCard key={t.id} ticket={t} />)}
            </ul>
          )}

          <Pagination meta={bets.data?.meta} onPage={(next) => {
            const p = new URLSearchParams(params);
            p.set('page', String(next));
            setParams(p);
            window.scrollTo({ top: 0 });
          }} />
        </>
      )}
    </section>
  );
}

/** « Aujourd'hui - 11:42:45 », « Hier - … » ou la date. */
function formatPlacedAt(iso: string, now = new Date()): string {
  const date = new Date(iso);
  const startOfDay = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
  const days = Math.round((startOfDay(now) - startOfDay(date)) / 86_400_000);
  const day = days === 0 ? "Aujourd'hui" : days === 1 ? 'Hier' : date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
  return `${day} - ${date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}`;
}

function SelectionRow({ s }: { s: BetSelection }) {
  const { day, time } = formatKickoff(s.event.starts_at);
  const where = [s.event.competition?.country, s.event.competition?.name].filter(Boolean).join(' - ');
  const pick = s.selection.label === s.selection.name ? s.selection.label : `${s.selection.label} (${s.selection.name})`;
  const state = SELECTION_ICONS[s.status];
  const finished = s.event.status === 'finished';

  return (
    <li className={`ticket-selection selection-${s.status}`}>
      <div className="ticket-selection-main">
        <strong className="ticket-event">
          <Icon name={sportIcon(s.event.sport ?? '')} size={16} /> {s.event.name}
        </strong>
        <span className="muted small">{day} {time}</span>
        {where && <span className="muted small">{where}</span>}
        {s.event.score && <span className="muted small">{finished ? 'Score final' : 'Score'} : {s.event.score.home} - {s.event.score.away}</span>}
        <span className="ticket-pick">{s.market.name} : {pick}</span>
      </div>
      <div className="ticket-selection-side">
        <span className={`selection-state state-${s.status}`} title={state.label} role="img" aria-label={state.label}>
          <Icon name={state.icon} size={14} />
        </span>
        <span className="odds-value">{s.odds}</span>
      </div>
    </li>
  );
}

/** Carte d'un ticket : statut, sélections, cote totale, mise, gain (potentiel ou versé), rejouer et partager. */
export function TicketCard({ ticket }: { ticket: BetTicket }) {
  const slip = useBetSlip();
  const [expanded, setExpanded] = useState(false);
  const [sharing, setSharing] = useState(false);
  const [rebetting, setRebetting] = useState(false);
  const [note, setNote] = useState<string | null>(null);

  const count = ticket.selections.length;
  const visible = expanded ? ticket.selections : ticket.selections.slice(0, VISIBLE_SELECTIONS);
  const title = ticket.type === 'multiple' ? `Sports - Multiple (${count})` : 'Sports - Simple';
  const returnLabel = { pending: 'Gains potentiels', won: 'Gain versé', lost: 'Gain', void: 'Remboursé' }[ticket.status];
  const returned = ticket.payout ?? (ticket.status === 'pending' ? ticket.potential_payout : { ...ticket.stake, minor: 0 });
  const rebettable = canRebet(ticket);

  async function rebet() {
    setRebetting(true);
    setNote(null);
    try {
      const { selections, skipped } = await rebetSelections(ticket);
      if (selections.length === 0) {
        setNote("Plus aucune sélection de ce ticket n'est disponible.");
        return;
      }
      slip.replace(selections);
      slip.setPanelOpen(true);
      setNote(skipped === 0
        ? 'Coupon recomposé avec les cotes du moment.'
        : `Coupon recomposé avec les cotes du moment ; ${skipped} sélection${skipped > 1 ? 's' : ''} plus disponible${skipped > 1 ? 's' : ''}.`);
    } finally {
      setRebetting(false);
    }
  }

  return (
    <li className="ticket-card">
      <header className="ticket-head">
        <div className="ticket-head-text">
          <strong className="ticket-title">{title}</strong>
          <span className="muted small">{formatPlacedAt(ticket.placed_at)} | Réf. <span className="mono">{ticket.reference}</span></span>
        </div>
        <span className={`ticket-status status-${ticket.status}`}>{STATUS_LABELS[ticket.status]}</span>
      </header>

      <ul className="ticket-selections">
        {visible.map((s) => <SelectionRow key={s.id} s={s} />)}
      </ul>
      {count > VISIBLE_SELECTIONS && (
        <button type="button" className="ticket-more" aria-expanded={expanded} onClick={() => setExpanded((v) => !v)}>
          {expanded ? 'Voir moins −' : `Voir plus + (${count - VISIBLE_SELECTIONS})`}
        </button>
      )}

      <dl className="ticket-summary">
        <div><dt>Cote totale</dt><dd>{ticket.total_odds}</dd></div>
        <div><dt>Mise</dt><dd>{formatMoney(ticket.stake)}</dd></div>
        <div className="ticket-summary-return">
          <dt>{returnLabel}</dt>
          <dd className={ticket.status === 'won' ? 'ticket-return' : undefined}>{formatMoney(returned)}</dd>
        </div>
      </dl>

      <div className="ticket-actions">
        <button type="button" className="btn btn-secondary" onClick={rebet} disabled={rebetting || !rebettable}
          title={rebettable ? 'Recomposer ce coupon avec les cotes du moment' : 'Les matchs de ce ticket ont commencé'}>
          {rebetting ? <Spinner size="sm" /> : <Icon name="refresh" size={16} />}<span>Rejouer</span>
        </button>
        <button type="button" className="btn btn-secondary" aria-expanded={sharing} onClick={() => setSharing((v) => !v)}>
          <Icon name="external" size={16} /><span>Partager</span>
        </button>
      </div>
      {note && <p className="muted small" role="status">{note}</p>}
      {sharing && <BetCodeShare code={ticket.reference} summary={`Cote ${ticket.total_odds} · Mise ${formatMoney(ticket.stake)}`} />}
    </li>
  );
}
