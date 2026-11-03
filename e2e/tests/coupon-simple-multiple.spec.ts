import { expect, test } from '@playwright/test';
import { createScenarioMatch } from '../support/artisan';

/**
 * Coupon simple ou multiple : deux sélections forment un combiné par défaut ; en « Simple », chaque sélection devient
 * un ticket avec sa propre mise (un POST /bets chacun). Crédit de bienvenue 5 000 HTG, 2 × 100 HTG → 4 800 HTG.
 */
const money = (amount: string) => new RegExp(`${amount.replace(' ', '\\s?')},00\\s?HTG`);

test('deux paris simples placés depuis le même coupon', async ({ page }) => {
  const first = createScenarioMatch();
  const second = createScenarioMatch();
  const phone = `5${String(Date.now()).slice(-7)}`;
  const password = 'Coupon-Simple-2026';

  await page.goto('/register');
  await page.getByLabel('Nom').fill('Joueur Coupon E2E');
  await page.getByLabel('Numéro de téléphone').fill(phone);
  await page.getByLabel('Date de naissance').fill('1995-05-21');
  await page.getByLabel('Mot de passe', { exact: true }).fill(password);
  await page.getByLabel('Confirmer le mot de passe').fill(password);
  // Case des conditions (V2-E01, PR #205) : cochée si elle est déjà en place sur la branche testée.
  const terms = page.getByRole('checkbox', { name: /J'accepte les conditions/ });
  if (await terms.count()) await terms.check();
  await page.getByRole('button', { name: 'Créer mon compte' }).click();
  await expect(page).toHaveURL(/\/account$/);

  await page.goto('/');
  await page.getByRole('button', { name: `1 ${first.home} à 2.00` }).first().click();
  await page.getByRole('button', { name: `1 ${second.home} à 2.00` }).first().click();

  const slip = page.getByRole('complementary', { name: 'Coupon' });
  const mode = slip.getByRole('group', { name: 'Type de pari' });
  await expect(mode.getByRole('button', { name: 'Multiple' })).toHaveAttribute('aria-pressed', 'true');
  await expect(slip.getByText('Cote totale').locator('xpath=following-sibling::*[1]')).toHaveText('4.00');

  await mode.getByRole('button', { name: 'Simple' }).click();
  await slip.getByLabel('Même mise pour tous').fill('100');
  await expect(slip.getByText('Mise totale (2 paris)').locator('xpath=following-sibling::*[1]')).toHaveText(money('200'));
  await slip.getByRole('button', { name: 'Placer 2 paris' }).click();

  await expect(slip.getByText('2 paris placés')).toBeVisible();
  await page.goto('/wallet');
  await expect(page.locator('.balance')).toHaveText(money('4 800'));
  await page.goto('/bets');
  await expect(page.locator('.ticket-card').filter({ hasText: first.home })).toHaveCount(1);
  await expect(page.locator('.ticket-card').filter({ hasText: second.home })).toHaveCount(1);
});
