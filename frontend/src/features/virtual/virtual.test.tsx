import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { formatMoney } from '../../shared/format/money';
import { guest, mockApi, renderApp } from '../../test/renderApp';
import { demoEvent, demoEvents, demoResults } from './api/demo';
import { eventState, formatClock, resultLabel } from './format';

const FOOTBALL_PERIOD = 180_000;
/** Début d'un créneau de football de démonstration, et son numéro. */
const START = Math.ceil(Date.parse('2026-11-08T12:00:00Z') / FOOTBALL_PERIOD) * FOOTBALL_PERIOD;
const SLOT = START / FOOTBALL_PERIOD;

function backend() {
  return mockApi({
    'GET /me': guest,
    'GET /sports': { status: 200, body: { data: [] } },
    'GET /events': { status: 200, body: { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } } },
  });
}

/** Écran de téléphone (barre d'onglets) ou d'ordinateur. */
function screenSize(mobile: boolean) {
  vi.stubGlobal('matchMedia', (query: string) => ({
    matches: mobile && query.includes('max-width'),
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
  }));
}

/** Seule l'horloge (Date) est figée : les minuteries restent réelles pour React Query et Testing Library. */
function at(ms: number) {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(ms);
}

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe('jeux virtuels désactivés (par défaut, D-11)', () => {
  it('les pages répondent « introuvable » et le menu ne propose pas le lien', async () => {
    screenSize(false);
    backend();
    renderApp('/jeux-virtuels');

    expect(await screen.findByRole('heading', { name: "Cette page n'existe pas" })).toBeInTheDocument();
    const nav = screen.getByRole('navigation', { name: 'Navigation principale' });
    expect(within(nav).queryByRole('link', { name: /Jeux virtuels/ })).toBeNull();
  });
});

describe('jeux virtuels activés (VITE_VIRTUAL_GAMES=true)', () => {
  beforeEach(() => { vi.stubEnv('VITE_VIRTUAL_GAMES', 'true'); });

  it('ordinateur : lien dans la navigation principale, accueil annoncé comme démonstration', async () => {
    screenSize(false);
    at(START - 60_000);
    backend();
    const router = renderApp('/');

    const nav = await screen.findByRole('navigation', { name: 'Navigation principale' });
    await userEvent.click(within(nav).getByRole('link', { name: /Jeux virtuels/ }));
    expect(router.state.location.pathname).toBe('/jeux-virtuels');

    expect(await screen.findByRole('heading', { level: 1, name: 'Jeux virtuels' })).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent(/Démonstration.*fictifs.*Aucun pari ne peut être placé/);

    // Football par défaut : les 4 matchs de la prochaine journée, chacun avec ses cotes 1, X, 2.
    const upcoming = screen.getByRole('region', { name: 'Prochaines épreuves' });
    const first = demoEvent(`football-${SLOT}-0`, new Date(START - 60_000))!;
    const link = within(upcoming).getAllByRole('link').find((a) => a.getAttribute('href') === `/jeux-virtuels/football-${SLOT}-0`);
    expect(link).toHaveTextContent(`${first.home_team} – ${first.away_team}`);
    expect(link).toHaveTextContent(first.markets[0].selections[0].odds);
    expect(within(upcoming).getAllByText('Départ dans 01:00').length).toBeGreaterThan(0);
    expect(screen.getByRole('region', { name: 'Derniers résultats' })).toBeInTheDocument();
  });

  it('change de jeu : les courses listent leurs partants et le choix reste dans l\'URL', async () => {
    screenSize(false);
    backend();
    const router = renderApp('/jeux-virtuels');

    await userEvent.click(await screen.findByRole('button', { name: /Courses virtuelles/ }));
    expect(screen.getByRole('button', { name: /Courses virtuelles/ })).toHaveAttribute('aria-pressed', 'true');
    expect(router.state.location.search).toBe('?jeu=courses');
    const upcoming = screen.getByRole('region', { name: 'Prochaines épreuves' });
    expect(within(upcoming).getAllByRole('link', { name: /8 partants/ }).length).toBeGreaterThan(0);
  });

  it('mobile : le menu « Jeux » ouvre les jeux virtuels', async () => {
    screenSize(true);
    backend();
    const router = renderApp('/');

    const bar = await screen.findByRole('navigation', { name: 'Navigation mobile' });
    await userEvent.click(within(bar).getByRole('button', { name: 'Jeux' }));
    const menu = screen.getByRole('dialog', { name: 'Jeux' });
    expect(within(menu).getByText('Jeux virtuels').closest('.game-tile')).not.toHaveTextContent('Bientôt');
    await userEvent.click(within(menu).getByRole('link', { name: /^Jeux virtuels/ }));
    expect(router.state.location.pathname).toBe('/jeux-virtuels');
  });

  it("page d'une épreuve : compte à rebours, cote ajoutée au coupon, gain indicatif, envoi désactivé (démo)", async () => {
    screenSize(false);
    at(START - 60_000);
    backend();
    renderApp(`/jeux-virtuels/football-${SLOT}-0`);
    const event = demoEvent(`football-${SLOT}-0`, new Date(START - 60_000))!;
    const home = event.markets[0].selections[0];

    expect(await screen.findByRole('heading', { level: 1, name: `${event.home_team} contre ${event.away_team}` })).toBeInTheDocument();
    // Les mises ferment 10 s avant le départ (démo) : 50 s restantes.
    expect(screen.getByRole('timer')).toHaveTextContent('00:50');

    await userEvent.click(screen.getByRole('button', { name: `${home.name} à ${home.odds}` }));
    expect(screen.getByRole('button', { name: `${home.name} à ${home.odds}` })).toHaveAttribute('aria-pressed', 'true');
    const coupon = screen.getByRole('region', { name: 'Coupon' });
    expect(within(coupon).getByText(home.name)).toBeInTheDocument();

    await userEvent.type(within(coupon).getByLabelText('Mise (HTG)'), '100');
    const gain = formatMoney({ minor: Math.round(10_000 * Number(home.odds)), currency: 'HTG' });
    expect(within(coupon).getByText((_, el) => el?.classList.contains('money') === true && el.textContent === gain)).toBeInTheDocument();
    expect(within(coupon).getByRole('button', { name: 'Placer le pari' })).toBeDisabled();
    expect(coupon).toHaveTextContent('Démonstration : les paris sur les jeux virtuels ne sont pas encore ouverts.');

    await userEvent.click(within(coupon).getByRole('button', { name: `Retirer ${home.name}` }));
    expect(within(coupon).getByText(/Touchez une cote/)).toBeInTheDocument();
  });

  it('épreuve terminée : score affiché, cotes fermées', async () => {
    screenSize(false);
    at(START + 100_000);
    backend();
    renderApp(`/jeux-virtuels/football-${SLOT}-1`);
    const event = demoEvent(`football-${SLOT}-1`, new Date(START + 100_000))!;

    expect(await screen.findByText('Terminé')).toBeInTheDocument();
    expect(event.result?.kind).toBe('football');
    if (event.result?.kind === 'football') {
      expect(screen.getByLabelText(`Score : ${event.result.home} à ${event.result.away}`)).toBeInTheDocument();
    }
    for (const button of within(screen.getByRole('group', { name: 'Résultat du match' })).getAllByRole('button')) {
      expect(button).toBeDisabled();
    }
  });

  it('épreuve inconnue : message et retour vers la liste', async () => {
    screenSize(false);
    backend();
    renderApp('/jeux-virtuels/inconnue');

    expect(await screen.findByText("Cette épreuve n'existe pas")).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Voir les jeux virtuels' })).toHaveAttribute('href', '/jeux-virtuels');
  });
});

describe('données de démonstration', () => {
  const now = new Date(START - 60_000);

  it('sont reproductibles et ne listent que des épreuves non terminées', () => {
    expect(demoEvents('football', now)).toEqual(demoEvents('football', now));
    expect(demoEvents('football', now).every((e) => e.status !== 'finished')).toBe(true);
    expect(demoEvents('courses', now).every((e) => e.runners.length === 8)).toBe(true);
  });

  it('les résultats sont terminés, avec un score ou une arrivée de 3 chevaux différents', () => {
    const football = demoResults('football', now);
    expect(football.every((e) => e.status === 'finished' && e.result?.kind === 'football')).toBe(true);
    for (const race of demoResults('courses', now)) {
      expect(race.result?.kind).toBe('courses');
      if (race.result?.kind === 'courses') expect(new Set(race.result.podium.map((r) => r.number)).size).toBe(3);
    }
  });

  it('les cotes sont des chaînes décimales, comme celles de l\'API', () => {
    for (const e of demoEvents('courses', now)) {
      for (const s of e.markets.flatMap((m) => m.selections)) expect(s.odds).toMatch(/^\d+\.\d{2}$/);
    }
  });
});

describe('mise en forme', () => {
  it('compte à rebours', () => {
    expect(formatClock(65_000)).toBe('01:05');
    expect(formatClock(3_725_000)).toBe('1:02:05');
    expect(formatClock(-5_000)).toBe('00:00');
  });

  it('état et résultat', () => {
    const event = demoEvent(`football-${SLOT}-0`, new Date(START - 60_000))!;
    expect(eventState(event, START - 60_000)).toEqual({ label: 'Départ dans 01:00', tone: 'open' });
    expect(eventState(event, START - 5_000).tone).toBe('closed');
    expect(resultLabel({ kind: 'football', home: 2, away: 1 })).toBe('2 - 1');
    expect(resultLabel({ kind: 'courses', podium: [{ number: 4, name: 'A' }, { number: 7, name: 'B' }, { number: 1, name: 'C' }] }))
      .toBe('1er 4 A, 2e 7 B, 3e 1 C');
  });
});
