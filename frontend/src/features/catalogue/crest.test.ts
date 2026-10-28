import { describe, expect, it } from 'vitest';
import { teamHue, teamInitials } from './crest';
import { startsIn } from './format';

describe('écussons', () => {
  it('prend les initiales utiles du nom', () => {
    expect(teamInitials('Manchester United')).toBe('MU');
    expect(teamInitials('FC Barcelona')).toBe('BA');
    expect(teamInitials('Club Brugge')).toBe('BR');
    expect(teamInitials('Como')).toBe('CO');
    expect(teamInitials('Paris Saint-Germain')).toBe('PS');
  });

  it('garde la même couleur pour la même équipe', () => {
    expect(teamHue('Arsenal')).toBe(teamHue('Arsenal'));
    expect(teamHue('Arsenal')).toBeGreaterThanOrEqual(0);
    expect(teamHue('Arsenal')).toBeLessThan(360);
  });
});

describe('startsIn', () => {
  const now = new Date(2026, 9, 21, 12, 0);
  const at = (h: number, m = 0) => new Date(2026, 9, 21, h, m).toISOString();

  it('donne un compte à rebours court dans les 24 h', () => {
    expect(startsIn(at(12, 45), now)).toBe('Dans 45 min');
    expect(startsIn(at(15, 0), now)).toBe('Dans 3 h');
    expect(startsIn(at(14, 30), now)).toBe('Dans 2 h 30');
  });

  it('ne dit rien pour un match commencé ou lointain', () => {
    expect(startsIn(at(11, 0), now)).toBeNull();
    expect(startsIn(new Date(2026, 9, 23, 12, 0).toISOString(), now)).toBeNull();
  });
});
