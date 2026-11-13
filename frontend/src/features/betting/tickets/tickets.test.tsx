import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, player, renderApp } from '../../../test/renderApp';
import type { BetTicket } from '../api/bets';

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
        { id: '11', key: 'home', label: '1', name: 'Everton', odds: '2.95' },
        { id: '12', key: 'draw', label: 'X', name: 'Match nul', odds: '3.60' },
        { id: '13', key: 'away', label: '2', name: 'Chelsea', odds: '2.05' },
      ],
    }],
  },
];

const paginated = (data: unknown[]) => ({
  data, links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 50, total: data.length, last_page: 1, from: 1, to: data.length },
});

const ticket: BetTicket = {
  id: '7',
  reference: 'R16-ABCD2345EF',
  type: 'single',
  status: 'pending',
  stake: { minor: 10000, currency: 'HTG' },
  total_odds: '2.95',
  potential_payout: { minor: 29500, currency: 'HTG' },
  payout: null,
  placed_at: '2026-10-26T10:00:00Z',
  settled_at: null,
  selections: [{
    id: '1', event: { id: '1', name: 'Everton – Chelsea', starts_at: inHours(5) },
    market: { key: 'h2h', name: 'Résultat du match (1X2)', line: null },
    selection: { key: 'home', label: '1', name: 'Everton' }, odds: '2.95', status: 'pending',
  }],
};

type Handler = Parameters<typeof mockApi>[0][string];

function loggedIn(postBets: Handler = { status: 201, body: { data: ticket } }, bets: unknown[] = []) {
  return mockApi({
    'GET /me': { status: 200, body: { data: player } },
    'GET /sports': { status: 200, body: { data: [] } },
    'GET /events': { status: 200, body: paginated(EVENTS) },
    'GET /wallet': { status: 200, body: { data: { currency: 'HTG', balance: { minor: 100000, currency: 'HTG' }, status: 'active', simulated: true } } },
    'GET /bets': { status: 200, body: paginated(bets) },
    'POST /bets': postBets,
  });
}

/** Choisit Everton à 2.95, saisit la mise et clique sur « Placer mon pari ». */
async function placeEvertonBet(stake = '100') {
  await userEvent.click(await screen.findByRole('button', { name: '1 Everton à 2.95' }));
  const slip = screen.getByRole('complementary', { name: 'Coupon' });
  expect(within(slip).getByRole('button', { name: 'Placer mon pari' })).toBeDisabled(); // sans mise

  await userEvent.type(within(slip).getByLabelText('Montant de la mise'), stake);
  await userEvent.click(within(slip).getByRole('button', { name: 'Placer mon pari' }));
  return slip;
}

describe('placer un pari', () => {
  it('envoie le coupon au serveur avec une clé d\'idempotence et affiche le reçu', async () => {
    const fetchMock = loggedIn();
    renderApp('/');

    const slip = await placeEvertonBet();

    expect(await within(slip).findByText('Pari placé')).toBeInTheDocument();
    expect(within(slip).getByText('R16-ABCD2345EF')).toBeInTheDocument();
    expect(within(slip).getByText(/Mise de 100,00.*débitée/)).toBeInTheDocument();
    expect(within(slip).getByRole('link', { name: 'WhatsApp' })).toHaveAttribute('href', expect.stringContaining('wa.me'));

    const call = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/bets') && init?.method === 'POST');
    const init = call?.[1] as RequestInit;
    expect(JSON.parse(String(init.body))).toEqual({
      stake: { minor: 10000, currency: 'HTG' },
      selections: [{ selection_id: 11, odds: '2.95' }],
    });
    expect((init.headers as Record<string, string>)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);

    // Un nouveau pari repart à zéro : la confirmation disparaît et la mise est vide.
    await userEvent.click((await screen.findAllByRole('button', { name: '2 Chelsea à 2.05' }))[0]);
    expect(within(slip).queryByText('Pari placé')).toBeNull();
    expect(within(slip).getByLabelText('Montant de la mise')).toHaveValue('');
  });

  it('met à jour une cote changée et demande de confirmer', async () => {
    let calls = 0;
    loggedIn(() => (++calls === 1
      ? { status: 409, body: { error: { code: 'odds_changed', message: 'La cote a changé.', details: { selections: [{ selection_id: '11', odds: '2.70', odds_version: 3 }] } } } }
      : { status: 201, body: { data: { ...ticket, total_odds: '2.70' } } }));
    renderApp('/');

    const slip = await placeEvertonBet();

    expect(await within(slip).findByText(/La cote a changé/)).toBeInTheDocument();
    expect(within(slip).getByText('Cote totale').nextElementSibling).toHaveTextContent('2.70');
    expect(within(slip).queryByText('Pari placé')).toBeNull();

    // Le joueur confirme : le pari part avec la nouvelle cote.
    await userEvent.click(within(slip).getByRole('button', { name: 'Placer mon pari' }));
    expect(await within(slip).findByText('Pari placé')).toBeInTheDocument();
  });

  it('retire du coupon une sélection que le serveur ne propose plus', async () => {
    loggedIn({ status: 422, body: { error: { code: 'selection_unavailable', message: 'Plus proposée.', details: { selection_ids: ['11'] } } } });
    renderApp('/');

    const slip = await placeEvertonBet();

    expect(await within(slip).findByText(/ne sont plus proposées et ont été retirées/)).toBeInTheDocument();
    expect(within(slip).getByLabelText('0 sélection')).toBeInTheDocument();
  });

  it('explique un refus (solde insuffisant, mise hors limites) sans vider le coupon', async () => {
    loggedIn({ status: 422, body: { error: { code: 'insufficient_funds', message: 'Solde insuffisant.' } } });
    renderApp('/');

    const slip = await placeEvertonBet('5000');

    expect(await within(slip).findByText('Solde insuffisant pour cette mise.')).toBeInTheDocument();
    expect(within(slip).getByLabelText('1 sélection')).toBeInTheDocument();
  });

  it('indique les limites de mise du serveur', async () => {
    loggedIn({ status: 422, body: { error: { code: 'stake_out_of_range', message: 'Mise hors limites.', details: { min: { minor: 1000, currency: 'HTG' }, max: { minor: 5000000, currency: 'HTG' } } } } });
    renderApp('/');

    const slip = await placeEvertonBet('5');

    expect(await within(slip).findByText(/La mise doit être comprise entre 10,00.*et 50.000,00/)).toBeInTheDocument();
  });

  it('montre au joueur sa propre mise maximale, posée par un administrateur (V2-D05)', async () => {
    loggedIn({ status: 422, body: { error: { code: 'stake_out_of_range', message: 'Mise hors limites.', details: { min: { minor: 1000, currency: 'HTG' }, max: { minor: 50000, currency: 'HTG' } } } } });
    renderApp('/');

    const slip = await placeEvertonBet('1000');

    expect(await within(slip).findByText(/La mise doit être comprise entre 10,00.*et 500,00/)).toBeInTheDocument();
    expect(within(slip).getByLabelText('1 sélection')).toBeInTheDocument();
  });

  it('refuse un pari au-delà du plafond du match sans révéler le plafond, et garde le coupon (V2-D05)', async () => {
    loggedIn({
      status: 422,
      body: { error: {
        code: 'risk_limit_exceeded',
        message: 'Ce pari dépasse le montant que nous acceptons sur ce match : réduisez la mise ou choisissez une autre sélection.',
        details: { scope: 'event', event_id: '1' },
      } },
    });
    renderApp('/');

    const slip = await placeEvertonBet('5000');

    expect(await within(slip).findByText(/Ce pari dépasse le montant que nous acceptons sur ce match/)).toBeInTheDocument();
    expect(within(slip).getByLabelText('1 sélection')).toBeInTheDocument();
    expect(within(slip).queryByText(/plafond/i)).toBeNull();
  });
});

describe('MES PARIS', () => {
  it('affiche les tickets enregistrés par le serveur', async () => {
    loggedIn(undefined, [ticket, { ...ticket, id: '8', reference: 'R16-WON2345678', status: 'won', payout: { minor: 29500, currency: 'HTG' } }]);
    renderApp('/bets');

    expect(await screen.findByRole('heading', { name: 'MES PARIS' })).toBeInTheDocument();
    expect(await screen.findByText('R16-ABCD2345EF')).toBeInTheDocument();
    expect(screen.getAllByText(/Everton – Chelsea/).length).toBeGreaterThan(0);
    const [pending, won] = screen.getAllByRole('listitem').filter((li) => li.classList.contains('ticket-card'));
    expect(within(pending).getByText('En cours')).toBeInTheDocument();
    expect(within(pending).getByText('Gains potentiels')).toBeInTheDocument();
    expect(within(won).getByText('Gagné')).toBeInTheDocument();
    expect(within(won).getByText('Gain versé')).toBeInTheDocument();
  });

  it('détaille chaque sélection : compétition, score final, statut, et replie au-delà de 3', async () => {
    const selection = (id: string, status: BetTicket['status']) => ({
      ...ticket.selections[0], id, status,
      event: { ...ticket.selections[0].event, name: `Match ${id}`, sport: 'soccer', competition: { name: 'Premier League', country: 'Angleterre' }, status: 'finished', score: { home: '1', away: '2' } },
    });
    const multiple: BetTicket = {
      ...ticket, id: '9', type: 'multiple', status: 'lost', payout: { minor: 0, currency: 'HTG' },
      selections: [selection('1', 'won'), selection('2', 'lost'), selection('3', 'won'), selection('4', 'won')],
    };
    loggedIn(undefined, [multiple]);
    renderApp('/bets');

    expect(await screen.findByText('Sports - Multiple (4)')).toBeInTheDocument();
    expect(screen.getAllByText('Angleterre - Premier League')).toHaveLength(3);
    expect(screen.getAllByText('Score final : 1 - 2')).toHaveLength(3);
    expect(screen.getAllByRole('img', { name: 'Gagnée' })).toHaveLength(2);
    expect(screen.getByRole('img', { name: 'Perdue' })).toBeInTheDocument();
    expect(screen.queryByText('Match 4')).toBeNull();

    await userEvent.click(screen.getByRole('button', { name: 'Voir plus + (1)' }));
    expect(screen.getByText('Match 4')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Voir moins −' })).toHaveAttribute('aria-expanded', 'true');
  });

  it('filtre par statut, type et période côté serveur', async () => {
    const fetchMock = loggedIn(undefined, [ticket]);
    const router = renderApp('/bets');

    await screen.findByText('R16-ABCD2345EF');
    await userEvent.click(screen.getByRole('button', { name: 'Gagné' }));
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Type de pari' }), 'multiple');
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Période' }), '7');

    await waitFor(() => expect(router.state.location.search).toBe('?statut=won&type=multiple&jours=7'));
    const urls = fetchMock.mock.calls.map(([u]) => new URL(String(u))).filter((u) => u.pathname.endsWith('/bets'));
    expect(urls[0].searchParams.get('days')).toBe('30'); // 30 derniers jours par défaut
    const last = urls[urls.length - 1].searchParams;
    expect([last.get('status'), last.get('type'), last.get('days')]).toEqual(['won', 'multiple', '7']);
  });

  it('rejoue un ticket avec les cotes du moment, pas celles du ticket', async () => {
    const old: BetTicket = {
      ...ticket, total_odds: '2.50',
      selections: [{ ...ticket.selections[0], odds: '2.50', market: { ...ticket.selections[0].market, id: 'm1' }, selection: { ...ticket.selections[0].selection, id: '11' } }],
    };
    const fetchMock = loggedIn(undefined, [old]);
    const handlers = fetchMock.getMockImplementation()!;
    fetchMock.mockImplementation(async (input, init) => String(input).includes('/events/1/markets')
      ? new Response(JSON.stringify({ data: EVENTS[0] }), { status: 200, headers: { 'Content-Type': 'application/json' } })
      : handlers(input, init));
    renderApp('/bets');

    await userEvent.click(await screen.findByRole('button', { name: 'Rejouer' }));

    const slip = await screen.findByRole('complementary', { name: 'Coupon' });
    expect(within(slip).getAllByText('2.95').length).toBeGreaterThan(0);
    expect(within(slip).queryByText('2.50')).toBeNull();
    expect(await screen.findByText('Coupon recomposé avec les cotes du moment.')).toBeInTheDocument();
  });

  it("ne propose pas de rejouer un ticket dont les matchs ont commencé", async () => {
    loggedIn(undefined, [{ ...ticket, selections: [{ ...ticket.selections[0], event: { ...ticket.selections[0].event, starts_at: inHours(-2) }, selection: { ...ticket.selections[0].selection, id: '11' } }] }]);
    renderApp('/bets');

    expect(await screen.findByRole('button', { name: 'Rejouer' })).toBeDisabled();
  });

  it('partage le betcode à la demande', async () => {
    loggedIn(undefined, [ticket]);
    renderApp('/bets');

    await userEvent.click(await screen.findByRole('button', { name: 'Partager' }));
    expect(screen.getByText('WhatsApp')).toBeInTheDocument();
  });

  it('affiche un message quand le joueur n\'a encore aucun pari', async () => {
    loggedIn();
    renderApp('/bets');

    await waitFor(() => expect(screen.getByText("Vous n'avez encore aucun pari")).toBeInTheDocument());
  });
});
