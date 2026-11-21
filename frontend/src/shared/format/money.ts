import type { Money } from '../api/client';

/**
 * Formate un montant reçu de l'API (unité mineure) pour l'affichage.
 * Aucun calcul métier : le montant est fourni par le serveur, on ne fait que le présenter.
 * Le nombre de décimales vient de la devise (ISO 4217), pas d'une division codée en dur.
 */
export function formatMoney(money: Money, locale = 'fr-FR', options: { signed?: boolean } = {}): string {
  const { formatter, digits } = formatterFor(locale, money.currency, options.signed === true);

  return formatter.format(money.minor / 10 ** digits);
}

/** Un formateur par langue, devise et signe, créé une seule fois (V2-E05) : en créer un par montant est coûteux. */
const formatters = new Map<string, { formatter: Intl.NumberFormat; digits: number }>();

function formatterFor(locale: string, currency: string, signed: boolean) {
  const key = `${locale}|${currency}|${signed}`;
  let entry = formatters.get(key);
  if (!entry) {
    const formatter = new Intl.NumberFormat(locale, { style: 'currency', currency, signDisplay: signed ? 'exceptZero' : 'auto' });
    entry = { formatter, digits: formatter.resolvedOptions().maximumFractionDigits ?? 2 };
    formatters.set(key, entry);
  }
  return entry;
}
