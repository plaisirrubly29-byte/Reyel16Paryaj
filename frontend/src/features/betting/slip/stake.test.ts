import { describe, expect, it } from 'vitest';
import { parseStakeMinor, sanitizeStake } from './stake';

describe('sanitizeStake', () => {
  it('ne garde que les chiffres et un séparateur décimal', () => {
    expect(sanitizeStake('12a3')).toBe('123');
    expect(sanitizeStake('1,5')).toBe('1.5');
  });

  it('garde 2 décimales au plus', () => {
    expect(sanitizeStake('10.456')).toBe('10.45');
  });

  it('accepte un séparateur en cours de saisie', () => {
    expect(sanitizeStake('12.')).toBe('12.');
  });
});

describe('parseStakeMinor', () => {
  it('convertit la mise en centimes', () => {
    expect(parseStakeMinor('100')).toBe(10000n);
    expect(parseStakeMinor('100.5')).toBe(10050n);
    expect(parseStakeMinor('0.99')).toBe(99n);
  });

  it('refuse une mise vide, nulle ou incomplète', () => {
    expect(parseStakeMinor('')).toBeNull();
    expect(parseStakeMinor('0')).toBeNull();
    expect(parseStakeMinor('12.')).toBeNull();
    expect(parseStakeMinor('.5')).toBeNull();
  });
});
