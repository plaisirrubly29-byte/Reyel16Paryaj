import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../shared/api/client';
import { formatMoney } from '../../shared/format/money';
import { guest, mockApi, player, renderApp } from '../../test/renderApp';
import { findEvent, latestResults, listBets, placeBet, upcomingEvents, winningSelections } from './api/simulator';
import { eventState, formatClock, resultLabel } from './format';

const FOOTBALL_PERIOD = 180_000;
/** Début d'un créneau de football, et son numéro. */
const START = Math.ceil(Date.parse('2026-11-08T12:00:00Z') / FOOTBALL_PERIOD) * FOOTBALL_PERIOD;
const SLOT = START / FOOTBALL_PERIOD;
const MATCH = `football-${SLOT}-0`;
const EMPTY_PAGE = { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } };

function backend(me: { status: number; body?: unknown } = guest) {
  return mockApi({
    'GET /me': me,
    'GET /sports': { status: 200, body: { data: [] } },
    'GET /events': { status: 200, body: EMPTY_PAGE },
    'GET /bets': { status: 200, body: EMPTY_PAGE },
    'GET /wallet': { status: 200, body: { data: { balance: { minor: 0, currency: 'HTG' } } } },
  });
}
const loggedIn = { status: 200, body: { data: player } };

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
  localStorage.clear();
});

describe('jeux virtuels désactivés (VITE_VIRTUAL_GAMES absent)', () => {
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

  it('ordinateur : lien dans la navigation principale, prochaines épreuves avec compte à rebours et cotes', async () => {
    screenSize(false);
    at(START - 60_000);
    backend();
    const router = renderApp('/');

    const nav = await screen.findByRole('navigation', { name: 'Navigation principale' });
    await userEvent.click(within(nav).getByRole('link', { name: 'Jeux virtuels' }));
    expect(router.state.location.pathname).toBe('/jeux-virtuels');

    expect(await screen.findByRole('heading', { level: 1, name: 'Jeux virtuels' })).toBeInTheDocument();
    expect(screen.queryByText(/Démonstration|fictifs/)).toBeNull();

    // Football par défaut : les 4 matchs de la prochaine journée, chacun avec ses cotes 1, X, 2.
    const upcoming = screen.getByRole('region', { name: 'Prochaines épreuves' });
    const first = findEvent(MATCH, new Date(START - 60_000))!;
    const link = within(upcoming).getAllByRole('link').find((a) => a.getAttribute('href') === `/jeux-virtuels/${MATCH}`);
    expect(link).toHaveTextContent(`${first.home_team} – ${first.away_team}`);
    expect(link).toHaveTextContent(first.markets[0].selections[0].odds);
    expect(within(upcoming).getAllByText('Départ dans 01:00').length).toBeGreaterThan(0);
    expect(screen.getByRole('region', { name: 'Derniers résultats' })).toBeInTheDocument();
    // Visiteur : pas de rubrique « Mes paris virtuels ».
    expect(screen.queryByRole('region', { name: 'Mes paris virtuels' })).toBeNull();
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

  it('visiteur : compte à rebours, cote au coupon, gain potentiel, invitation à se connecter', async () => {
    screenSize(false);
    at(START - 60_000);
    backend();
    renderApp(`/jeux-virtuels/${MATCH}`);
    const event = findEvent(MATCH, new Date(START - 60_000))!;
    const home = event.markets[0].selections[0];

    expect(await screen.findByRole('heading', { level: 1, name: `${event.home_team} contre ${event.away_team}` })).toBeInTheDocument();
    // Les mises ferment 10 s avant le départ : 50 s restantes.
    expect(screen.getByRole('timer')).toHaveTextContent('00:50');

    await userEvent.click(screen.getByRole('button', { name: `${home.name} à ${home.odds}` }));
    expect(screen.getByRole('button', { name: `${home.name} à ${home.odds}` })).toHaveAttribute('aria-pressed', 'true');
    const coupon = screen.getByRole('region', { name: 'Coupon' });
    expect(within(coupon).getByText(home.name)).toBeInTheDocument();

    await userEvent.type(within(coupon).getByLabelText('Mise (HTG)'), '100');
    const gain = formatMoney({ minor: Math.round(10_000 * Number(home.odds)), currency: 'HTG' });
    expect(within(coupon).getByText((_, el) => el?.classList.contains('money') === true && el.textContent === gain)).toBeInTheDocument();
    expect(within(coupon).getByRole('link', { name: 'Se connecter pour parier' }))
      .toHaveAttribute('href', `/login?redirect=${encodeURIComponent(`/jeux-virtuels/${MATCH}`)}`);

    await userEvent.click(within(coupon).getByRole('button', { name: `Retirer ${home.name}` }));
    expect(within(coupon).getByText(/Touchez une cote/)).toBeInTheDocument();
  });

  it('joueur connecté : le pari est accepté avec une référence et apparaît dans ses paris', async () => {
    screenSize(false);
    at(START - 60_000);
    backend(loggedIn);
    renderApp(`/jeux-virtuels/${MATCH}`);
    const draw = findEvent(MATCH, new Date(START - 60_000))!.markets[0].selections[1];

    await userEvent.click(await screen.findByRole('button', { name: `${draw.name} à ${draw.odds}` }));
    const coupon = screen.getByRole('region', { name: 'Coupon' });
    await userEvent.type(within(coupon).getByLabelText('Mise (HTG)'), '250');
    await userEvent.click(within(coupon).getByRole('button', { name: 'Placer le pari' }));

    const accepted = await within(coupon).findByRole('status');
    expect(accepted).toHaveTextContent(/Pari accepté.*Référence JV-[0-9A-Z]{6}/);
    expect(within(coupon).getByRole('button', { name: 'Placer le pari' })).toBeDisabled();

    const bets = listBets(player.id, new Date(START - 60_000));
    expect(bets).toHaveLength(1);
    expect(bets[0]).toMatchObject({ status: 'pending', odds: draw.odds, stake: { minor: 25_000, currency: 'HTG' }, selection: { name: 'Match nul' } });
  });

  it('« Mes paris » liste les paris virtuels, réglés une fois l\'épreuve terminée', async () => {
    const before = new Date(START - 60_000);
    const event = findEvent(MATCH, before)!;
    placeBet(player.id, { event_id: MATCH, selection_id: event.markets[0].selections[0].id, stake_minor: 10_000 }, 'k1', before);
    placeBet(player.id, { event_id: MATCH, selection_id: event.markets[0].selections[2].id, stake_minor: 10_000 }, 'k2', before);

    screenSize(false);
    at(START + 120_000);
    backend(loggedIn);
    renderApp('/bets');
    // Onglet « Jeux virtuels » de Mes paris (à côté de « Sport »).
    await userEvent.click(await screen.findByRole('button', { name: 'Jeux virtuels' }));

    const section = await screen.findByRole('region', { name: 'Jeux virtuels' });
    const winners = winningSelections(findEvent(MATCH, new Date(START + 120_000))!);
    const expected = [event.markets[0].selections[2], event.markets[0].selections[0]]
      .map((s) => (winners.has(s.id) ? 'Gagné' : 'Perdu'));
    const statuses = await within(section).findAllByText(/^(Gagné|Perdu)$/);
    expect(statuses.map((s) => s.textContent)).toEqual(expected);
  });

  it('épreuve en cours : direct avec la minute, mises fermées', async () => {
    screenSize(false);
    at(START + 45_000);
    backend();
    renderApp(`/jeux-virtuels/${MATCH}`);

    const stage = await screen.findByRole('region', { name: 'Direct' });
    expect(stage).toHaveTextContent("En direct · 45'");
    expect(within(stage).getByRole('progressbar')).toHaveAttribute('aria-valuenow', '50');
    for (const button of within(screen.getByRole('group', { name: 'Résultat du match' })).getAllByRole('button')) {
      expect(button).toBeDisabled();
    }
  });

  it('épreuve terminée : score affiché', async () => {
    screenSize(false);
    at(START + 100_000);
    backend();
    renderApp(`/jeux-virtuels/football-${SLOT}-1`);
    const result = findEvent(`football-${SLOT}-1`, new Date(START + 100_000))!.result;

    expect(await screen.findByText('Terminé')).toBeInTheDocument();
    expect(screen.getByText('Match terminé')).toBeInTheDocument();
    expect(result?.kind).toBe('football');
    if (result?.kind === 'football') {
      expect(screen.getByLabelText(`Score : ${result.home} à ${result.away}`)).toBeInTheDocument();
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

describe('simulateur du prestataire', () => {
  const now = new Date(START - 60_000);

  it('programme reproductible : épreuves non terminées, 8 partants par course', () => {
    expect(upcomingEvents('football', now)).toEqual(upcomingEvents('football', now));
    expect(upcomingEvents('football', now).every((e) => e.status !== 'finished')).toBe(true);
    expect(upcomingEvents('courses', now).every((e) => e.runners.length === 8)).toBe(true);
  });

  it('résultats : score cohérent avec le direct, arrivée de 3 chevaux différents', () => {
    for (const e of latestResults('football', now)) {
      expect(e.result?.kind).toBe('football');
      const live = findEvent(e.id, new Date(Date.parse(e.ends_at) - 1))!.live;
      if (live?.kind === 'football' && e.result?.kind === 'football') {
        expect(live.home).toBeLessThanOrEqual(e.result.home);
        expect(live.away).toBeLessThanOrEqual(e.result.away);
      }
    }
    for (const race of latestResults('courses', now)) {
      if (race.result?.kind === 'courses') expect(new Set(race.result.podium.map((r) => r.number)).size).toBe(3);
    }
  });

  it('cotes en chaînes décimales, comme celles de l\'API', () => {
    for (const s of upcomingEvents('courses', now).flatMap((e) => e.markets.flatMap((m) => m.selections))) {
      expect(s.odds).toMatch(/^\d+\.\d{2}$/);
    }
  });

  it('refuse un pari après la fermeture des mises ou sous la mise minimale', () => {
    const selection = findEvent(MATCH, now)!.markets[0].selections[0].id;
    const closed = () => placeBet('9', { event_id: MATCH, selection_id: selection, stake_minor: 1000 }, 'a', new Date(START - 5_000));
    expect(closed).toThrow(ApiError);
    expect(closed).toThrow('Les mises sont fermées pour cette épreuve.');
    expect(() => placeBet('9', { event_id: MATCH, selection_id: selection, stake_minor: 50 }, 'b', now)).toThrow('La mise minimale est de 1 gourde.');
    expect(() => placeBet('9', { event_id: MATCH, selection_id: 'inconnue', stake_minor: 1000 }, 'c', now)).toThrow('Cette cote n\'est plus proposée.');
  });

  it('même clé d\'idempotence : un seul pari ; règlement gagné ou perdu avec le gain', () => {
    const event = findEvent(MATCH, now)!;
    const input = { event_id: MATCH, selection_id: event.markets[1].selections[0].id, stake_minor: 2_000 };
    const first = placeBet('9', input, 'same', now);
    expect(placeBet('9', input, 'same', now).id).toBe(first.id);
    expect(listBets('9', now)).toHaveLength(1);

    const after = new Date(START + 100_000);
    const [settled] = listBets('9', after);
    const won = winningSelections(findEvent(MATCH, after)!).has(input.selection_id);
    expect(settled.status).toBe(won ? 'won' : 'lost');
    expect(settled.payout).toEqual(won ? first.potential_payout : { minor: 0, currency: 'HTG' });
  });
});

describe('mise en forme', () => {
  it('compte à rebours', () => {
    expect(formatClock(65_000)).toBe('01:05');
    expect(formatClock(3_725_000)).toBe('1:02:05');
    expect(formatClock(-5_000)).toBe('00:00');
  });

  it('état et résultat', () => {
    const event = findEvent(MATCH, new Date(START - 60_000))!;
    expect(eventState(event, START - 60_000)).toEqual({ label: 'Départ dans 01:00', tone: 'open' });
    expect(eventState(event, START - 5_000).tone).toBe('closed');
    expect(resultLabel({ kind: 'football', home: 2, away: 1 })).toBe('2 - 1');
    expect(resultLabel({ kind: 'courses', podium: [{ number: 4, name: 'A' }, { number: 7, name: 'B' }, { number: 1, name: 'C' }] }))
      .toBe('1er 4 A, 2e 7 B, 3e 1 C');
  });
});
