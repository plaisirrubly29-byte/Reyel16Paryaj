/**
 * Saisie de la mise en gourdes : chiffres et un séparateur décimal (point ou virgule), 2 décimales au plus.
 * `sanitizeStake` nettoie la saisie au fil de la frappe ; `parseStakeMinor` la convertit en centimes.
 */
export function sanitizeStake(value: string): string {
  const cleaned = value.replace(/[^\d.,]/g, '').replace(',', '.');
  const [int = '', ...rest] = cleaned.split('.');
  if (rest.length === 0) return int;

  return `${int}.${rest.join('').slice(0, 2)}`;
}

/** Mise en centimes (ex. « 100.5 » → 10050n), ou null si elle est vide, invalide ou nulle. */
export function parseStakeMinor(value: string): bigint | null {
  if (!/^\d+(\.\d{1,2})?$/.test(value)) return null;

  const [int = '', frac = ''] = value.split('.');
  const minor = BigInt(int + frac.padEnd(2, '0'));

  return minor > 0n ? minor : null;
}
