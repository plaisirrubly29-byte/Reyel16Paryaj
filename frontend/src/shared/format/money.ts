import type { Money } from '../api/client';

/**
 * Formate un montant reçu de l'API (unité mineure) pour l'affichage.
 * Aucun calcul métier : le montant est fourni par le serveur, on ne fait que le présenter.
 * Le nombre de décimales vient de la devise (ISO 4217), pas d'une division codée en dur.
 */
export function formatMoney(money: Money, locale = 'fr-FR', options: { signed?: boolean } = {}): string {
  const formatter = new Intl.NumberFormat(locale, {
    style: 'currency',
    currency: money.currency,
    signDisplay: options.signed ? 'exceptZero' : 'auto',
  });
  const digits = formatter.resolvedOptions().maximumFractionDigits ?? 2;

  return formatter.format(money.minor / 10 ** digits);
}
