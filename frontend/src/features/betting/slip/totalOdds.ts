/**
 * Calculs du coupon en arithmétique exacte (entiers, jamais de nombre flottant) :
 * une cote « 2.10 » est lue comme 2100 millièmes, une mise comme un nombre de centimes.
 */

/** Cote décimale (jusqu'à 3 décimales) en millièmes : « 2.1 » → 2100n. */
function toMilli(odds: string): bigint {
  const [int = '0', frac = ''] = odds.split('.');
  return BigInt(int + frac.padEnd(3, '0').slice(0, 3));
}

/** Produit exact des cotes sous forme de fraction numerator / scale (scale = 1000^n). */
function product(odds: string[]): { numerator: bigint; scale: bigint } {
  let numerator = 1n;
  let scale = 1n;
  for (const value of odds) {
    numerator *= toMilli(value);
    scale *= 1000n;
  }
  return { numerator, scale };
}

/** Division arrondie au plus proche, moitié vers le haut (numérateur et diviseur positifs). */
function divRound(numerator: bigint, denominator: bigint): bigint {
  return (2n * numerator + denominator) / (2n * denominator);
}

/** Cote combinée d'un coupon à 2 décimales (« 6.30 »), ou null si le coupon est vide. */
export function combinedOdds(odds: string[]): string | null {
  if (odds.length === 0) return null;

  const { numerator, scale } = product(odds);
  const cents = divRound(numerator * 100n, scale);
  const whole = cents / 100n;
  const fraction = (cents % 100n).toString().padStart(2, '0');

  return `${whole}.${fraction}`;
}

/**
 * Gain potentiel (mise incluse) en centimes : mise × cote combinée, arrondi au centime.
 * `stakeMinor` est la mise en centimes (ex. 100 HTG → 10000n).
 */
export function potentialReturnMinor(stakeMinor: bigint, odds: string[]): bigint {
  const { numerator, scale } = product(odds);
  return divRound(stakeMinor * numerator, scale);
}
