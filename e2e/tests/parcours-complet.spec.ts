import { expect, test, type Page } from '@playwright/test';
import { createScenarioMatch, finishAndSettle, type ScenarioMatch } from '../support/artisan';

/**
 * Parcours complet (P8-01) : compte → événement → coupon → ticket simulé → résultat test → règlement simulé.
 *
 * Fonds fictifs : le joueur reçoit le crédit de bienvenue (5 000 HTG), mise 100 HTG sur la victoire à domicile
 * (cote 2.00), le match se termine 2-1 : ticket gagné, 200 HTG versés, solde final 5 100 HTG.
 */

/** Montant affiché au format français, quel que soit l'espace insécable utilisé (« 5 100,00 HTG »). */
const money = (amount: string) => new RegExp(`${amount.replace(' ', '\\s?')},00\\s?HTG`);

async function balance(page: Page) {
  await page.goto('/wallet');
  return page.locator('.balance');
}

test.describe.configure({ mode: 'serial' });

test('parcours complet du joueur, du compte au règlement', async ({ page }) => {
  const phone = `3${String(Date.now()).slice(-7)}`;
  const password = 'Parcours-E2E-2026';
  let match: ScenarioMatch;

  await test.step('un match est ouvert aux paris', () => {
    match = createScenarioMatch();
  });

  await test.step('compte : inscription, crédit de bienvenue', async () => {
    await page.goto('/register');
    await page.getByLabel('Nom').fill('Joueur Parcours E2E');
    await page.getByLabel('Numéro de téléphone').fill(phone);
    await page.getByLabel('Date de naissance').fill('1995-05-21');
    await page.getByLabel('Mot de passe', { exact: true }).fill(password);
    await page.getByLabel('Confirmer le mot de passe').fill(password);
    await page.getByRole('button', { name: 'Créer mon compte' }).click();

    await expect(page).toHaveURL(/\/account$/);
    await expect(await balance(page)).toHaveText(money('5 000'));
  });

  await test.step('événement : le match est affiché avec ses cotes', async () => {
    await page.goto('/');
    await expect(page.getByText(match.home).first()).toBeVisible();
  });

  await test.step('coupon : sélection, mise et ticket simulé', async () => {
    await page.getByRole('button', { name: `1 ${match.home} à 2.00` }).first().click();
    const slip = page.getByRole('complementary', { name: 'Coupon' });
    await slip.getByLabel('Montant de la mise').fill('100');
    await slip.getByRole('button', { name: 'Placer mon pari' }).click();

    await expect(slip.getByText('Pari placé')).toBeVisible();
    await expect(slip.getByText(/Mise de 100,00.*débitée/)).toBeVisible();
    await expect(await balance(page)).toHaveText(money('4 900'));

    await page.goto('/bets');
    await expect(page.locator('.ticket-card').filter({ hasText: match.home }).locator('.ticket-status')).toHaveText('En attente');
  });

  await test.step('résultat test (2-1) et règlement simulé', () => {
    finishAndSettle(match, '2-1');
  });

  await test.step('ticket gagné et gain crédité', async () => {
    await page.goto('/bets');
    await expect(page.locator('.ticket-card').filter({ hasText: match.home }).locator('.ticket-status')).toHaveText('Gagné');
    await expect(await balance(page)).toHaveText(money('5 100'));
  });
});
