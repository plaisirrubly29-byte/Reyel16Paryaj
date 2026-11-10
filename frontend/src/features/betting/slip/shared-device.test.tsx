import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { currentQueryClient, guest, mockApi, player, renderApp } from '../../../test/renderApp';

// Revue de sécurité P8-02, point F11 : sur un appareil partagé (cybercafé), ni le coupon ni les données
// du joueur précédent ne doivent apparaître au suivant.

beforeEach(() => localStorage.clear());
afterEach(() => vi.unstubAllGlobals());

const SELECTIONS = 'r16p.betslip';
const OWNER = 'r16p.betslip.owner';
const inHours = (h: number) => new Date(Date.now() + h * 3_600_000).toISOString();

const pick = (id: string) => ({
  selectionId: `x${id}`, eventId: `e${id}`, marketId: `m${id}`, oddsVersion: 1, odds: '2.00',
  label: '1', pick: `Équipe ${id}`, match: `Équipe ${id} – Autre ${id}`, startsAt: inHours(5),
});

/** Coupon laissé dans le navigateur par le compte `owner` (null : par un visiteur). */
function leftBehind(owner: string | null, ...ids: string[]) {
  localStorage.setItem(SELECTIONS, JSON.stringify(ids.map(pick)));
  if (owner !== null) localStorage.setItem(OWNER, owner);
}

const storedPicks = () => (JSON.parse(localStorage.getItem(SELECTIONS) ?? '[]') as { selectionId: string }[]).map((s) => s.selectionId);

const paginated = (data: unknown[]) => ({
  data, links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 50, total: data.length, last_page: 1, from: 1, to: data.length },
});

const EVENT = {
  id: '1', sport: { slug: 'soccer', name: 'Football' }, competition: { id: '1', name: 'Premier League', country: 'Angleterre' },
  home_team: 'Everton', away_team: 'Chelsea', starts_at: inHours(5), status: 'scheduled',
  markets: [{
    id: 'm1', key: 'h2h', name: 'Résultat du match (1X2)', odds_version: 1, odds_updated_at: inHours(0), bettable: true,
    selections: [
      { id: '11', key: 'home', label: '1', name: 'Everton', odds: '2.00' },
      { id: '12', key: 'draw', label: 'X', name: 'Match nul', odds: '3.60' },
      { id: '13', key: 'away', label: '2', name: 'Chelsea', odds: '2.05' },
    ],
  }],
};

type Handlers = Parameters<typeof mockApi>[0];

const site: Handlers = {
  'GET /sports': { status: 200, body: { data: [] } },
  'GET /events': { status: 200, body: paginated([EVENT]) },
};
const account: Handlers = {
  'GET /wallet': { status: 200, body: { data: { currency: 'HTG', balance: { minor: 100000, currency: 'HTG' }, status: 'active', simulated: false } } },
  'GET /bets': { status: 200, body: paginated([]) },
};
const asGuest = (extra: Handlers = {}) => mockApi({ 'GET /me': guest, ...site, ...extra });
const asPlayer = (extra: Handlers = {}) => mockApi({ 'GET /me': { status: 200, body: { data: player } }, ...site, ...account, ...extra });

const slip = () => screen.findByRole('complementary', { name: 'Coupon' });
const addEverton = async () => userEvent.click((await screen.findAllByRole('button', { name: '1 Everton à 2.00' }))[0]);

describe('coupon sur un appareil partagé', () => {
  it('enregistre le coupon avec le compte qui le compose', async () => {
    asPlayer();
    renderApp('/');

    await addEverton();

    await waitFor(() => expect(storedPicks()).toEqual(['11']));
    expect(localStorage.getItem(OWNER)).toBe(player.id);
  });

  it('enregistre le coupon d\'un visiteur sans propriétaire, repris par le compte qui se connecte', async () => {
    let connected = false;
    mockApi({
      ...site, ...account,
      'GET /me': () => (connected ? { status: 200, body: { data: player } } : guest),
      'POST /auth/login': () => { connected = true; return { status: 200, body: { data: player } }; },
    });
    renderApp('/');

    await addEverton();
    await waitFor(() => expect(storedPicks()).toEqual(['11']));
    expect(localStorage.getItem(OWNER)).toBeNull();

    await userEvent.click(within(await slip()).getByRole('link', { name: 'Se connecter pour parier' }));
    await userEvent.type(await screen.findByLabelText('Téléphone ou email'), '34123456');
    await userEvent.type(screen.getByLabelText('Mot de passe'), 'secret-123');
    await userEvent.click(screen.getByRole('button', { name: 'Se connecter' }));

    await waitFor(() => expect(localStorage.getItem(OWNER)).toBe(player.id));
    expect(within(await slip()).getByLabelText('1 sélection')).toBeInTheDocument();
  });

  it('cache au visiteur le coupon d\'un compte, sans l\'effacer', async () => {
    leftBehind(player.id, 'a', 'b');
    asGuest();
    renderApp('/');

    await screen.findAllByRole('button', { name: '1 Everton à 2.00' });
    expect(within(await slip()).getByLabelText('0 sélection')).toBeInTheDocument();
    expect(screen.queryByText(/Équipe a/)).not.toBeInTheDocument();
    // Gardé pour son joueur, s'il se reconnecte sur cet appareil.
    expect(storedPicks()).toEqual(['xa', 'xb']);
    expect(localStorage.getItem(OWNER)).toBe(player.id);
  });

  it('rend son coupon au joueur qui revient', async () => {
    leftBehind(player.id, 'a', 'b');
    asPlayer();
    renderApp('/');

    expect(await within(await slip()).findByLabelText('2 sélections')).toBeInTheDocument();
    expect(storedPicks()).toEqual(['xa', 'xb']);
    expect(localStorage.getItem(OWNER)).toBe(player.id);
  });

  it('abandonne le coupon d\'un autre compte quand un joueur se connecte', async () => {
    leftBehind('99', 'a');
    asPlayer();
    renderApp('/');

    await waitFor(() => expect(localStorage.getItem(SELECTIONS)).toBeNull());
    expect(localStorage.getItem(OWNER)).toBeNull();
    expect(within(await slip()).getByLabelText('0 sélection')).toBeInTheDocument();
  });

  it('remplace le coupon mis de côté dès que le visiteur en commence un', async () => {
    leftBehind('99', 'a');
    asGuest();
    renderApp('/');

    await addEverton();

    await waitFor(() => expect(storedPicks()).toEqual(['11']));
    expect(localStorage.getItem(OWNER)).toBeNull();
    expect(within(await slip()).getByLabelText('1 sélection')).toBeInTheDocument();
  });

  it('efface le coupon et son propriétaire à la déconnexion', async () => {
    leftBehind(player.id, 'a');
    let connected = true;
    asPlayer({
      'GET /me': () => (connected ? { status: 200, body: { data: player } } : guest),
      'POST /auth/logout': () => { connected = false; return { status: 204 }; },
    });
    renderApp('/account');

    await userEvent.click(await screen.findByRole('button', { name: 'Se déconnecter' }));

    await waitFor(() => expect(within(screen.getByRole('complementary', { name: 'Coupon' })).getByLabelText('0 sélection')).toBeInTheDocument());
    expect(localStorage.getItem(SELECTIONS)).toBeNull();
    expect(localStorage.getItem(OWNER)).toBeNull();
  });

  it('efface le coupon à la déconnexion depuis l\'administration', async () => {
    leftBehind(player.id, 'a');
    const admin = { ...player, is_admin: true, roles: ['support'], permissions: ['dashboard.view'], mfa: { enabled: true, verified: true, required: true } };
    mockApi({ 'GET /me': { status: 200, body: { data: admin } }, 'POST /auth/logout': { status: 204 } });
    const router = renderApp('/admin');

    await userEvent.click((await screen.findAllByRole('button', { name: 'Se déconnecter' }))[0]);

    await waitFor(() => expect(localStorage.getItem(SELECTIONS)).toBeNull());
    expect(localStorage.getItem(OWNER)).toBeNull();
    expect(router.state.location.pathname).toBe('/admin/login');
  });
});

describe('session fermée par le serveur', () => {
  it('ferme la session à l\'écran, oublie les données du compte et met le coupon de côté', async () => {
    leftBehind(player.id, 'a');
    let expired = false;
    asPlayer({
      'GET /me': () => (expired ? guest : { status: 200, body: { data: player } }),
      'GET /bets': () => (expired ? guest : { status: 200, body: paginated([]) }),
    });
    const router = renderApp('/bets');
    await screen.findByRole('heading', { name: 'MES PARIS', level: 1 });
    expect(await within(await slip()).findByLabelText('1 sélection')).toBeInTheDocument();

    // La session expire sur le serveur ; l'écran ne l'apprend qu'à la requête suivante.
    expired = true;
    await currentQueryClient().refetchQueries({ queryKey: ['bets'] });

    await waitFor(() => expect(router.state.location.pathname).toBe('/login'));
    expect(router.state.location.search).toBe('?redirect=%2Fbets');
    expect(within(await slip()).getByLabelText('0 sélection')).toBeInTheDocument();
    expect(currentQueryClient().getQueryData(['me'])).toBeNull();
    expect(currentQueryClient().getQueryCache().findAll().map((q) => q.queryKey[0])).not.toContain('wallet');
    // Le coupon attend son joueur : caché, pas effacé.
    expect(storedPicks()).toEqual(['xa']);
    expect(localStorage.getItem(OWNER)).toBe(player.id);
  });
});
