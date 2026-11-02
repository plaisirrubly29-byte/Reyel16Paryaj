import { expect, test } from '@playwright/test';

/**
 * Pages d'information (V2-E01) : plus aucune « Page introuvable », liens du pied de page, bandeau cookies et case
 * d'acceptation à l'inscription. L'enregistrement de l'acceptation (version) est vérifié par le parcours complet.
 */
const PAGES = [
  { path: '/conditions', title: "Conditions d'utilisation" },
  { path: '/reglement', title: 'Règlement des paris' },
  { path: '/confidentialite', title: 'Confidentialité et cookies' },
  { path: '/jeu-responsable', title: 'Jeu responsable' },
];

test('les quatre textes s\'ouvrent depuis le pied de page', async ({ page }) => {
  await page.goto('/');
  const footer = page.getByRole('navigation', { name: 'Informations' });

  for (const p of PAGES) {
    await footer.getByRole('link', { name: p.title }).click();
    await expect(page).toHaveURL(new RegExp(`${p.path}$`));
    await expect(page.getByRole('heading', { level: 1, name: p.title })).toBeVisible();
    await expect(page.getByText(/À implémenter|Page introuvable/)).toHaveCount(0);
  }
});

test('une adresse inconnue propose de revenir aux matchs', async ({ page }) => {
  await page.goto('/cette-page-n-existe-pas');

  await expect(page.getByRole('heading', { level: 1, name: "Cette page n'existe pas" })).toBeVisible();
  await page.getByRole('link', { name: 'Retour aux matchs' }).click();
  await expect(page).toHaveURL(/\/$/);
});

test('le bandeau cookies se ferme et ne revient pas', async ({ page }) => {
  await page.goto('/');
  const banner = page.getByRole('region', { name: 'Cookies' });

  await expect(banner).toBeVisible();
  await banner.getByRole('button', { name: "J'ai compris" }).click();
  await expect(banner).toHaveCount(0);

  await page.reload();
  await expect(page.getByRole('contentinfo')).toBeVisible();
  await expect(banner).toHaveCount(0);
});

test('l\'inscription exige la case des conditions', async ({ page }) => {
  await page.goto('/register');

  await page.getByRole('button', { name: 'Créer mon compte' }).click();
  await expect(page.getByText('Cochez la case pour accepter les conditions et créer votre compte.')).toBeVisible();
  await expect(page).toHaveURL(/\/register$/);

  // Lien vers le règlement : nouvel onglet, le formulaire reste ouvert.
  const [rules] = await Promise.all([
    page.waitForEvent('popup'),
    page.getByRole('link', { name: 'règlement des paris' }).click(),
  ]);
  await expect(rules.getByRole('heading', { level: 1, name: 'Règlement des paris' })).toBeVisible();
  await expect(page).toHaveURL(/\/register$/);
});
