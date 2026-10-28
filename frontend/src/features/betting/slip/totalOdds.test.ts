import { describe, expect, it } from 'vitest';
import { combinedOdds, potentialReturnMinor } from './totalOdds';

describe('combinedOdds', () => {
  it("n'a pas de cote combinée sans sélection", () => {
    expect(combinedOdds([])).toBeNull();
  });

  it('affiche la cote seule sur 2 décimales', () => {
    expect(combinedOdds(['2.1'])).toBe('2.10');
  });

  it('multiplie les cotes de plusieurs matchs', () => {
    expect(combinedOdds(['2.10', '3.00'])).toBe('6.30');
  });

  it('arrondi demi vers le haut (3,375 → 3,38)', () => {
    expect(combinedOdds(['1.50', '1.50', '1.50'])).toBe('3.38');
  });

  it('reste exact là où le flottant se trompe (1,1 × 1,1 = 1,21)', () => {
    expect(combinedOdds(['1.1', '1.1'])).toBe('1.21');
  });

  it('accepte des cotes à 3 décimales', () => {
    expect(combinedOdds(['1.005', '2.5'])).toBe('2.51'); // 2,5125 arrondi
  });
});

describe('potentialReturnMinor', () => {
  it('calcule mise × cote combinée en centimes', () => {
    // Mise de 100 HTG (10000 centimes) sur une cote de 2.10 → 210 HTG.
    expect(potentialReturnMinor(10000n, ['2.10'])).toBe(21000n);
  });

  it('applique la cote combinée de plusieurs matchs', () => {
    // Mise de 50 HTG sur 2.10 × 3.00 = 6.30 → 315 HTG.
    expect(potentialReturnMinor(5000n, ['2.10', '3.00'])).toBe(31500n);
  });

  it('arrondit au centime le plus proche', () => {
    // Mise de 0,01 HTG sur 1.5 → 0,015 → arrondi à 0,02 HTG.
    expect(potentialReturnMinor(1n, ['1.5'])).toBe(2n);
  });
});
