import { describe, expect, it } from 'vitest';
import { digitsOnly } from './phone';

describe('digitsOnly', () => {
  it('garde un numéro local déjà propre', () => {
    expect(digitsOnly('34123456')).toBe('34123456');
  });

  it('retire les espaces et tirets', () => {
    expect(digitsOnly('34-12 34 56')).toBe('34123456');
  });

  it('retire les lettres et autres caractères', () => {
    expect(digitsOnly('34a12b34c56')).toBe('34123456');
  });

  it('retire l\'indicatif +509 collé devant (11 chiffres au total)', () => {
    expect(digitsOnly('+509 34 12 34 56')).toBe('34123456');
    expect(digitsOnly('50934123456')).toBe('34123456');
  });

  it('ne retire pas "509" s\'il ne s\'agit pas d\'un indicatif (pas 11 chiffres)', () => {
    expect(digitsOnly('5091234')).toBe('5091234');
  });

  it('tronque à 8 chiffres au-delà (saisie trop longue, hors cas de l\'indicatif)', () => {
    expect(digitsOnly('123456789012')).toBe('12345678');
  });
});
