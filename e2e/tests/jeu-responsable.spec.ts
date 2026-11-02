import { expect, test, type Page } from '@playwright/test';

/**
 * Jeu responsable du joueur (P7-02) : limite de mise, pause, puis auto-exclusion qui ferme le compte au jeu.
 * Un compte neuf par exécution : la pause et l'exclusion sont irrévocables.
 */
async function register(page: Page, phone: string, password: string) {
  await page.goto('/register');
  await page.getByLabel('Nom').fill('Joueur Jeu Responsable');
  await page.getByLabel('Numéro de téléphone').fill(phone);
  await page.getByLabel('Date de naissance').fill('1995-05-21');
  await page.getByLabel('Mot de passe', { exact: true }).fill(password);
  await page.getByLabel('Confirmer le mot de passe').fill(password);
  await page.getByRole('checkbox', { name: /J'accepte les conditions/ }).check();
  await page.getByRole('button', { name: 'Créer mon compte' }).click();
  await expect(page).toHaveURL(/\/account$/);
}

test('limite, pause et auto-exclusion depuis l\'espace joueur', async ({ page }) => {
  const phone = `4${String(Date.now()).slice(-7)}`;
  const password = 'Jeu-Responsable-2026';
  await register(page, phone, password);

  await test.step('la rubrique est dans le menu du compte', async () => {
    await page.getByRole('navigation', { name: 'Rubriques du compte' }).getByRole('link', { name: /Jeu responsable/ }).click();
    await expect(page).toHaveURL(/\/responsible-gaming$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Jeu responsable' })).toBeVisible();
  });

  await test.step('une première limite s\'applique tout de suite', async () => {
    const limits = page.getByRole('region', { name: 'Limites de mise' });
    await limits.getByRole('button', { name: 'Fixer une limite' }).first().click();
    await limits.getByLabel(/Nouvelle limite sur 24 heures/).fill('2500');
    await limits.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(limits.getByText('Limite appliquée.')).toBeVisible();
    await expect(limits.getByText(/2\s?500,00\s?HTG/).first()).toBeVisible();
  });

  await test.step('la pause est confirmée puis annoncée', async () => {
    const pause = page.getByRole('region', { name: 'Faire une pause' });
    await pause.getByRole('button', { name: 'Faire une pause de 24 heures' }).click();
    await pause.getByRole('button', { name: 'Confirmer la pause' }).click();

    await expect(pause.getByText(/Pause enregistrée jusqu'au/)).toBeVisible();
    await expect(page.getByText('Paris en pause')).toBeVisible();
  });

  await test.step('l\'auto-exclusion déconnecte et bloque la connexion', async () => {
    const exclusion = page.getByRole('region', { name: "S'exclure du jeu" });
    await exclusion.getByRole('button', { name: "Je veux m'exclure" }).click();
    await exclusion.getByLabel('Mot de passe').fill(password);
    await exclusion.getByRole('checkbox', { name: /irrévocable/ }).check();
    await exclusion.getByRole('button', { name: "M'exclure pour 6 mois" }).click();

    await expect(page).toHaveURL(/\/auto-exclusion$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Votre auto-exclusion est enregistrée' })).toBeVisible();

    await page.goto('/login');
    await page.getByLabel('Téléphone ou email').fill(phone);
    await page.getByLabel('Mot de passe').fill(password);
    await page.getByRole('button', { name: 'Se connecter' }).click();
    await expect(page.getByText(/auto-exclusion/)).toBeVisible();
    await expect(page).toHaveURL(/\/login/);
  });
});
