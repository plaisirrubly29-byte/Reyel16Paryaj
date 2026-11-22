import { describe, expect, it } from 'vitest';
import type { CompetitionTier } from './api/catalogue';
import { groupByCountry, OTHER_COUNTRY, tierLabel } from './competitionGroups';

const c = (name: string, country: string | null, tier: CompetitionTier | null = null) => ({ name, country, tier });

describe('groupByCountry', () => {
  it('garde l\'ordre des pays et range chaque pays D1 → D2 → D3 → coupes', () => {
    const groups = groupByCountry([
      c('Ligue des champions', 'Europe'),
      c('DFB-Pokal', 'Allemagne', 'cup'),
      c('Bundesliga', 'Allemagne', 'd1'),
      c('Ligue Europa', 'Europe'),
      c('3. Liga', 'Allemagne', 'd3'),
      c('Bundesliga féminine', 'Allemagne'),
      c('2. Bundesliga', 'Allemagne', 'd2'),
      c('Serie A', 'Italie', 'd1'),
    ]);

    expect(groups.map((g) => [g.country, g.competitions.map((x) => x.name)])).toEqual([
      ['Europe', ['Ligue des champions', 'Ligue Europa']],
      ['Allemagne', ['Bundesliga', '2. Bundesliga', '3. Liga', 'DFB-Pokal', 'Bundesliga féminine']],
      ['Italie', ['Serie A']],
    ]);
  });

  it('met les compétitions sans pays dans un dernier groupe', () => {
    const groups = groupByCountry([c('Amical', null), c('Ligue 1', 'France', 'd1')]);

    expect(groups.map((g) => g.country)).toEqual(['France', OTHER_COUNTRY]);
  });
});

describe('tierLabel', () => {
  it('donne la mention de la division ou de la coupe', () => {
    expect(['d1', 'd2', 'd3', 'd4', 'cup', null].map((t) => tierLabel(t as CompetitionTier | null))).toEqual([
      'Division 1', 'Division 2', 'Division 3', 'Division 4', 'Coupe nationale', null,
    ]);
  });
});
