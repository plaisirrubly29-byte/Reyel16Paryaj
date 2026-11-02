import { expect, test } from '@playwright/test';
import { createScenarioMatch, type ScenarioMatch } from '../support/artisan';

/**
 * Corrections du site constatées sur le Staging le 2026-11-02 (V2-E03) : menu d'un sport sans match,
 * onglet Live vide, pays tapé à la main à l'inscription.
 *
 * Le faux fournisseur ne propose que du football (Premier League) : le basketball n'a aucun match.
 */
test.describe.configure({ mode: 'serial' });

let match: ScenarioMatch;

test.beforeAll(() => {
  match = createScenarioMatch();
});

test('un sport sans match l\'annonce au lieu d\'afficher du football', async ({ page }) => {
  await page.goto('/sports?sport=basketball');

  await expect(page.getByText('Aucun match de basketball ouvert aux paris pour le moment.')).toBeVisible();
  await expect(page.getByText(match.home)).toHaveCount(0);

  // Accueil filtré sur ce sport : pas de « Top matchs » de football non plus.
  await page.goto('/?sport=basketball');
  await expect(page.getByText('Aucun match de basketball ouvert aux paris pour le moment.')).toBeVisible();
  await expect(page.getByRole('list', { name: 'Matchs à la une' })).toHaveCount(0);

  // Le pied de page ne propose que les sports qui ont des matchs.
  const footer = page.getByRole('navigation', { name: 'Paris sportifs' });
  await expect(footer.getByRole('link', { name: 'Football' })).toBeVisible();
  await expect(footer.getByRole('link', { name: 'Basketball' })).toHaveCount(0);
});

test('l\'onglet Live n\'est jamais vide', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('navigation', { name: 'Navigation principale' }).getByRole('link', { name: 'Live' }).click();

  await expect(page).toHaveURL(/tab=live/);
  // Sans match en direct (cas du faux fournisseur) : les prochains matchs ouverts aux paris.
  const upcoming = page.getByRole('region', { name: 'Prochains matchs' });
  await expect(upcoming.or(page.locator('.live-badge').first())).toBeVisible();
  if (await upcoming.isVisible()) {
    await expect(page.getByText(/Aucun match en direct en ce moment/)).toBeVisible();
    await expect(upcoming.locator('.match-line').first()).toBeVisible();
  }
});

test('le pays se choisit dans une liste, Haïti par défaut', async ({ page }) => {
  await page.goto('/register');

  const country = page.getByRole('combobox', { name: 'Pays' });
  await expect(country).toHaveValue('HT');
  await expect(country.locator('option').first()).toHaveText('Haïti');

  await country.selectOption({ label: 'République dominicaine' });
  await expect(country).toHaveValue('DO');
});
