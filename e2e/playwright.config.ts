import { defineConfig, devices } from '@playwright/test';

/**
 * Tests de bout en bout du parcours complet (P8-01) — voir e2e/README.md.
 *
 *   E2E_BASE_URL  site testé (défaut : frontend de docker compose, http://localhost:5173)
 *   E2E_ARTISAN   commande artisan du même environnement (défaut : docker compose exec -T backend php artisan)
 *   E2E_EXPECT_TIMEOUT, E2E_TEST_TIMEOUT  délais en millisecondes (pile lente)
 */
export default defineConfig({
  testDir: './tests',
  // Un seul parcours, des étapes qui dépendent les unes des autres : pas de parallélisme.
  workers: 1,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  // Pile de développement (artisan serve, une requête à la fois) : E2E_EXPECT_TIMEOUT=60000 si besoin.
  timeout: Number(process.env.E2E_TEST_TIMEOUT ?? 180_000),
  expect: { timeout: Number(process.env.E2E_EXPECT_TIMEOUT ?? 30_000) },
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5173',
    locale: 'fr-FR',
    timezoneId: 'America/Port-au-Prince',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    ignoreHTTPSErrors: process.env.E2E_IGNORE_HTTPS_ERRORS === '1',
    // Navigateur dans un conteneur (image Playwright) : « MAP localhost host.docker.internal » vise la pile locale.
    launchOptions: process.env.E2E_HOST_RESOLVER_RULES
      ? { args: [`--host-resolver-rules=${process.env.E2E_HOST_RESOLVER_RULES}`] }
      : {},
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
