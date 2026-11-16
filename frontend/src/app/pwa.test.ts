import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/** Application installable (V2-E05) : manifeste, icônes et service worker présents et cohérents. */
const root = resolve(__dirname, '../..');
const publicDir = resolve(root, 'public');

describe('application installable (V2-E05)', () => {
  const manifest = JSON.parse(readFileSync(resolve(publicDir, 'manifest.webmanifest'), 'utf8'));

  it('manifeste complet : nom, ouverture plein écran, couleurs, icônes 192, 512 et maskable', () => {
    expect(manifest).toMatchObject({ short_name: 'Reyel16', display: 'standalone', start_url: './', scope: './', lang: 'fr' });
    expect(manifest.name).toContain('REYEL 16 PARYAJ');
    const sizes = manifest.icons.map((i: { sizes: string; purpose: string }) => `${i.sizes}:${i.purpose}`);
    expect(sizes).toEqual(expect.arrayContaining(['192x192:any', '512x512:any', '512x512:maskable']));
    for (const icon of manifest.icons) expect(existsSync(resolve(publicDir, icon.src))).toBe(true);
  });

  it('index.html annonce le manifeste et les icônes', () => {
    const html = readFileSync(resolve(root, 'index.html'), 'utf8');
    expect(html).toContain('<link rel="manifest" href="/manifest.webmanifest" />');
    expect(html).toContain('href="/icons/apple-touch-icon.png"');
    expect(existsSync(resolve(publicDir, 'icons/apple-touch-icon.png'))).toBe(true);
  });

  it("le service worker ne garde jamais les réponses de l'API ni les autres sites", () => {
    const sw = readFileSync(resolve(publicDir, 'sw.js'), 'utf8');
    expect(sw).toMatch(/url\.origin !== self\.location\.origin\) return;/);
    expect(sw).toMatch(/pathname\.includes\('\/api\/'\)/);
    expect(sw).toMatch(/pathname\.includes\('\/sanctum\/'\)/);
  });
});
