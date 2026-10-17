import type { CatalogueMarket, CatalogueSelection } from './api/catalogue';

/**
 * Sélection favorite (cote la plus basse, unique) : simple repère visuel. Comparaison décimale exacte
 * sur les chaînes (« 1.55 » < « 2.10 »), sans conversion en nombre flottant.
 */
export function favoriteSelection(selections: CatalogueSelection[]): string | undefined {
  const key = (odds: string) => {
    const [int = '0', frac = ''] = odds.split('.');
    return int.padStart(6, '0') + frac.padEnd(3, '0');
  };
  const sorted = [...selections].sort((a, b) => key(a.odds).localeCompare(key(b.odds)));
  return sorted.length > 1 && key(sorted[0].odds) !== key(sorted[1].odds) ? sorted[0].id : undefined;
}

/** Nom court d'un marché pour le coupon : « Résultat du match (1X2) » → « Résultat du match ». */
export function marketTitle(market: CatalogueMarket): string {
  return market.name.replace(/\s*\(1X2\)$/, '');
}
