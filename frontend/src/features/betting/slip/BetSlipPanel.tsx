import { useId, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Alert } from '../../../shared/components/Alert';
import { useMediaQuery } from '../../../shared/hooks/useMediaQuery';
import { useMe } from '../../auth/api/auth';
import { formatKickoff } from '../../catalogue/format';
import { MAX_SELECTIONS, useBetSlip } from './BetSlipContext';

/** Point de rupture du coupon en barre repliable (doit correspondre à index.css). */
export const SLIP_COLLAPSE_QUERY = '(max-width: 960px)';

/**
 * Coupon : colonne à droite sur ordinateur, barre repliable en bas sur mobile.
 * La validation du pari (mise, revalidation de la cote par le serveur) arrive avec P4-03 / P4-05.
 */
export function BetSlipPanel() {
  const slip = useBetSlip();
  const { data: user } = useMe();
  const collapsible = useMediaQuery(SLIP_COLLAPSE_QUERY);
  const [open, setOpen] = useState(false);
  const bodyId = useId();
  const headingRef = useRef<HTMLElement>(null);
  const count = slip.selections.length;
  const countLabel = `${count} sélection${count > 1 ? 's' : ''}`;

  // Après une suppression, le bouton activé disparaît : on ramène le focus sur l'en-tête du coupon.
  function afterRemoval(action: () => void) {
    action();
    requestAnimationFrame(() => headingRef.current?.focus());
  }

  const counter = <span className="betslip-count" aria-label={countLabel}>{count}</span>;

  return (
    <aside className={`betslip${collapsible ? ' collapsible' : ''}${open ? ' open' : ''}`} aria-label="Coupon">
      {collapsible ? (
        <button
          ref={(el) => { headingRef.current = el; }}
          type="button"
          className="betslip-toggle"
          aria-expanded={open}
          aria-controls={bodyId}
          onClick={() => setOpen((o) => !o)}
        >
          <span>Coupon</span>
          {counter}
        </button>
      ) : (
        <h2 ref={(el) => { headingRef.current = el; }} className="betslip-toggle" tabIndex={-1}>
          <span>Coupon</span>
          {counter}
        </h2>
      )}

      <div className="betslip-body" id={bodyId}>
        {slip.full && (
          <Alert tone="warning">Coupon complet : {MAX_SELECTIONS} matchs au maximum. Retirez une sélection pour en ajouter une autre.</Alert>
        )}
        {count === 0 ? (
          <p className="muted small">Cliquez sur une cote pour l'ajouter à votre coupon.</p>
        ) : (
          <>
            <ul className="betslip-list">
              {slip.selections.map((s) => {
                const { day, time } = formatKickoff(s.startsAt);
                return (
                  <li key={s.selectionId}>
                    <div>
                      <strong>{s.label} · {s.pick}</strong>
                      <div className="muted small">{s.match}</div>
                      <div className="muted small">{day} {time}</div>
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
            <button type="button" className="link-button small" onClick={() => afterRemoval(slip.clear)}>Vider le coupon</button>
            <div className="betslip-footer">
              <p className="muted small">
                La mise et la validation du pari arrivent bientôt. La cote sera revérifiée au moment du pari.
              </p>
              {!user && <Link to="/login" className="btn btn-primary btn-block">Se connecter pour parier</Link>}
              {user && <button type="button" className="btn btn-primary btn-block" disabled>Placer le pari — bientôt</button>}
            </div>
          </>
        )}
      </div>
    </aside>
  );
}
