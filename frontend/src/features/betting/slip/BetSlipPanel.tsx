import { useEffect, useId, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Alert } from '../../../shared/components/Alert';
import { Icon } from '../../../shared/components/Icon';
import { TextField } from '../../../shared/components/TextField';
import { formatMoney } from '../../../shared/format/money';
import { useMe } from '../../auth/api/auth';
import { formatKickoff } from '../../catalogue/format';
import { ApiError } from '../../../shared/api/client';
import { MAX_SELECTIONS, useBetSlip, type BetMode, type SlipSelection } from './BetSlipContext';
import { BetCodeShare } from '../tickets/BetCodeShare';
import { usePlaceBet, type BetTicket, type OddsChange } from '../api/bets';
import { parseStakeMinor, sanitizeStake } from './stake';
import { combinedOdds, potentialReturnMinor } from './totalOdds';

/** Devise de la mise et des gains (gourdes haïtiennes, comme APP_CURRENCY côté serveur). */
const CURRENCY = 'HTG';

/** Point de rupture du coupon en panneau mobile (doit correspondre à index.css). */
export const SLIP_COLLAPSE_QUERY = '(max-width: 960px)';

type Notice = { tone: 'success' | 'warning' | 'error'; text: string };

const money = (minor: bigint) => formatMoney({ minor: Number(minor), currency: CURRENCY });

/** Message affichable pour un refus du serveur ; les cotes changées ou sélections fermées sont aussi corrigées dans le coupon. */
function refusal(error: unknown): string {
  if (!(error instanceof ApiError)) return 'Le pari n\'a pas pu être placé. Réessayez.';
  if (error.code === 'stake_out_of_range') {
    const { min, max } = error.details as { min?: { minor: number; currency: string }; max?: { minor: number; currency: string } };
    return min && max ? `La mise doit être comprise entre ${formatMoney(min)} et ${formatMoney(max)}.` : error.message;
  }
  if (error.code === 'insufficient_funds') return 'Solde insuffisant pour cette mise.';
  if (error.status === 0) return 'Serveur injoignable : le pari n\'a pas été placé.';
  return error.message;
}

/** Sélections d'un même match : un combiné n'en accepte qu'une (le serveur refuse same_event_selections). */
function sameEventIds(selections: SlipSelection[]): Set<string> {
  const byEvent = new Map<string, number>();
  for (const s of selections) byEvent.set(s.eventId, (byEvent.get(s.eventId) ?? 0) + 1);
  return new Set(selections.filter((s) => (byEvent.get(s.eventId) ?? 0) > 1).map((s) => s.selectionId));
}

/**
 * Coupon, rendu une fois pour tout le site (PublicLayout) et fermé par défaut : sur ordinateur, panneau à droite ouvert
 * par le bouton flottant « Panier » ; sur mobile, panneau ouvert depuis la barre d'onglets du bas.
 *
 * - Simple : un ticket par sélection, chacun avec sa mise. Plusieurs simples partent l'un après l'autre (un POST /bets
 *   et une clé d'idempotence chacun) : certains peuvent être acceptés et d'autres refusés, le coupon le montre.
 * - Multiple : un seul ticket combiné (cote totale = produit des cotes), une mise, une sélection par match.
 *
 * Le serveur revalide cotes, disponibilité, limites et solde, débite la mise et renvoie le reçu. Une cote changée est
 * mise à jour dans le coupon et doit être confirmée.
 */
export function BetSlipPanel() {
  const slip = useBetSlip();
  const { data: user } = useMe();
  const bodyId = useId();
  const headingRef = useRef<HTMLHeadingElement>(null);
  const count = slip.selections.length;
  const countLabel = `${count} sélection${count > 1 ? 's' : ''}`;
  // Plusieurs paris simples : une mise par sélection. Une seule sélection : la mise du résumé, comme un combiné.
  const singles = slip.mode === 'simple' && count > 1;

  // Combiné (ou pari simple unique) : cote combinée et gain potentiel, recalculés à chaque changement.
  const odds = slip.selections.map((s) => s.odds);
  const combined = combinedOdds(odds);
  const stakeMinor = parseStakeMinor(slip.stake);
  const potential = combined !== null && stakeMinor !== null ? potentialReturnMinor(stakeMinor, odds) : null;
  const conflicts = slip.mode === 'multiple' ? sameEventIds(slip.selections) : new Set<string>();

  // Paris simples : mise et gain de chaque sélection, puis les totaux.
  const singleStakes = slip.selections.map((s) => parseStakeMinor(slip.stakes[s.selectionId] ?? ''));
  const allSinglesStaked = singleStakes.every((m) => m !== null);
  const totalStake = singleStakes.reduce<bigint>((sum, m) => sum + (m ?? 0n), 0n);
  const totalReturn = slip.selections.reduce<bigint>((sum, s, i) => {
    const minor = singleStakes[i];
    return minor === null ? sum : sum + potentialReturnMinor(minor, [s.odds]);
  }, 0n);

  const placeBetMutation = usePlaceBet();
  const [placing, setPlacing] = useState(false);
  const busy = placing || placeBetMutation.isPending;
  const canPlace = user !== null && user !== undefined && count > 0 && !busy
    && (singles ? allSinglesStaked : combined !== null && stakeMinor !== null && potential !== null && conflicts.size === 0);

  // Tickets venant d'être placés : affichés à la place du coupon vide, avec le betcode à partager.
  const [placed, setPlaced] = useState<BetTicket[]>([]);
  const [notice, setNotice] = useState<Notice | null>(null);
  // Refus du serveur par sélection (paris simples) : affiché sous la sélection restée dans le coupon.
  const [rowErrors, setRowErrors] = useState<Record<string, string>>({});
  const [sameStake, setSameStake] = useState('');
  // Dès qu'une sélection est ajoutée (nouveau pari), la confirmation précédente disparaît. Pas quand les paris
  // simples acceptés quittent le coupon : la confirmation reste au-dessus des sélections refusées.
  const previousCount = useRef(count);
  useEffect(() => {
    if (count > previousCount.current) {
      setPlaced([]);
      setRowErrors({});
    }
    previousCount.current = count;
  }, [count]);

  // Après une suppression, le bouton activé disparaît : on ramène le focus sur l'en-tête du coupon.
  function afterRemoval(action: () => void) {
    action();
    requestAnimationFrame(() => headingRef.current?.focus());
  }

  function chooseMode(mode: BetMode) {
    slip.setMode(mode);
    setNotice(null);
    setRowErrors({});
  }

  /** Cote changée ou sélection fermée : le coupon est corrigé ; renvoie le message à afficher. */
  function absorb(error: unknown): string {
    if (error instanceof ApiError && error.code === 'odds_changed') {
      const changes = ((error.details.selections ?? []) as OddsChange[])
        .map((c) => ({ selectionId: c.selection_id, odds: c.odds, oddsVersion: c.odds_version }));
      slip.updateOdds(changes);
      return 'La cote a changé. Vérifiez la nouvelle cote, puis confirmez votre pari.';
    }
    if (error instanceof ApiError && error.code === 'selection_unavailable') {
      slip.removeMany((error.details.selection_ids ?? []) as string[]);
      return 'Une ou plusieurs sélections ne sont plus proposées et ont été retirées du coupon.';
    }
    return refusal(error);
  }

  function placeCombined() {
    if (!user || combined === null || stakeMinor === null || potential === null) return;

    setNotice(null);
    placeBetMutation.mutate(
      {
        stakeMinor: Number(stakeMinor),
        currency: CURRENCY,
        selections: slip.selections.map((s) => ({ selectionId: s.selectionId, odds: s.odds })),
      },
      {
        onSuccess: (ticket) => {
          setPlaced([ticket]);
          slip.clear();
          slip.setStake('');
        },
        onError: (error) => {
          const unavailable = error instanceof ApiError && (error.code === 'odds_changed' || error.code === 'selection_unavailable');
          setNotice({ tone: unavailable ? 'warning' : 'error', text: absorb(error) });
        },
      },
    );
  }

  /** Paris simples : un ticket par sélection, envoyés l'un après l'autre. Les sélections acceptées quittent le coupon. */
  async function placeSingles() {
    if (!user || !allSinglesStaked) return;

    setNotice(null);
    setRowErrors({});
    setPlacing(true);
    const tickets: BetTicket[] = [];
    const errors: Record<string, string> = {};
    const accepted: string[] = [];
    for (const [i, s] of slip.selections.entries()) {
      try {
        tickets.push(await placeBetMutation.mutateAsync({
          stakeMinor: Number(singleStakes[i]),
          currency: CURRENCY,
          selections: [{ selectionId: s.selectionId, odds: s.odds }],
        }));
        accepted.push(s.selectionId);
      } catch (error) {
        errors[s.selectionId] = absorb(error);
      }
    }
    setPlacing(false);

    slip.removeMany(accepted);
    setPlaced(tickets);
    setRowErrors(errors);
    const refused = Object.keys(errors).length;
    if (refused > 0) {
      setNotice(tickets.length > 0
        ? { tone: 'warning', text: `${tickets.length} pari${tickets.length > 1 ? 's' : ''} placé${tickets.length > 1 ? 's' : ''} sur ${tickets.length + refused}. Les sélections refusées restent dans le coupon.` }
        : { tone: 'error', text: refused > 1 ? 'Aucun pari n\'a été placé : voir le motif sous chaque sélection.' : 'Le pari n\'a pas été placé : voir le motif sous la sélection.' });
    }
  }

  const placeLabel = busy ? 'Validation…' : singles ? `Placer ${count} paris` : 'Placer mon pari';

  return (
    <aside
      className={`betslip${slip.panelOpen ? ' open' : ''}`}
      aria-label="Coupon"
      id="betslip"
    >
      <div className="betslip-head">
        <h2 ref={headingRef} className="betslip-toggle" tabIndex={-1}>
          <span className="betslip-title"><Icon name="cart" size={26} />Panier</span>
          <span className="betslip-count" aria-label={countLabel}>{count}</span>
        </h2>
        <span className="betslip-head-right">
          {count > 0 && (
            <button type="button" className="betslip-icon-btn" aria-label="Vider le coupon" title="Vider le coupon" onClick={() => afterRemoval(slip.clear)}>
              <Icon name="trash" size={22} />
            </button>
          )}
          <button type="button" className="betslip-close" aria-label="Fermer le coupon" onClick={() => slip.setPanelOpen(false)}>×</button>
        </span>
      </div>
      {/* Type de pari : simple (un ticket par sélection) ou multiple (un combiné, 2 sélections au moins). */}
      <div className="betslip-mode" role="group" aria-label="Type de pari">
        <button type="button" aria-pressed={slip.mode === 'simple'} className={slip.mode === 'simple' ? 'active' : undefined} disabled={busy} onClick={() => chooseMode('simple')}>
          Simple
        </button>
        <button
          type="button"
          aria-pressed={slip.mode === 'multiple'}
          className={slip.mode === 'multiple' ? 'active' : undefined}
          disabled={count < 2 || busy}
          title={count < 2 ? 'Ajoutez au moins 2 sélections pour un combiné' : undefined}
          onClick={() => chooseMode('multiple')}
        >
          Multiple
        </button>
      </div>

      <div className="betslip-body" id={bodyId}>
        {slip.full && (
          <Alert tone="warning">Coupon complet : {MAX_SELECTIONS} sélections au maximum. Retirez une sélection pour en ajouter une autre.</Alert>
        )}
        {/* Refus du serveur : visible même si toutes les sélections ont été retirées du coupon. */}
        {notice && (count > 0 || placed.length === 0) && <Alert tone={notice.tone}>{notice.text}</Alert>}
        {count > 0 && placed.length > 0 && <PlacedList tickets={placed} />}
        {count === 0 && placed.length > 0 ? (
          <Placed tickets={placed} />
        ) : count === 0 ? (
          <div className="betslip-empty">
            <span className="betslip-empty-icon" aria-hidden="true"><Icon name="coupon" size={40} /></span>
            <strong>Votre panier est vide</strong>
            <p className="small">Ajoutez vos paris en cliquant sur les cotes.</p>
          </div>
        ) : (
          <>
            {singles && (
              <TextField
                label="Même mise pour tous"
                inputMode="decimal"
                autoComplete="off"
                prefix={CURRENCY}
                placeholder="0.00"
                className="betslip-same-stake"
                value={sameStake}
                onChange={(e) => {
                  const value = sanitizeStake(e.target.value);
                  setSameStake(value);
                  slip.setAllStakes(value);
                }}
              />
            )}
            <ul className="betslip-list">
              {slip.selections.map((s, i) => {
                const { day, time } = formatKickoff(s.startsAt);
                const rowStake = singleStakes[i];
                const rowError = rowErrors[s.selectionId];
                return (
                  <li key={s.selectionId} className={conflicts.has(s.selectionId) || rowError ? 'has-issue' : undefined}>
                    <div className="betslip-pick">
                      <strong>{s.label === s.pick ? s.pick : `${s.label} · ${s.pick}`}</strong>
                      <div className="betslip-match">{s.match}</div>
                      <div className="muted small">{s.market ?? 'Résultat du match'} · {day} {time}</div>
                    </div>
                    <span className="odds-value">{s.odds}</span>
                    <button
                      type="button"
                      className="remove"
                      aria-label={`Retirer ${s.pick} (${s.match})`}
                      onClick={() => afterRemoval(() => slip.remove(s.selectionId))}
                    >
                      ×
                    </button>
                    {singles && (
                      <div className="betslip-row-stake">
                        <TextField
                          label={`Mise sur ${s.pick} (${s.match})`}
                          labelHidden
                          inputMode="decimal"
                          autoComplete="off"
                          prefix={CURRENCY}
                          placeholder="Mise"
                          value={slip.stakes[s.selectionId] ?? ''}
                          onChange={(e) => slip.setStakeFor(s.selectionId, sanitizeStake(e.target.value))}
                        />
                        <span className="betslip-row-return">
                          <span className="muted small">Gain</span>
                          <strong>{rowStake !== null ? money(potentialReturnMinor(rowStake, [s.odds])) : '—'}</strong>
                        </span>
                      </div>
                    )}
                    {conflicts.has(s.selectionId) && (
                      <p className="betslip-issue">Même match : un combiné n'accepte qu'une sélection par match.</p>
                    )}
                    {rowError && <p className="betslip-issue">{rowError}</p>}
                  </li>
                );
              })}
            </ul>
            <div className="betslip-summary" aria-live="polite">
              {singles ? (
                <>
                  <div className="betslip-row">
                    <span>Mise totale ({count} paris)</span>
                    <strong>{allSinglesStaked || totalStake > 0n ? money(totalStake) : '—'}</strong>
                  </div>
                  <div className="betslip-row">
                    <span>Gain potentiel total</span>
                    <strong className="betslip-return">{totalReturn > 0n ? money(totalReturn) : '—'}</strong>
                  </div>
                </>
              ) : (
                <>
                  <div className="betslip-row">
                    <span>Cote totale</span>
                    <strong className="betslip-combined">{combined}</strong>
                  </div>
                  <TextField
                    label="Montant de la mise"
                    inputMode="decimal"
                    name="stake"
                    autoComplete="off"
                    prefix={CURRENCY}
                    placeholder="0.00"
                    value={slip.stake}
                    onChange={(e) => slip.setStake(sanitizeStake(e.target.value))}
                  />
                  <div className="betslip-row">
                    <span>Gain potentiel</span>
                    <strong className="betslip-return">{potential !== null ? money(potential) : '—'}</strong>
                  </div>
                </>
              )}
            </div>
            <div className="betslip-footer">
              <p className="muted small">
                {singles ? 'Chaque mise est débitée de votre solde dès que son pari est accepté.' : 'La mise est débitée de votre solde dès que le pari est accepté.'}
              </p>
              {!user && <Link to="/login" className="btn btn-green btn-block">Se connecter pour parier</Link>}
              {user && (
                <button
                  type="button"
                  className="btn btn-green btn-block place-bet"
                  disabled={!canPlace}
                  aria-busy={busy || undefined}
                  onClick={singles ? () => void placeSingles() : placeCombined}
                >
                  {placeLabel}
                </button>
              )}
              {user && <PlaceHint singles={singles} allStaked={allSinglesStaked} stakeMissing={stakeMinor === null} conflicts={conflicts.size > 0} />}
            </div>
          </>
        )}
      </div>
      {count === 0 && placed.length === 0 && (
        <div className="betslip-footer betslip-footer-empty">
          <button type="button" className="btn btn-green btn-block place-bet" disabled>Placer mon pari</button>
        </div>
      )}
    </aside>
  );
}

/** Ce qui manque pour pouvoir parier. */
function PlaceHint({ singles, allStaked, stakeMissing, conflicts }: { singles: boolean; allStaked: boolean; stakeMissing: boolean; conflicts: boolean }) {
  const text = singles
    ? !allStaked && 'Saisissez une mise pour chaque sélection.'
    : conflicts ? 'Retirez une des sélections du même match, ou passez en Simple.'
      : stakeMissing && 'Saisissez une mise pour placer votre pari.';
  return text ? <p className="muted small place-hint">{text}</p> : null;
}

/** Coupon vidé après le pari : reçu du ticket (ou des tickets simples) avec le betcode à partager. */
function Placed({ tickets }: { tickets: BetTicket[] }) {
  const [first] = tickets;
  return (
    <div className="betslip-placed" role="status">
      <span className="betslip-empty-icon" aria-hidden="true"><Icon name="check" size={26} /></span>
      {tickets.length === 1 ? (
        <>
          <strong>Pari placé</strong>
          <p className="muted small">
            Mise de {formatMoney(first.stake)} débitée · gain potentiel {formatMoney(first.potential_payout)}.
          </p>
          <BetCodeShare code={first.reference} summary={`Cote ${first.total_odds} · Mise ${formatMoney(first.stake)}`} />
        </>
      ) : (
        <>
          <strong>{tickets.length} paris placés</strong>
          <TicketRefs tickets={tickets} />
        </>
      )}
      <Link to="/bets" className="btn btn-secondary btn-sm">Voir mes paris</Link>
    </div>
  );
}

/** Une partie des paris simples acceptée : rappel au-dessus des sélections refusées restées dans le coupon. */
function PlacedList({ tickets }: { tickets: BetTicket[] }) {
  return (
    <Alert tone="success" title={tickets.length > 1 ? `${tickets.length} paris placés` : 'Pari placé'}>
      <TicketRefs tickets={tickets} />
    </Alert>
  );
}

function TicketRefs({ tickets }: { tickets: BetTicket[] }) {
  return (
    <ul className="betslip-refs">
      {tickets.map((t) => (
        <li key={t.id}>
          <span>{t.selections[0]?.selection.name ?? 'Pari'} · <span className="mono">{t.reference}</span></span>
          <span className="muted small">Mise {formatMoney(t.stake)} · gain {formatMoney(t.potential_payout)}</span>
        </li>
      ))}
    </ul>
  );
}
