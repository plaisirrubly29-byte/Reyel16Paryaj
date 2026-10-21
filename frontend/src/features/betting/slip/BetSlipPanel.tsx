import { useEffect, useId, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Alert } from '../../../shared/components/Alert';
import { Icon } from '../../../shared/components/Icon';
import { TextField } from '../../../shared/components/TextField';
import { formatMoney } from '../../../shared/format/money';
import { useMediaQuery } from '../../../shared/hooks/useMediaQuery';
import { useMe } from '../../auth/api/auth';
import { formatKickoff } from '../../catalogue/format';
import { MAX_SELECTIONS, useBetSlip } from './BetSlipContext';
import { BetCodeShare } from '../tickets/BetCodeShare';
import { addTicket, generateBetCode, type Ticket } from '../tickets/ticketStore';
import { parseStakeMinor, sanitizeStake } from './stake';
import { combinedOdds, potentialReturnMinor } from './totalOdds';

/** Devise de la mise et des gains (gourdes haïtiennes, comme APP_CURRENCY côté serveur). */
const CURRENCY = 'HTG';

/** Point de rupture du coupon en panneau mobile (doit correspondre à index.css). */
export const SLIP_COLLAPSE_QUERY = '(max-width: 960px)';

/**
 * Coupon : colonne à droite sur ordinateur ; sur mobile, panneau ouvert depuis la barre d'onglets du bas.
 * La validation du pari (mise, revalidation de la cote par le serveur) arrive avec P4-03 / P4-05.
 */
export function BetSlipPanel() {
  const slip = useBetSlip();
  const { data: user } = useMe();
  const collapsible = useMediaQuery(SLIP_COLLAPSE_QUERY);
  const bodyId = useId();
  const headingRef = useRef<HTMLHeadingElement>(null);
  const count = slip.selections.length;
  const countLabel = `${count} sélection${count > 1 ? 's' : ''}`;

  // Cote combinée et gain potentiel : recalculés à chaque sélection ou changement de mise.
  const odds = slip.selections.map((s) => s.odds);
  const combined = combinedOdds(odds);
  const stakeMinor = parseStakeMinor(slip.stake);
  const potential = combined !== null && stakeMinor !== null ? potentialReturnMinor(stakeMinor, odds) : null;
  const canPlace = user !== null && user !== undefined && count > 0 && combined !== null && stakeMinor !== null && potential !== null;

  // Ticket venant d'être placé : affiché à la place du coupon vide, avec le betcode à partager.
  const [placed, setPlaced] = useState<Ticket | null>(null);
  // Dès qu'un nouveau pari est commencé, la confirmation précédente disparaît.
  useEffect(() => {
    if (count > 0) setPlaced(null);
  }, [count]);

  // Après une suppression, le bouton activé disparaît : on ramène le focus sur l'en-tête du coupon.
  function afterRemoval(action: () => void) {
    action();
    requestAnimationFrame(() => headingRef.current?.focus());
  }

  function placeBet() {
    if (!user || combined === null || stakeMinor === null || potential === null) return;

    const ticket: Ticket = {
      code: generateBetCode(),
      placedAt: new Date().toISOString(),
      stakeMinor: Number(stakeMinor),
      potentialMinor: Number(potential),
      combinedOdds: combined,
      selections: slip.selections.map(({ match, pick, label, odds: o, market, startsAt }) => ({
        match, pick, label, odds: o, market, startsAt,
      })),
    };
    addTicket(user.id, ticket);
    setPlaced(ticket);
    slip.clear();
    slip.setStake('');
  }

  return (
    <aside
      className={`betslip${collapsible ? ' collapsible' : ''}${slip.panelOpen ? ' open' : ''}`}
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
          {collapsible && (
            <button type="button" className="betslip-close" aria-label="Fermer le coupon" onClick={() => slip.setPanelOpen(false)}>×</button>
          )}
        </span>
      </div>
      {/* Type de pari, déduit du nombre de sélections : une seule = simple, plusieurs = combiné (multiple). */}
      <div className="betslip-mode" aria-label={count > 1 ? 'Pari multiple' : 'Pari simple'}>
        <span className={count <= 1 ? 'active' : undefined}>Simple</span>
        <span className={count > 1 ? 'active' : undefined}>Multiple</span>
      </div>

      <div className="betslip-body" id={bodyId}>
        {slip.full && (
          <Alert tone="warning">Coupon complet : {MAX_SELECTIONS} sélections au maximum. Retirez une sélection pour en ajouter une autre.</Alert>
        )}
        {count === 0 && placed ? (
          <div className="betslip-placed" role="status">
            <span className="betslip-empty-icon" aria-hidden="true"><Icon name="check" size={26} /></span>
            <strong>Pari placé</strong>
            <p className="muted small">Votre ticket est enregistré dans MES PARIS.</p>
            <BetCodeShare code={placed.code} summary={`Cote ${placed.combinedOdds} · Mise ${formatMoney({ minor: placed.stakeMinor, currency: CURRENCY })}`} />
            <Link to="/bets" className="btn btn-secondary btn-sm">Voir mes paris</Link>
          </div>
        ) : count === 0 ? (
          <div className="betslip-empty">
            <span className="betslip-empty-icon" aria-hidden="true"><Icon name="coupon" size={40} /></span>
            <strong>Votre panier est vide</strong>
            <p className="small">Ajoutez vos paris en cliquant sur les cotes.</p>
          </div>
        ) : (
          <>
            <ul className="betslip-list">
              {slip.selections.map((s) => {
                const { day, time } = formatKickoff(s.startsAt);
                return (
                  <li key={s.selectionId}>
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
                  </li>
                );
              })}
            </ul>
            <div className="betslip-summary" aria-live="polite">
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
                <strong className="betslip-return">
                  {potential !== null ? formatMoney({ minor: Number(potential), currency: CURRENCY }) : '—'}
                </strong>
              </div>
            </div>
            <div className="betslip-footer">
              <p className="muted small">Pari de démonstration : le ticket est simulé et aucun argent réel n'est engagé.</p>
              {!user && <Link to="/login" className="btn btn-green btn-block">Se connecter pour parier</Link>}
              {user && (
                <button
                  type="button"
                  className="btn btn-green btn-block place-bet"
                  disabled={!canPlace}
                  onClick={placeBet}
                >
                  Placer mon pari
                </button>
              )}
              {user && !canPlace && <p className="muted small place-hint">Saisissez une mise pour placer votre pari.</p>}
            </div>
          </>
        )}
      </div>
      {count === 0 && !placed && (
        <div className="betslip-footer betslip-footer-empty">
          <button type="button" className="btn btn-green btn-block place-bet" disabled>Placer mon pari</button>
        </div>
      )}
    </aside>
  );
}
