import type { Money } from '../api/client';
import { formatMoney } from '../format/money';

type Props = { value: Money; signed?: boolean };

/** Montant fourni par l'API, affiché tel quel (crédits en vert, débits en rouge si signé). */
export function MoneyText({ value, signed = false }: Props) {
  const tone = signed ? (value.minor > 0 ? 'credit' : value.minor < 0 ? 'debit' : '') : '';

  return <span className={['money', tone].filter(Boolean).join(' ')}>{formatMoney(value, 'fr-FR', { signed })}</span>;
}
