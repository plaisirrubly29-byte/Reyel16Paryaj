import { describe, expect, it } from 'vitest';
import { checkBirthDate, maskBirthDate } from './birthDate';

describe('maskBirthDate', () => {
  it('place les « / » au fil de la frappe et s\'arrête à 8 chiffres', () => {
    expect(maskBirthDate('2')).toBe('2');
    expect(maskBirthDate('21', '2')).toBe('21/');
    expect(maskBirthDate('21/0', '21/')).toBe('21/0');
    expect(maskBirthDate('21/05', '21/0')).toBe('21/05/');
    expect(maskBirthDate('21/05/19981', '21/05/1998')).toBe('21/05/1998');
  });

  it('laisse effacer le « / » sans le remettre aussitôt', () => {
    expect(maskBirthDate('21', '21/')).toBe('21');
    expect(maskBirthDate('21/05', '21/05/')).toBe('21/05');
  });

  it('accepte une saisie collée : chiffres seuls, autres séparateurs, jour ou mois d\'un chiffre, date ISO', () => {
    expect(maskBirthDate('21051998')).toBe('21/05/1998');
    expect(maskBirthDate('21.05.1998')).toBe('21/05/1998');
    expect(maskBirthDate('5/3/1998')).toBe('05/03/1998');
    expect(maskBirthDate('1998-05-21')).toBe('21/05/1998');
    expect(maskBirthDate('ab')).toBe('');
  });
});

describe('checkBirthDate', () => {
  const today = new Date(2026, 10, 22); // 22/11/2026

  it('convertit une date valide au format AAAA-MM-JJ de l\'API', () => {
    expect(checkBirthDate('21/05/1998', today)).toEqual({ ok: true, iso: '1998-05-21' });
    expect(checkBirthDate('29/02/2000', today)).toEqual({ ok: true, iso: '2000-02-29' });
  });

  it('refuse un format incomplet ou un champ vide', () => {
    expect(checkBirthDate('', today)).toEqual({ ok: false, message: 'Saisissez votre date de naissance (JJ/MM/AAAA).' });
    expect(checkBirthDate('21/05/98', today)).toMatchObject({ ok: false, message: expect.stringContaining('JJ/MM/AAAA') });
  });

  it('refuse les dates qui n\'existent pas', () => {
    expect(checkBirthDate('31/02/2000', today)).toEqual({ ok: false, message: "Le 31/02/2000 n'existe pas : vérifiez le jour et le mois." });
    expect(checkBirthDate('29/02/2001', today)).toMatchObject({ ok: false });
    expect(checkBirthDate('00/05/1998', today)).toMatchObject({ ok: false });
    expect(checkBirthDate('12/13/1998', today)).toEqual({ ok: false, message: 'Le mois doit être compris entre 01 et 12.' });
  });

  it('refuse le futur et les années improbables', () => {
    expect(checkBirthDate('01/01/2030', today)).toEqual({ ok: false, message: 'La date de naissance ne peut pas être dans le futur.' });
    expect(checkBirthDate('01/01/1850', today)).toEqual({ ok: false, message: "Vérifiez l'année de naissance." });
  });

  it('exige l\'âge minimum, jour d\'anniversaire compris (comme le serveur)', () => {
    expect(checkBirthDate('22/11/2008', today)).toEqual({ ok: true, iso: '2008-11-22' });
    expect(checkBirthDate('23/11/2008', today)).toEqual({ ok: false, message: 'Vous devez avoir au moins 18 ans pour ouvrir un compte.' });
    expect(checkBirthDate('22/11/2005', today, 21)).toMatchObject({ ok: true });
    expect(checkBirthDate('23/11/2005', today, 21)).toMatchObject({ ok: false, message: expect.stringContaining('21 ans') });
  });
});
