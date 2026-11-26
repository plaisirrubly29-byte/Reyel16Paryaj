import type { CatalogueMarket, CatalogueSelection } from './api/catalogue';

export type MarketCategory =
  | 'Résultat' | 'Spécial R16' | 'Buts' | 'Mi-temps' | 'Corners' | 'Cartons' | 'Handicap' | 'Score exact' | 'Joueurs' | 'Autres';

/** Ordre des onglets de la page du match. */
export const MARKET_CATEGORIES: readonly MarketCategory[] = [
  'Résultat', 'Spécial R16', 'Buts', 'Mi-temps', 'Corners', 'Cartons', 'Handicap', 'Score exact', 'Joueurs', 'Autres',
];

function normalizeMarketText(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

/** Classe les noms libres provenant du catalogue pour faciliter la navigation sur la page du match. */
export function marketCategory(market: CatalogueMarket): MarketCategory {
  const text = normalizeMarketText(`${market.key} ${market.name}`);

  // Paris maison, cotés par REYEL 16 (clé « r16_… ») : leur propre onglet.
  if (market.key.startsWith('r16_')) return 'Spécial R16';
  // Corners, cartons, mi-temps d'abord : « Handicap corners » va dans Corners, « Score exact à la mi-temps » dans Mi-temps.
  if (/corner/.test(text)) return 'Corners';
  if (/_cards\b|carton/.test(text)) return 'Cartons';
  if (/_h1\b|halftime|mi-temps/.test(text)) return 'Mi-temps';
  if (/exact.?score|score.?exact/.test(text)) return 'Score exact';
  if (/player|joueur|buteur|scoreur|marqueur|goalscorer|goal.?scorer/.test(text)) return 'Joueurs';
  if (/spread|handicap/.test(text)) return 'Handicap';
  if (/goal|but|total|over.?under|btts|deux equipes marquent|odd.?even|pair/.test(text)) return 'Buts';
  if (/h2h|1x2|resultat|winner|vainqueur|double.?chance|draw.?no.?bet|rembourse/.test(text)) return 'Résultat';
  return 'Autres';
}

/**
 * Colonnes d'un marché sur la page du match : les marchés à plusieurs lignes (plus/moins, handicap, buts par équipe)
 * vont par paires (plus / moins de 2.5), les autres jusqu'à trois (1, X, 2).
 */
export function marketColumns(market: CatalogueMarket, count: number): number {
  return /^(alternate_|team_totals|totals_h1)/.test(market.key) ? Math.min(count, 2) : Math.min(count, 3);
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
