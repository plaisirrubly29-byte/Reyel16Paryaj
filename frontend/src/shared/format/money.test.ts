import { describe, expect, it } from 'vitest';
import { formatMoney } from './money';

// Intl utilise des espaces insécables : on les normalise pour comparer.
const NBSP = new RegExp(`[${String.fromCharCode(0xa0, 0x202f)}]`, 'g');
const plain = (s: string) => s.replace(NBSP, ' ');

describe('formatMoney', () => {
  it("affiche l'unité mineure selon les décimales de la devise", () => {
    expect(plain(formatMoney({ minor: 1_000_075, currency: 'HTG' }))).toBe('10 000,75 HTG');
    expect(plain(formatMoney({ minor: 1500, currency: 'USD' }, 'en-US'))).toBe('$15.00');
  });

  it('gère les devises sans décimales', () => {
    expect(plain(formatMoney({ minor: 1500, currency: 'JPY' }, 'en-US'))).toBe('¥1,500');
  });

  it('peut afficher le signe des crédits et débits', () => {
    expect(plain(formatMoney({ minor: -2000, currency: 'HTG' }, 'fr-FR', { signed: true }))).toBe('-20,00 HTG');
    expect(plain(formatMoney({ minor: 2000, currency: 'HTG' }, 'fr-FR', { signed: true }))).toBe('+20,00 HTG');
  });
});
