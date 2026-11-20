import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { importOrReload, isStaleAssetError, reloadOnce } from './staleAssets';

describe('fichiers effacés par un déploiement', () => {
  const reload = vi.fn();

  beforeEach(() => {
    sessionStorage.clear();
    reload.mockReset();
    vi.stubGlobal('location', { ...window.location, reload });
  });
  afterEach(() => vi.unstubAllGlobals());

  it('reconnaît les messages de Chrome, Firefox et Safari', () => {
    expect(isStaleAssetError(new TypeError('Failed to fetch dynamically imported module: https://x/assets/A-1.js'))).toBe(true);
    expect(isStaleAssetError(new TypeError('error loading dynamically imported module: https://x/assets/A-1.js'))).toBe(true);
    expect(isStaleAssetError(new TypeError('Importing a module script failed.'))).toBe(true);
    expect(isStaleAssetError(new Error('Solde insuffisant'))).toBe(false);
  });

  it('recharge une seule fois par minute (pas de boucle si le serveur est en panne)', () => {
    expect(reloadOnce(1_000_000)).toBe(true);
    expect(reloadOnce(1_030_000)).toBe(false);
    expect(reloadOnce(1_061_000)).toBe(true);
    expect(reload).toHaveBeenCalledTimes(2);
  });

  it('laisse passer les autres erreurs', async () => {
    await expect(importOrReload(() => Promise.reject(new Error('boom')))).rejects.toThrow('boom');
    expect(reload).not.toHaveBeenCalled();
  });

  it('recharge la page quand le module a disparu', async () => {
    void importOrReload(() => Promise.reject(new TypeError('Failed to fetch dynamically imported module: /assets/A.js')));
    await Promise.resolve();
    await Promise.resolve();
    expect(reload).toHaveBeenCalledTimes(1);
  });
});
