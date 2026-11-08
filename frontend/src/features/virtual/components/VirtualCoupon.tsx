import { useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useMe } from '../../auth/api/auth';
import { parseStakeMinor, sanitizeStake } from '../../betting/slip/stake';
import { Alert } from '../../../shared/components/Alert';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon } from '../../../shared/components/Icon';
import { MoneyText } from '../../../shared/components/MoneyText';
import { usePlaceVirtualBet } from '../api/virtualGames';
import { eventTitle } from '../format';
import type { VirtualEvent, VirtualMarket, VirtualSelection } from '../types';

/** Devise de la plateforme (décision Phase 0). */
const CURRENCY = 'HTG';

export type VirtualPick = { market: VirtualMarket; selection: VirtualSelection };

type Props = {
  event: VirtualEvent;
  pick: VirtualPick | null;
  open: boolean;
  onClear: () => void;
};

/**
 * Coupon d'une épreuve virtuelle : un choix (pari simple) et la mise. Le serveur revérifie tout (mises ouvertes,
 * cote, mise minimale) et renvoie la référence du pari ; le gain potentiel affiché avant l'envoi est mise × cote.
 */
export function VirtualCoupon({ event, pick, open, onClear }: Props) {
  const me = useMe();
  const location = useLocation();
  const place = usePlaceVirtualBet();
  const [stake, setStake] = useState('');
  const stakeMinor = parseStakeMinor(stake);
  const gainMinor = pick && stakeMinor ? Math.round(Number(stakeMinor) * Number(pick.selection.odds)) : 0;

  function submit() {
    if (!pick || !stakeMinor) return;
    place.mutate(
      { event_id: event.id, selection_id: pick.selection.id, stake_minor: Number(stakeMinor) },
      { onSuccess: () => { onClear(); setStake(''); } },
    );
  }

  return (
    <section className="virtual-coupon" aria-labelledby="virtual-coupon-title">
      <h2 id="virtual-coupon-title"><Icon name="coupon" size={20} /> Coupon</h2>

      {!pick ? (
        <p className="virtual-empty">{open ? 'Touchez une cote pour l\'ajouter au coupon.' : 'Mises fermées pour cette épreuve.'}</p>
      ) : (
        <div className="virtual-pick">
          <div>
            <span className="virtual-pick-market">{pick.market.name}</span>
            <strong className="virtual-pick-name">{pick.selection.name}</strong>
            <span className="virtual-pick-event">{eventTitle(event)}</span>
          </div>
          <span className="virtual-pick-odds odds-value">{pick.selection.odds}</span>
          <button type="button" className="lottery-play-remove" aria-label={`Retirer ${pick.selection.name}`} onClick={onClear}>
            <Icon name="trash" size={18} />
          </button>
        </div>
      )}

      <label className="lottery-stake">
        <span>Mise ({CURRENCY})</span>
        <input
          inputMode="decimal"
          autoComplete="off"
          placeholder="50"
          value={stake}
          onChange={(e) => { setStake(sanitizeStake(e.target.value)); place.reset(); }}
        />
      </label>

      <div className="lottery-total">
        <span>Gain potentiel</span>
        <strong><MoneyText value={{ minor: gainMinor, currency: CURRENCY }} /></strong>
      </div>

      {place.isSuccess && (
        <Alert tone="success" title="Pari accepté">
          <p>
            Référence <strong>{place.data.reference}</strong> · {place.data.selection.name} à {place.data.odds}.
            {' '}Résultat à la fin de l&apos;épreuve.
          </p>
        </Alert>
      )}
      <ErrorAlert error={place.error} />

      {!me.data ? (
        <Link to={`/login?redirect=${encodeURIComponent(location.pathname + location.search)}`} className="btn btn-primary btn-block">
          Se connecter pour parier
        </Link>
      ) : (
        <button
          type="button"
          className="btn btn-primary btn-block"
          disabled={!pick || !stakeMinor || !open || place.isPending}
          onClick={submit}
        >
          {place.isPending ? 'Envoi…' : 'Placer le pari'}
        </button>
      )}
    </section>
  );
}
