import { expect, test, type Page } from '@playwright/test';
import { artisan } from '../support/artisan';

/**
 * Borlette (V2-D09) : un joueur valide une fiche (bolèt « 25 » à 10 HTG) sur un tirage ouvert, reçoit le reçu B16-…,
 * retrouve la fiche « En cours » dans Mes paris et voit son solde baisser de 10 HTG.
 *
 * Le backend doit avoir LOTTERY_ENABLED=true et LOTTERY_SALES_ENABLED=true, avec le faux fournisseur de tirages
 * (4 tirages : fl_midday, ny_midday, fl_evening, ny_evening), synchronisé par `lottery:sync`.
 */

/** Montant affiché au format français, quel que soit l'espace insécable utilisé (« 4 990,00 HTG »). */
const money = (amount: string) => new RegExp(`${amount.replace(' ', '\\s?')},00\\s?HTG`);

async function balance(page: Page) {
  await page.goto('/wallet');
  return page.locator('.balance');
}

test.describe.configure({ mode: 'serial' });

test('vente d\'une fiche de borlette, du tirage à Mes paris', async ({ page }) => {
  const phone = `3${String(Date.now()).slice(-7)}`;
  const password = 'Borlette-E2E-2026';
  let reference = '';

  await test.step('tirages synchronisés depuis le faux fournisseur', () => {
    artisan('lottery:sync');
  });

  await test.step('compte : inscription, crédit de bienvenue', async () => {
    await page.goto('/register');
    await page.getByLabel('Nom').fill('Joueur Borlette E2E');
    await page.getByLabel('Numéro de téléphone').fill(phone);
    await page.getByLabel('Date de naissance').fill('1995-05-21');
    await page.getByLabel('Mot de passe', { exact: true }).fill(password);
    await page.getByLabel('Confirmer le mot de passe').fill(password);
    await page.getByRole('checkbox', { name: /J'accepte les conditions/ }).check();
    await page.getByRole('button', { name: 'Créer mon compte' }).click();

    await expect(page).toHaveURL(/\/account$/);
    await expect(await balance(page)).toHaveText(money('5 000'));
  });

  await test.step('fiche : choisir un tirage, bolèt 25 à 10 HTG, valider', async () => {
    await page.goto('/borlette');
    // Le tirage proposé par défaut est le prochain ; on le choisit explicitement.
    const draws = page.getByRole('list', { name: 'Tirages' });
    const draw = draws.locator('.lottery-draw').first();
    await expect(draw).toBeVisible();
    const name = (await draw.locator('.lottery-draw-name').textContent())?.trim() ?? '';
    await draw.click();

    const slip = page.getByRole('region', { name: `Fiche : ${name}` });
    await slip.getByRole('button', { name: 'Bolèt' }).click();
    await slip.getByLabel('Numéro').fill('25');
    await slip.getByLabel(/Mise/).fill('10');
    await slip.getByRole('button', { name: 'Ajouter à la fiche' }).click();
    await expect(slip.getByRole('list', { name: 'Lignes de la fiche' })).toContainText('25');

    await slip.getByRole('button', { name: 'Valider la fiche' }).click();
    await slip.getByRole('button', { name: 'Confirmer et payer' }).click();

    // Tirage déjà fermé à la vente (fin des ventes passée) : le serveur propose la date suivante.
    const receipt = slip.getByText(/^B16-/);
    const nextDate = slip.getByRole('button', { name: /^Jouer pour le / });
    await expect(receipt.or(nextDate)).toBeVisible();
    if (await nextDate.isVisible()) {
      await nextDate.click();
      await slip.getByRole('button', { name: 'Confirmer et payer' }).click();
    }

    await expect(slip.getByText('Fiche validée')).toBeVisible();
    reference = (await receipt.textContent())?.trim() ?? '';
    expect(reference).toMatch(/^B16-/);
  });

  await test.step('Mes paris : la fiche est en cours, le solde a baissé de 10 HTG', async () => {
    await page.goto('/bets?produit=borlette');
    const card = page.getByRole('listitem', { name: `Fiche ${reference}` });
    await expect(card.locator('.ticket-status')).toHaveText('En cours');
    await expect(card).toContainText('Bolèt 25');
    await expect(card).toContainText(money('10'));

    await expect(await balance(page)).toHaveText(money('4 990'));
  });
});
