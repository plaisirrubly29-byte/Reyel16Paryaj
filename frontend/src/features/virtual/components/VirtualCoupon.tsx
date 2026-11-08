import { useState } from 'react';
import { Icon } from '../../../shared/components/Icon';
import { MoneyText } from '../../../shared/components/MoneyText';
import type { VirtualEvent, VirtualMarket, VirtualSelection } from '../types';
import { eventTitle } from '../format';

/** Devise de la plateforme (décision Phase 0) ; le serveur restera maître du montant débité et du gain. */
const CURRENCY = 'HTG';

export type VirtualPick = { market: VirtualMarket; selection: VirtualSelection };

type Props = {
  event: VirtualEvent;
  pick: VirtualPick | null;
  open: boolean;
  onClear: () => void;
};

/**
 * Coupon d'une épreuve virtuelle : un choix (pari simple) et la mise. Le gain affiché est indicatif (mise × cote) ;
 * le vrai montant viendra du serveur. Démo : l'envoi est désactivé. Avec l'API, l'envoi portera une clé
 * d'idempotence (newIdempotencyKey), comme les paris sportifs et la borlette.
 */
export function VirtualCoupon({ event, pick, open, onClear }: Props) {
  const [stake, setStake] = useState('');
  const amount = Number(stake) || 0;
  const gainMinor = pick ? Math.round(amount * 100 * Number(pick.selection.odds)) : 0;

  return (
    <section className="virtual-coupon" aria-labelledby="virtual-coupon-title">
      <h2 id="virtual-coupon-title"><Icon name="coupon" size={20} /> Coupon</h2>

      {!pick ? (
        <p className="virtual-empty">Touchez une cote pour l&apos;ajouter au coupon.</p>
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
        <input inputMode="numeric" autoComplete="off" placeholder="50" value={stake} onChange={(e) => setStake(e.target.value.replace(/\D/g, ''))} />
      </label>

      <div className="lottery-total">
        <span>Gain potentiel <small>(indicatif)</small></span>
        <strong><MoneyText value={{ minor: gainMinor, currency: CURRENCY }} /></strong>
      </div>

      <button type="button" className="btn btn-primary btn-block" disabled>Placer le pari</button>
      <p className="lottery-soon">
        {open
          ? 'Démonstration : les paris sur les jeux virtuels ne sont pas encore ouverts.'
          : 'Mises fermées pour cette épreuve.'}
      </p>
    </section>
  );
}
