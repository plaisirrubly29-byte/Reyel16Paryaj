import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, player, renderApp } from '../../../test/renderApp';
import type { BetTicket } from '../api/bets';

beforeEach(() => localStorage.clear());
afterEach(() => vi.unstubAllGlobals());

const NBSP = new RegExp(`[${String.fromCharCode(0xa0, 0x202f)}]`, 'g');
const text = (el: Element | null) => (el?.textContent ?? '').replace(NBSP, ' ');
const inHours = (h: number) => new Date(Date.now() + h * 3_600_000).toISOString();

function event(id: string, home: string, away: string, odds: [string, string, string]) {
  return {
    id, sport: { slug: 'soccer', name: 'Football' }, competition: { id: '1', name: 'Premier League', country: 'Angleterre' },
    home_team: home, away_team: away, starts_at: inHours(5), status: 'scheduled',
    markets: [{
      id: `m${id}`, key: 'h2h', name: 'Résultat du match (1X2)', odds_version: 1, odds_updated_at: inHours(0), bettable: true,
      selections: [
        { id: `${id}1`, key: 'home', label: '1', name: home, odds: odds[0] },
        { id: `${id}2`, key: 'draw', label: 'X', name: 'Match nul', odds: odds[1] },
        { id: `${id}3`, key: 'away', label: '2', name: away, odds: odds[2] },
      ],
    }],
  };
}

const EVENTS = [event('1', 'Everton', 'Chelsea', ['2.00', '3.60', '2.05']), event('2', 'Arsenal', 'Leeds', ['1.50', '4.00', '6.00'])];

const paginated = (data: unknown[]) => ({
  data, links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 50, total: data.length, last_page: 1, from: 1, to: data.length },
});

function ticket(id: string, name: string, stakeMinor: number, odds: string, payoutMinor: number): BetTicket {
  return {
    id, reference: `R16-TICKET000${id}`, type: 'single', status: 'pending',
    stake: { minor: stakeMinor, currency: 'HTG' }, total_odds: odds, potential_payout: { minor: payoutMinor, currency: 'HTG' },
    payout: null, placed_at: '2026-11-02T10:00:00Z', settled_at: null,
    selections: [{
      id, event: { id, name: 'Match', starts_at: inHours(5) }, market: { key: 'h2h', name: 'Résultat du match (1X2)', line: null },
      selection: { key: 'home', label: '1', name }, odds, status: 'pending',
    }],
  };
}

type Handler = Parameters<typeof mockApi>[0][string];

function loggedIn(postBets: Handler) {
  return mockApi({
    'GET /me': { status: 200, body: { data: player } },
    'GET /sports': { status: 200, body: { data: [] } },
    'GET /events': { status: 200, body: paginated(EVENTS) },
    'GET /wallet': { status: 200, body: { data: { currency: 'HTG', balance: { minor: 100000, currency: 'HTG' }, status: 'active', simulated: false } } },
    'GET /bets': { status: 200, body: paginated([]) },
    'POST /bets': postBets,
  });
}

/** Ajoute Everton (2.00) et Arsenal (1.50) au coupon (boutons présents aussi dans « Top matchs »). */
async function pickTwo() {
  await userEvent.click((await screen.findAllByRole('button', { name: '1 Everton à 2.00' }))[0]);
  await userEvent.click(screen.getAllByRole('button', { name: '1 Arsenal à 1.50' })[0]);
  return screen.getByRole('complementary', { name: 'Coupon' });
}

const modeButton = (slip: HTMLElement, name: 'Simple' | 'Multiple') =>
  within(within(slip).getByRole('group', { name: 'Type de pari' })).getByRole('button', { name });

const postedBodies = (fetchMock: ReturnType<typeof mockApi>) => fetchMock.mock.calls
  .filter(([url, init]) => String(url).endsWith('/bets') && init?.method === 'POST')
  .map(([, init]) => ({ body: JSON.parse(String(init?.body)), key: (init?.headers as Record<string, string>)['Idempotency-Key'] }));

describe('coupon : simple ou multiple', () => {
  it('déduit le type : simple pour une sélection, multiple dès la deuxième', async () => {
    loggedIn({ status: 500 });
    renderApp('/');

    await userEvent.click((await screen.findAllByRole('button', { name: '1 Everton à 2.00' }))[0]);
    const slip = screen.getByRole('complementary', { name: 'Coupon' });
    expect(modeButton(slip, 'Simple')).toHaveAttribute('aria-pressed', 'true');
    expect(modeButton(slip, 'Multiple')).toBeDisabled();

    await userEvent.click(screen.getAllByRole('button', { name: '1 Arsenal à 1.50' })[0]);
    expect(modeButton(slip, 'Multiple')).toHaveAttribute('aria-pressed', 'true');
    expect(text(within(slip).getByText('Cote totale').nextElementSibling)).toBe('3.00');
  });

  it('place plusieurs paris simples, un ticket et une clé d\'idempotence chacun', async () => {
    let n = 0;
    const fetchMock = loggedIn((init) => {
      const body = JSON.parse(String(init?.body));
      n += 1;
      return { status: 201, body: { data: ticket(String(n), n === 1 ? 'Everton' : 'Arsenal', body.stake.minor, body.selections[0].odds, n === 1 ? 20000 : 15000) } };
    });
    renderApp('/');

    const slip = await pickTwo();
    await userEvent.type(within(slip).getByLabelText('Montant de la mise'), '100');
    await userEvent.click(modeButton(slip, 'Simple'));

    // La mise du combiné devient celle de chaque pari simple ; « Même mise pour tous » la remplace partout.
    expect(within(slip).getByLabelText('Mise sur Everton (Everton – Chelsea)')).toHaveValue('100');
    await userEvent.type(within(slip).getByLabelText('Même mise pour tous'), '100');
    expect(text(within(slip).getByText('Mise totale (2 paris)').nextElementSibling)).toBe('200,00 HTG');
    expect(text(within(slip).getByText('Gain potentiel total').nextElementSibling)).toBe('350,00 HTG');

    await userEvent.click(within(slip).getByRole('button', { name: 'Placer 2 paris' }));

    expect(await within(slip).findByText('2 paris placés')).toBeInTheDocument();
    expect(within(slip).getByText('R16-TICKET0001')).toBeInTheDocument();
    expect(within(slip).getByText('R16-TICKET0002')).toBeInTheDocument();
    const posts = postedBodies(fetchMock);
    expect(posts.map((p) => p.body)).toEqual([
      { stake: { minor: 10000, currency: 'HTG' }, selections: [{ selection_id: 11, odds: '2.00' }] },
      { stake: { minor: 10000, currency: 'HTG' }, selections: [{ selection_id: 21, odds: '1.50' }] },
    ]);
    expect(posts[0].key).not.toBe(posts[1].key);
  });

  it('garde dans le coupon la sélection refusée, avec son motif, sous les paris acceptés', async () => {
    let n = 0;
    loggedIn(() => (++n === 1
      ? { status: 201, body: { data: ticket('1', 'Everton', 5000, '2.00', 10000) } }
      : { status: 422, body: { error: { code: 'insufficient_funds', message: 'Solde insuffisant.' } } }));
    renderApp('/');

    const slip = await pickTwo();
    await userEvent.click(modeButton(slip, 'Simple'));
    await userEvent.type(within(slip).getByLabelText('Mise sur Everton (Everton – Chelsea)'), '50');
    expect(within(slip).getByRole('button', { name: 'Placer 2 paris' })).toBeDisabled();
    expect(within(slip).getByText('Saisissez une mise pour chaque sélection.')).toBeInTheDocument();
    await userEvent.type(within(slip).getByLabelText('Mise sur Arsenal (Arsenal – Leeds)'), '5000');
    await userEvent.click(within(slip).getByRole('button', { name: 'Placer 2 paris' }));

    expect(await within(slip).findByText(/1 pari placé sur 2/)).toBeInTheDocument();
    expect(within(slip).getByText('R16-TICKET0001')).toBeInTheDocument();
    expect(within(slip).getByText('Solde insuffisant pour cette mise.')).toBeInTheDocument();
    expect(within(slip).getByLabelText('1 sélection')).toBeInTheDocument();
    expect(within(slip).getByRole('button', { name: 'Retirer Arsenal (Arsenal – Leeds)' })).toBeInTheDocument();
  });

  it('signale deux sélections du même match dans un combiné et bloque le pari', async () => {
    const pick = (selectionId: string, marketId: string, label: string, pickName: string, odds: string) => ({
      selectionId, eventId: '1', marketId, oddsVersion: 1, odds, label, pick: pickName, match: 'Everton – Chelsea', startsAt: inHours(5),
    });
    localStorage.setItem('r16p.betslip', JSON.stringify([
      pick('11', 'm1', '1', 'Everton', '2.00'),
      { ...pick('15', 'm9', '+2.5', 'Plus de 2.5', '1.80'), market: 'Nombre de buts' },
    ]));
    loggedIn({ status: 500 });
    renderApp('/');

    const slip = await screen.findByRole('complementary', { name: 'Coupon' });
    expect(await within(slip).findAllByText("Même match : un combiné n'accepte qu'une sélection par match.")).toHaveLength(2);
    await userEvent.type(within(slip).getByLabelText('Montant de la mise'), '100');
    expect(within(slip).getByRole('button', { name: 'Placer mon pari' })).toBeDisabled();
    expect(within(slip).getByText('Retirez une des sélections du même match, ou passez en Simple.')).toBeInTheDocument();

    // En simple, deux paris séparés sur le même match sont permis.
    await userEvent.click(modeButton(slip, 'Simple'));
    expect(within(slip).queryByText(/Même match/)).toBeNull();
    expect(within(slip).getByRole('button', { name: 'Placer 2 paris' })).toBeEnabled();
  });
});
