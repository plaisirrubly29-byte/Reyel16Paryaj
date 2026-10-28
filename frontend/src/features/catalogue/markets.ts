import type { CatalogueMarket, CatalogueSelection } from './api/catalogue';

export type MarketCategory = 'Résultat' | 'Buts' | 'Score exact' | 'Joueurs' | 'Autres';

function normalizeMarketText(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

/** Classe les noms libres provenant du catalogue pour faciliter la navigation sur la page du match. */
export function marketCategory(market: CatalogueMarket): MarketCategory {
  const text = normalizeMarketText(`${market.key} ${market.name}`);

  if (/exact.?score|score.?exact/.test(text)) return 'Score exact';
  if (/player|joueur|buteur|scoreur|marqueur|goalscorer|goal.?scorer/.test(text)) return 'Joueurs';
  if (/goal|but|total|over.?under|btts|deux equipes marquent/.test(text)) return 'Buts';
  if (/h2h|1x2|resultat|winner|vainqueur|double.?chance/.test(text)) return 'Résultat';
  return 'Autres';
}

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

/**
 * Part de chaque sélection dans les probabilités implicites du marché (1 / cote, ramenées à 100 %), en pourcentage entier.
 * Affichage seulement (barre sous la cote) : jamais utilisé pour un calcul de mise ou de gain.
 */
export function impliedShares(selections: CatalogueSelection[]): Record<string, number> {
  const inverse = selections.map((s) => ({ id: s.id, value: Number(s.odds) > 1 ? 1 / Number(s.odds) : 0 }));
  const total = inverse.reduce((sum, s) => sum + s.value, 0);
  return Object.fromEntries(inverse.map((s) => [s.id, total > 0 ? Math.round((s.value / total) * 100) : 0]));
}

/** Nom court d'un marché pour le coupon : « Résultat du match (1X2) » → « Résultat du match ». */
export function marketTitle(market: CatalogueMarket): string {
  return market.name.replace(/\s*\(1X2\)$/, '');
}

/** Recherche insensible aux accents dans les marchés et les noms des sélections. */
export function marketMatchesSearch(market: CatalogueMarket, query: string): boolean {
  const needle = normalizeMarketText(query.trim());
  if (!needle) return true;

  return normalizeMarketText([
    market.key,
    market.name,
    market.line ?? '',
    ...market.selections.flatMap((selection) => [selection.key, selection.label, selection.name]),
  ].join(' ')).includes(needle);
}

/** Garde les sélections correspondant à la recherche, sauf si celle-ci correspond au marché lui-même. */
export function matchingSelections(market: CatalogueMarket, query: string): CatalogueSelection[] {
  const needle = normalizeMarketText(query.trim());
  if (!needle || normalizeMarketText(`${market.key} ${market.name} ${market.line ?? ''}`).includes(needle)) {
    return market.selections;
  }

  return market.selections.filter((selection) =>
    normalizeMarketText(`${selection.key} ${selection.label} ${selection.name}`).includes(needle));
}
