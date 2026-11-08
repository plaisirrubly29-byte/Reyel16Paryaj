import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { guest, mockApi, renderApp } from '../../test/renderApp';

const DRAW = {
  key: 'NYM', name: 'New York Midi', draw_time: '14:20', timezone: 'America/Port-au-Prince', days: null,
  next_draw_at: '2099-01-01T19:20:00Z', latest_result: null,
};

function backend(lottery: { status: number; body?: unknown } = { status: 200, body: { data: [DRAW] } }) {
  return mockApi({
    'GET /me': guest,
    'GET /sports': { status: 200, body: { data: [] } },
    'GET /events': { status: 200, body: { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } } },
    'GET /lottery/draws': lottery,
    'GET /lottery/results': { status: 200, body: { data: [], meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 } } },
  });
}

/** Écran de téléphone : toutes les requêtes média « max-width » correspondent (barre d'onglets affichée). */
beforeEach(() => {
  vi.stubGlobal('matchMedia', (query: string) => ({
    matches: query.includes('max-width'),
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
  }));
});
afterEach(() => { vi.unstubAllGlobals(); });

describe('menu des jeux (barre mobile)', () => {
  it("remplace l'onglet Accueil et liste Sport, Borlette, Jeux virtuels et Casino", async () => {
    backend();
    const router = renderApp('/');

    const bar = await screen.findByRole('navigation', { name: 'Navigation mobile' });
    expect(within(bar).queryByRole('link', { name: 'Accueil' })).toBeNull();

    await userEvent.click(within(bar).getByRole('button', { name: 'Jeux' }));
    const menu = screen.getByRole('dialog', { name: 'Jeux' });
    expect(within(menu).getAllByRole('listitem').map((li) => li.textContent))
      .toEqual([
        expect.stringMatching(/^Sport/),
        expect.stringMatching(/^Borlette/),
        expect.stringMatching(/^Jeux virtuels.*Bientôt$/),
        expect.stringMatching(/^Casino.*Bientôt$/),
      ]);
    expect(within(menu).getByRole('link', { name: /^Sport/ })).toHaveAttribute('href', '/sports');

    await userEvent.click(await within(menu).findByRole('link', { name: /^Borlette/ }));
    expect(router.state.location.pathname).toBe('/borlette');
    expect(screen.queryByRole('dialog', { name: 'Jeux' })).toBeNull();
  });

  it('borlette coupée côté serveur : affichée « Bientôt », sans lien ; Échap referme le menu', async () => {
    backend({ status: 404, body: { message: 'Not Found' } });
    renderApp('/');

    const bar = await screen.findByRole('navigation', { name: 'Navigation mobile' });
    await userEvent.click(within(bar).getByRole('button', { name: 'Jeux' }));
    const menu = screen.getByRole('dialog', { name: 'Jeux' });
    expect(within(menu).queryByRole('link', { name: /Borlette/ })).toBeNull();
    expect(within(menu).getByText('Borlette').closest('.game-tile')).toHaveTextContent('Bientôt');

    await userEvent.keyboard('{Escape}');
    expect(screen.queryByRole('dialog', { name: 'Jeux' })).toBeNull();
  });
});
