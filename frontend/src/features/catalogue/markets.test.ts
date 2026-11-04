import { describe, expect, it } from 'vitest';
import type { CatalogueMarket } from './api/catalogue';
import { marketCategory, marketColumns } from './markets';

const market = (key: string, name: string) => ({ id: '1', key, name, line: null, selections: [] }) as unknown as CatalogueMarket;

describe('marchés du match', () => {
  it('classe les nouveaux marchés réglés sur le score final', () => {
    expect(marketCategory(market('draw_no_bet', 'Remboursé si match nul'))).toBe('Résultat');
    expect(marketCategory(market('spreads', 'Handicap'))).toBe('Handicap');
    expect(marketCategory(market('alternate_spreads', 'Handicap (autres lignes)'))).toBe('Handicap');
    expect(marketCategory(market('alternate_totals', 'Nombre de buts (autres lignes)'))).toBe('Buts');
    expect(marketCategory(market('team_totals', 'Buts par équipe'))).toBe('Buts');
    expect(marketCategory(market('odd_even', 'Nombre de buts pair ou impair'))).toBe('Buts');
  });

  it('affiche les marchés à plusieurs lignes par paires', () => {
    expect(marketColumns(market('alternate_totals', ''), 8)).toBe(2);
    expect(marketColumns(market('team_totals', ''), 4)).toBe(2);
    expect(marketColumns(market('h2h', ''), 3)).toBe(3);
    expect(marketColumns(market('correct_score', ''), 12)).toBe(3);
    expect(marketColumns(market('btts', ''), 2)).toBe(2);
  });
});
