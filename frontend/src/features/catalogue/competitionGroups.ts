import type { CompetitionTier } from './api/catalogue';

/** Ordre des niveaux dans un pays : 1re division → 2e → 3e → 4e → coupes, puis les compétitions sans niveau. */
const TIER_ORDER: CompetitionTier[] = ['d1', 'd2', 'd3', 'd4', 'cup'];

const TIER_LABELS: Record<CompetitionTier, string> = {
  d1: 'Division 1',
  d2: 'Division 2',
  d3: 'Division 3',
  d4: 'Division 4',
  cup: 'Coupe nationale',
};

/** Titre du groupe des compétitions dont le pays n'est pas connu. */
export const OTHER_COUNTRY = 'Autres compétitions';

/** Mention du niveau affichée sous le nom (« Division 1 », « Coupe nationale »), ou null sans niveau. */
export function tierLabel(tier: CompetitionTier | null | undefined): string | null {
  return tier ? TIER_LABELS[tier] ?? null : null;
}

function tierRank(tier: CompetitionTier | null | undefined): number {
  const index = tier ? TIER_ORDER.indexOf(tier) : -1;
  return index === -1 ? TIER_ORDER.length : index;
}

type Groupable = { country: string | null; tier?: CompetitionTier | null };

/**
 * Regroupe les compétitions par pays (ou zone : Europe, Monde…). Les pays gardent l'ordre de l'API (pays des « top
 * compétitions » d'abord), les compétitions sans pays viennent en dernier ; dans un pays, D1 → D2 → D3 → coupes,
 * l'ordre de l'API départageant les compétitions de même niveau.
 */
export function groupByCountry<T extends Groupable>(competitions: T[]): { country: string; competitions: T[] }[] {
  const groups = new Map<string, T[]>();
  for (const competition of competitions) {
    const country = competition.country || OTHER_COUNTRY;
    groups.set(country, [...(groups.get(country) ?? []), competition]);
  }

  const known = [...groups].filter(([country]) => country !== OTHER_COUNTRY);
  const other = groups.get(OTHER_COUNTRY);
  return [...known, ...(other ? [[OTHER_COUNTRY, other] as const] : [])].map(([country, items]) => ({
    country,
    competitions: [...items].sort((a, b) => tierRank(a.tier) - tierRank(b.tier)),
  }));
}
