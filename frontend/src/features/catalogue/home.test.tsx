import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { currentQueryClient, guest, mockApi, player, renderApp } from '../../test/renderApp';
import { formatKickoff } from './format';

beforeEach(() => localStorage.clear());
afterEach(() => vi.unstubAllGlobals());

/** Bouton de cote dans la liste d'une compétition (les cartes « Top matchs » reprennent les mêmes cotes). */
async function oddsButton(name: string, competition = 'Premier League') {
  return within(await screen.findByRole('region', { name: competition })).getByRole('button', { name });
}

const inHours = (h: number) => new Date(Date.now() + h * 3_600_000).toISOString();

const event = (id: string, home: string, away: string, odds: [string, string, string] | [string, string], sport = { slug: 'soccer', name: 'Football' }, competition = { id: '1', name: 'Premier League', country: 'Angleterre' }) => ({
  id,
  sport,
  competition,
  home_team: home,
  away_team: away,
  starts_at: inHours(Number(id) * 3),
  status: 'scheduled',
  markets: [{
    id: `m${id}`,
    key: 'h2h',
    name: 'Résultat du match (1X2)',
    odds_version: 2,
    odds_updated_at: new Date().toISOString(),
    bettable: true,
    selections: odds.length === 3
      ? [
          { id: `s${id}h`, key: 'home', label: '1', name: home, odds: odds[0] },
          { id: `s${id}d`, key: 'draw', label: 'X', name: 'Match nul', odds: odds[1] },
          { id: `s${id}a`, key: 'away', label: '2', name: away, odds: odds[2] },
        ]
      : [
          { id: `s${id}h`, key: 'home', label: '1', name: home, odds: odds[0] },
          { id: `s${id}a`, key: 'away', label: '2', name: away, odds: odds[1] },
        ],
  }],
});

const page = (data: unknown[], meta: Partial<{ current_page: number; total: number; last_page: number }> = {}) => ({
  data,
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 30, total: data.length, last_page: 1, from: 1, to: data.length, ...meta },
});

const slipEntry = (id: string, startsInHours: number) => ({
  selectionId: `x${id}`, eventId: `e${id}`, marketId: `m${id}`, oddsVersion: 1, odds: '2.00',
  label: '1', pick: `Équipe ${id}`, match: `Équipe ${id} – Autre ${id}`, startsAt: inHours(startsInHours),
});

const EVENTS = [
  event('1', 'Everton', 'Chelsea', ['2.95', '3.60', '2.05']),
  event('2', 'Fulham', 'Hull City', ['1.70', '3.75', '4.50']),
  event('3', 'Boston Celtics', 'Miami Heat', ['1.45', '2.80'], { slug: 'basketball', name: 'Basketball' }, { id: '2', name: 'NBA', country: 'États-Unis' }),
];

const SPORTS = [
  { slug: 'soccer', name: 'Football', events_count: 2, competitions: [] },
  { slug: 'basketball', name: 'Basketball', events_count: 1, competitions: [] },
];

function catalogue(user: unknown = guest) {
  return mockApi({
    'GET /me': user as never,
    'GET /health': { status: 200, body: { status: 'ok', version: 'v1', time: '' } },
    'GET /sports': { status: 200, body: { data: SPORTS } },
    'GET /events': (_, url) => ({
      status: 200,
      body: page(url.searchParams.get('sport') ? EVENTS.filter((e) => e.sport.slug === url.searchParams.get('sport')) : EVENTS),
    }),
  });
}

describe("page d'accueil : matchs", () => {
  it('affiche les matchs groupés par compétition avec les cotes 1 X 2', async () => {
    catalogue();
    renderApp('/');

    const epl = await screen.findByRole('region', { name: 'Premier League' });
    expect(within(epl).getByText('Everton')).toBeInTheDocument();
    expect(within(epl).getByRole('button', { name: '1 Everton à 2.95' })).toBeInTheDocument();
    expect(within(epl).getByRole('button', { name: 'X Match nul à 3.60' })).toBeInTheDocument();
    expect(within(epl).getByRole('button', { name: '2 Chelsea à 2.05' })).toBeInTheDocument();
    // Repère visuel du favori : la cote la plus basse du match (2.05 < 2.95 < 3.60).
    expect(within(epl).getByRole('button', { name: '2 Chelsea à 2.05' })).toHaveClass('favorite');
    expect(within(epl).getByRole('button', { name: '1 Everton à 2.95' })).not.toHaveClass('favorite');

    // Chaque match est une ligne : équipes, heure et cotes en barres (sans match nul au basket).
    expect(within(epl).getAllByRole('listitem')).toHaveLength(2);
    const nba = screen.getByRole('region', { name: 'NBA' });
    expect(within(nba).getAllByRole('button')).toHaveLength(2); // pas de match nul au basket

    // Les équipes mènent à la page du match (tous ses paris).
    expect(within(epl).getByText('Everton').closest('a')).toHaveAttribute('href', '/events/1');
  });

  it('filtre par sport et garde le choix dans l\'URL', async () => {
    const fetchMock = catalogue();
    const router = renderApp('/');

    await userEvent.click(await screen.findByRole('button', { name: /Basketball/ }));

    await waitFor(() => expect(router.state.location.search).toBe('?sport=basketball'));
    await waitFor(() => expect(screen.queryByRole('region', { name: 'Premier League' })).toBeNull());
    expect(screen.getByRole('region', { name: 'NBA' })).toBeInTheDocument();
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('/events?sport=basketball'))).toBe(true);
  });

  it('ajoute une cote au coupon, la remplace pour le même marché et la retire', async () => {
    catalogue();
    renderApp('/');

    await userEvent.click(await oddsButton('1 Everton à 2.95'));
    const slip = screen.getByRole('complementary', { name: 'Coupon' });
    expect(within(slip).getByText('1 · Everton')).toBeInTheDocument();
    expect(await oddsButton('1 Everton à 2.95')).toHaveAttribute('aria-pressed', 'true');

    // Un seul choix par marché : X remplace 1.
    await userEvent.click(await oddsButton('X Match nul à 3.60'));
    expect(within(slip).queryByText('1 · Everton')).toBeNull();
    expect(within(slip).getByText('X · Match nul')).toBeInTheDocument();

    // Deux matchs différents : deux sélections.
    await userEvent.click(await oddsButton('1 Fulham à 1.70'));
    expect(within(slip).getByLabelText('2 sélections')).toBeInTheDocument();

    await userEvent.click(within(slip).getByRole('button', { name: 'Retirer Fulham (Fulham – Hull City)' }));
    expect(within(slip).getByLabelText('1 sélection')).toBeInTheDocument();
  });

  it('affiche la cote totale du coupon et le gain potentiel selon la mise', async () => {
    catalogue();
    renderApp('/');

    await userEvent.click(await oddsButton('1 Everton à 2.95'));
    await userEvent.click(await oddsButton('1 Fulham à 1.70'));

    const slip = screen.getByRole('complementary', { name: 'Coupon' });
    // 2.95 × 1.70 = 5.015 → 5.02 (arrondi demi vers le haut, calcul exact).
    expect(within(slip).getByText('Cote totale').nextElementSibling).toHaveTextContent('5.02');

    await userEvent.type(within(slip).getByLabelText('Montant de la mise'), '100');
    // 100 HTG × 5.015 = 501,50 HTG.
    expect(within(slip).getByText('Gain potentiel').nextElementSibling).toHaveTextContent('501,50');
  });

  it('conserve le coupon avec la version de cote pour la revalidation serveur', async () => {
    catalogue();
    renderApp('/');

    await userEvent.click(await oddsButton('2 Chelsea à 2.05'));

    const stored = JSON.parse(localStorage.getItem('r16p.betslip') ?? '[]');
    expect(stored).toEqual([expect.objectContaining({ selectionId: 's1a', marketId: 'm1', oddsVersion: 2, odds: '2.05' })]);
  });

  it('invite un visiteur à se connecter pour parier', async () => {
    catalogue();
    renderApp('/');

    await userEvent.click(await oddsButton('1 Everton à 2.95'));

    expect(screen.getByRole('link', { name: 'Se connecter pour parier' })).toHaveAttribute('href', '/login');
  });

  it('affiche un match à la cote trop ancienne sans permettre de le parier', async () => {
    const stale = { ...EVENTS[0], markets: [{ ...EVENTS[0].markets[0], bettable: false }] };
    mockApi({
      'GET /me': guest,
      'GET /sports': { status: 200, body: { data: SPORTS } },
      'GET /events': { status: 200, body: page([stale, EVENTS[1]]) },
    });
    renderApp('/');

    const lockedBtn = await oddsButton('1 Everton à 2.95 (cote en cours de mise à jour)');
    expect(lockedBtn).toBeDisabled();
    await userEvent.click(lockedBtn);
    expect(within(screen.getByRole('complementary', { name: 'Coupon' })).getByLabelText('0 sélection')).toBeInTheDocument();
    expect(await oddsButton('1 Fulham à 1.70')).toBeEnabled();
  });

  it('annonce l\'absence de match ouvert', async () => {
    mockApi({
      'GET /me': guest,
      'GET /sports': { status: 200, body: { data: [] } },
      'GET /events': { status: 200, body: page([]) },
    });
    renderApp('/');

    expect(await screen.findByText(/Aucun match ouvert aux paris/)).toBeInTheDocument();
  });

  it('reste affichable si l\'API est indisponible', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));
    renderApp('/');

    expect(await screen.findByRole('heading', { level: 1 })).toHaveTextContent('paris sportifs');
    expect(screen.getByRole('link', { name: "S'inscrire" })).toHaveAttribute('href', '/register');
    // Panne réseau : 2 nouvelles tentatives automatiques (délais croissants) avant d'afficher l'erreur.
    expect(await screen.findByText('Connexion impossible', {}, { timeout: 8000 })).toBeInTheDocument();
  });

  it('propose le portefeuille à un joueur connecté', async () => {
    catalogue({ status: 200, body: { data: player } });
    renderApp('/');

    expect(await screen.findByRole('link', { name: 'Portefeuille' })).toHaveAttribute('href', '/wallet');
    expect(screen.queryByRole('link', { name: "S'inscrire" })).toBeNull();
  });
});

describe('coupon : règles', () => {
  it('refuse une 21e sélection avec un message, sans retirer la plus ancienne', async () => {
    localStorage.setItem('r16p.betslip', JSON.stringify(Array.from({ length: 20 }, (_, i) => slipEntry(String(i), 48))));
    catalogue();
    renderApp('/');

    await userEvent.click(await oddsButton('1 Everton à 2.95'));

    const slip = screen.getByRole('complementary', { name: 'Coupon' });
    expect(within(slip).getByText(/Coupon complet : 20 sélections au maximum/)).toBeInTheDocument();
    expect(within(slip).getByLabelText('20 sélections')).toBeInTheDocument();
    expect(within(slip).getByText('1 · Équipe 0')).toBeInTheDocument();
    expect(await oddsButton('1 Everton à 2.95')).toHaveAttribute('aria-pressed', 'false');
  });

  it('conserve les matchs commencés du coupon et écarte les données invalides au chargement', async () => {
    localStorage.setItem('r16p.betslip', JSON.stringify([slipEntry('old', -1), slipEntry('ok', 5), { pirate: true }]));
    catalogue();
    renderApp('/');

    const slip = await screen.findByRole('complementary', { name: 'Coupon' });
    expect(within(slip).getByLabelText('2 sélections')).toBeInTheDocument();
    expect(within(slip).getByText('1 · Équipe ok')).toBeInTheDocument();
    expect(within(slip).getByText('1 · Équipe old')).toBeInTheDocument();
  });

  it('vide le coupon à la déconnexion', async () => {
    localStorage.setItem('r16p.betslip', JSON.stringify([slipEntry('a', 5)]));
    let loggedIn = true;
    mockApi({
      'GET /me': () => (loggedIn ? { status: 200, body: { data: player } } : guest),
      'POST /auth/logout': () => { loggedIn = false; return { status: 204 }; },
      'GET /health': { status: 200, body: { status: 'ok', version: 'v1', time: '' } },
      'GET /sports': { status: 200, body: { data: SPORTS } },
      'GET /events': { status: 200, body: page(EVENTS) },
    });
    renderApp('/account');

    await userEvent.click(await screen.findByRole('button', { name: 'Se déconnecter' }));

    const slip = await screen.findByRole('complementary', { name: 'Coupon' });
    await waitFor(() => expect(within(slip).getByLabelText('0 sélection')).toBeInTheDocument());
    expect(JSON.parse(localStorage.getItem('r16p.betslip') ?? '[]')).toEqual([]);
  });

  it('nomme chaque bouton « Retirer » avec le match (plusieurs « Match nul »)', async () => {
    catalogue();
    renderApp('/');

    await userEvent.click(await oddsButton('X Match nul à 3.60'));
    await userEvent.click(await oddsButton('X Match nul à 3.75'));

    expect(screen.getByRole('button', { name: 'Retirer Match nul (Everton – Chelsea)' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Retirer Match nul (Fulham – Hull City)' })).toBeInTheDocument();
  });
});

describe('liste des matchs : robustesse et navigation', () => {
  it('garde les matchs affichés si un rafraîchissement échoue', async () => {
    let fail = false;
    mockApi({
      'GET /me': guest,
      'GET /sports': { status: 200, body: { data: SPORTS } },
      'GET /events': () => (fail
        ? { status: 429, body: { error: { code: 'too_many_requests', message: 'Trop de tentatives.' } } }
        : { status: 200, body: page(EVENTS) }),
    });
    renderApp('/');
    await oddsButton('1 Everton à 2.95');

    // Le rafraîchissement automatique (toutes les 60 s) échoue : erreur 4xx, sans nouvelle tentative.
    fail = true;
    await act(() => currentQueryClient().refetchQueries({ queryKey: ['catalogue', 'events'] }));

    expect(await screen.findByText(/Cotes non actualisées/)).toBeInTheDocument();
    expect(await oddsButton('1 Everton à 2.95')).toBeInTheDocument();
  });

  it("pagine tous les matchs sur l'accueil", async () => {
    const fetchMock = mockApi({
      'GET /me': guest,
      'GET /sports': { status: 200, body: { data: SPORTS } },
      'GET /events': (_, url) => ({
        status: 200,
        body: page(EVENTS, { current_page: Number(url.searchParams.get('page') ?? 1), total: 134, last_page: 3 }),
      }),
    });
    const router = renderApp('/');

    expect(await screen.findByRole('heading', { name: 'Tous les matchs' })).toBeInTheDocument();
    await userEvent.click(await screen.findByRole('button', { name: 'Suivant' }));

    await waitFor(() => expect(router.state.location.search).toBe('?page=2'));
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('page=2'))).toBe(true);
  });

  it('pagine la page Sports et garde la page dans l\'URL', async () => {
    const fetchMock = mockApi({
      'GET /me': guest,
      'GET /sports': { status: 200, body: { data: SPORTS } },
      'GET /events': (_, url) => ({
        status: 200,
        body: page(EVENTS, { current_page: Number(url.searchParams.get('page') ?? 1), total: 90, last_page: 3 }),
      }),
    });
    const router = renderApp('/sports');

    await userEvent.click(await screen.findByRole('button', { name: 'Suivant' }));

    await waitFor(() => expect(router.state.location.search).toBe('?page=2'));
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('page=2'))).toBe(true);
  });
});

describe('liste des matchs : type de pari', () => {
  /** Double chance d'un match de football (le basket n'en a pas : markets vide). */
  const withDoubleChance = (e: ReturnType<typeof event>) => ({
    ...e,
    markets: e.sport.slug !== 'soccer' ? [] : [{
      ...e.markets[0],
      id: `dc${e.id}`,
      key: 'double_chance',
      name: 'Double chance',
      selections: [
        { id: `dc${e.id}1x`, key: 'home_draw', label: '1X', name: `${e.home_team} ou nul`, odds: '1.27' },
        { id: `dc${e.id}12`, key: 'home_away', label: '12', name: `${e.home_team} ou ${e.away_team}`, odds: '1.30' },
        { id: `dc${e.id}x2`, key: 'draw_away', label: 'X2', name: `Nul ou ${e.away_team}`, odds: '1.66' },
      ],
    }],
  });

  it('affiche la double chance à la place du 1X2 et la garde dans l\'URL', async () => {
    const fetchMock = mockApi({
      'GET /me': guest,
      'GET /sports': { status: 200, body: { data: SPORTS } },
      'GET /events': (_, url) => ({
        status: 200,
        body: page(url.searchParams.get('market') === 'double_chance' ? EVENTS.map(withDoubleChance) : EVENTS),
      }),
    });
    const router = renderApp('/sports');

    expect(await oddsButton('1 Everton à 2.95')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: '1X2 — Résultat du match' })).toHaveAttribute('aria-pressed', 'true');

    await userEvent.click(screen.getByRole('button', { name: 'DC — Double chance' }));

    expect(await oddsButton('1X Everton ou nul à 1.27')).toBeInTheDocument();
    expect(await oddsButton('X2 Nul ou Chelsea à 1.66')).toBeInTheDocument();
    expect(within(screen.getByRole('region', { name: 'NBA' })).getByText('Pari non proposé pour ce match')).toBeInTheDocument();
    expect(router.state.location.search).toBe('?market=double_chance');
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('market=double_chance'))).toBe(true);

    // Le choix se fait au coupon comme pour le 1X2.
    await userEvent.click(await oddsButton('1X Everton ou nul à 1.27'));
    expect(within(screen.getByRole('complementary', { name: 'Coupon' })).getByText('1X · Everton ou nul')).toBeInTheDocument();
  });
});

describe('page Sports', () => {
  it('liste tous les matchs ouverts', async () => {
    catalogue();
    renderApp('/sports');

    expect(await screen.findByRole('heading', { name: 'Sports & matchs' })).toBeInTheDocument();
    expect(await screen.findByRole('region', { name: 'NBA' })).toBeInTheDocument();
  });
});

describe('formatKickoff', () => {
  it("indique « Aujourd'hui » et « Demain »", () => {
    const now = new Date(2026, 9, 16, 9, 0);
    expect(formatKickoff(new Date(2026, 9, 16, 15, 30).toISOString(), now)).toEqual({ day: "Aujourd'hui", time: '15:30' });
    expect(formatKickoff(new Date(2026, 9, 17, 8, 0).toISOString(), now).day).toBe('Demain');
    expect(formatKickoff(new Date(2026, 9, 18, 8, 0).toISOString(), now).day).toMatch(/18\/10/);
  });
});
