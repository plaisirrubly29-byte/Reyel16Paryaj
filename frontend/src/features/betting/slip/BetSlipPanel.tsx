import { useId, useRef } from 'react';
import { Link } from 'react-router-dom';
import { Alert } from '../../../shared/components/Alert';
import { Icon } from '../../../shared/components/Icon';
import { useMediaQuery } from '../../../shared/hooks/useMediaQuery';
import { useMe } from '../../auth/api/auth';
import { formatKickoff } from '../../catalogue/format';
import { MAX_SELECTIONS, useBetSlip } from './BetSlipContext';

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

  // Après une suppression, le bouton activé disparaît : on ramène le focus sur l'en-tête du coupon.
  function afterRemoval(action: () => void) {
    action();
    requestAnimationFrame(() => headingRef.current?.focus());
  }

  return (
    <aside
      className={`betslip${collapsible ? ' collapsible' : ''}${slip.panelOpen ? ' open' : ''}`}
      aria-label="Coupon"
      id="betslip"
    >
      <h2 ref={headingRef} className="betslip-toggle" tabIndex={-1}>
        <span className="betslip-title"><Icon name="coupon" size={20} />Coupon</span>
        <span className="betslip-head-right">
          <span className="betslip-count" aria-label={countLabel}>{count}</span>
          {collapsible && (
            <button type="button" className="betslip-close" aria-label="Fermer le coupon" onClick={() => slip.setPanelOpen(false)}>×</button>
          )}
        </span>
      </h2>

      <div className="betslip-body" id={bodyId}>
        {slip.full && (
          <Alert tone="warning">Coupon complet : {MAX_SELECTIONS} matchs au maximum. Retirez une sélection pour en ajouter une autre.</Alert>
        )}
        {count === 0 ? (
          <div className="betslip-empty">
            <span className="betslip-empty-icon" aria-hidden="true"><Icon name="coupon" size={26} /></span>
            <strong>Votre coupon est vide</strong>
            <p className="muted small">Ajoutez des paris en touchant les cotes.</p>
          </div>
        ) : (
          <>
            <ul className="betslip-list">
              {slip.selections.map((s) => {
                const { day, time } = formatKickoff(s.startsAt);
                return (
                  <li key={s.selectionId}>
                    <div className="betslip-pick">
                      <strong>{s.label} · {s.pick}</strong>
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
            <button type="button" className="link-button small" onClick={() => afterRemoval(slip.clear)}>Vider le coupon</button>
            <div className="betslip-footer">
              <p className="muted small">La mise et la validation du pari arrivent bientôt. La cote sera revérifiée au moment du pari.</p>
              {!user && <Link to="/login" className="btn btn-primary btn-block">Se connecter pour parier</Link>}
              {user && <button type="button" className="btn btn-primary btn-block" disabled>Placer le pari — bientôt</button>}
            </div>
          </>
        )}
      </div>
    </aside>
  );
}
