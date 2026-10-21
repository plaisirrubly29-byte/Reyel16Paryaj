import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, player, renderApp } from '../../../test/renderApp';
import { addTicket, generateBetCode, loadTickets, type Ticket } from './ticketStore';

beforeEach(() => localStorage.clear());
afterEach(() => vi.unstubAllGlobals());

const inHours = (h: number) => new Date(Date.now() + h * 3_600_000).toISOString();

const EVENTS = [
  {
    id: '1', sport: { slug: 'soccer', name: 'Football' }, competition: { id: '1', name: 'Premier League', country: 'Angleterre' },
    home_team: 'Everton', away_team: 'Chelsea', starts_at: inHours(5), status: 'scheduled',
    markets: [{
      id: 'm1', key: 'h2h', name: 'Résultat du match (1X2)', odds_version: 2, odds_updated_at: inHours(0), bettable: true,
      selections: [
        { id: 's1', key: 'home', label: '1', name: 'Everton', odds: '2.95' },
        { id: 's2', key: 'draw', label: 'X', name: 'Match nul', odds: '3.60' },
        { id: 's3', key: 'away', label: '2', name: 'Chelsea', odds: '2.05' },
      ],
    }],
  },
];

const page = { data: EVENTS, links: { first: null, last: null, prev: null, next: null }, meta: { current_page: 1, per_page: 50, total: 1, last_page: 1, from: 1, to: 1 } };

function loggedIn() {
  return mockApi({
    'GET /me': { status: 200, body: { data: player } },
    'GET /sports': { status: 200, body: { data: [] } },
    'GET /events': { status: 200, body: page },
  });
}

const ticket: Ticket = {
  code: 'ABCD2345',
  placedAt: '2026-10-16T10:00:00.000Z',
  stakeMinor: 10000,
  potentialMinor: 29500,
  combinedOdds: '2.95',
  selections: [{ match: 'Everton – Chelsea', pick: 'Everton', label: '1', odds: '2.95', market: 'Résultat du match', startsAt: inHours(5) }],
};

describe('ticketStore', () => {
  it('génère un betcode de 8 caractères sans caractères ambigus', () => {
    const code = generateBetCode();
    expect(code).toMatch(/^[A-HJ-NP-Z2-9]{8}$/);
  });

  it('garde les tickets par joueur, les plus récents d\'abord', () => {
    addTicket('1', ticket);
    addTicket('1', { ...ticket, code: 'ZZZZ2222' });
    addTicket('2', ticket);

    expect(loadTickets('1').map((t) => t.code)).toEqual(['ZZZZ2222', 'ABCD2345']);
    expect(loadTickets('2')).toHaveLength(1);
  });

  it('ignore un stockage illisible ou corrompu', () => {
    localStorage.setItem('r16p.tickets.1', '{pas du json');
    expect(loadTickets('1')).toEqual([]);

    localStorage.setItem('r16p.tickets.1', JSON.stringify([{ pirate: true }, ticket]));
    expect(loadTickets('1')).toEqual([ticket]);
  });
});

describe('placer un pari', () => {
  it('place le ticket, affiche le betcode et vide le coupon', async () => {
    loggedIn();
    renderApp('/');

    await userEvent.click(await screen.findByRole('button', { name: '1 Everton à 2.95' }));
    const slip = screen.getByRole('complementary', { name: 'Coupon' });
    const place = within(slip).getByRole('button', { name: 'Placer mon pari' });

    // Sans mise, le pari ne peut pas être placé.
    expect(place).toBeDisabled();

    await userEvent.type(within(slip).getByLabelText('Montant de la mise'), '100');
    await userEvent.click(within(slip).getByRole('button', { name: 'Placer mon pari' }));

    expect(await within(slip).findByText('Pari placé')).toBeInTheDocument();
    const [saved] = loadTickets('1');
    expect(saved).toMatchObject({ stakeMinor: 10000, potentialMinor: 29500, combinedOdds: '2.95' });
    expect(within(slip).getByText(saved.code)).toBeInTheDocument();
    expect(within(slip).getByRole('link', { name: 'WhatsApp' })).toHaveAttribute('href', expect.stringContaining('wa.me'));

    // Un nouveau pari repart à zéro : la confirmation disparaît et la mise est vide.
    // Le même match figure dans les « Top matchs » et dans la liste : on prend le premier bouton.
    await userEvent.click((await screen.findAllByRole('button', { name: '2 Chelsea à 2.05' }))[0]);
    expect(within(slip).queryByText('Pari placé')).toBeNull();
    expect(within(slip).getByLabelText('Montant de la mise')).toHaveValue('');
  });

  it('affiche le ticket dans MES PARIS', async () => {
    localStorage.setItem('r16p.tickets.1', JSON.stringify([ticket]));
    loggedIn();
    renderApp('/bets');

    expect(await screen.findByRole('heading', { name: 'MES PARIS' })).toBeInTheDocument();
    expect(screen.getByText('ABCD2345')).toBeInTheDocument();
    expect(screen.getByText(/Everton – Chelsea/)).toBeInTheDocument();
  });

  it('affiche un message quand le joueur n\'a encore aucun pari', async () => {
    loggedIn();
    renderApp('/bets');

    await waitFor(() => expect(screen.getByText("Vous n'avez encore aucun pari")).toBeInTheDocument());
  });
});
