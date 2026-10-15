import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { guest, mockApi, player, renderApp } from '../../test/renderApp';

afterEach(() => vi.unstubAllGlobals());

describe("page d'accueil", () => {
  it('invite un visiteur à créer un compte, même si l\'API est indisponible', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));

    renderApp('/');

    expect(await screen.findByRole('heading', { level: 1 })).toHaveTextContent('Pariez sur vos sports préférés');
    expect(screen.getByRole('link', { name: 'Créer un compte' })).toHaveAttribute('href', '/register');
    expect(screen.getByRole('heading', { name: 'Comment ça marche' })).toBeInTheDocument();
    expect(await screen.findByText(/Service : indisponible/)).toBeInTheDocument();
  });

  it('propose les matchs et le portefeuille à un joueur connecté', async () => {
    mockApi({
      'GET /me': { status: 200, body: { data: player } },
      'GET /health': { status: 200, body: { status: 'ok', version: 'v1', time: '' } },
    });

    renderApp('/');

    expect(await screen.findByText('Bon retour, Ana Joueuse !')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Mon portefeuille' })).toHaveAttribute('href', '/wallet');
    expect(screen.queryByRole('link', { name: 'Créer un compte' })).toBeNull();
    expect(await screen.findByText(/Service : en ligne/)).toBeInTheDocument();
  });

  it("indique au visiteur qu'il n'est pas connecté", async () => {
    mockApi({ 'GET /me': guest, 'GET /health': { status: 200, body: { status: 'ok', version: 'v1', time: '' } } });

    renderApp('/');

    expect(await screen.findByRole('link', { name: 'Se connecter' })).toHaveAttribute('href', '/login');
  });
});
