import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { guest, mockApi, player, renderApp } from '../../test/renderApp';
import type { LotteryGameOffer, LotteryResult, LotterySales, LotteryTicket } from './api/lottery';
import { multiplyMinor, playLabel, possibleWins, randomNumber, validatePlay } from './games';

afterEach(() => vi.unstubAllGlobals());

const RESULT_NYM: LotteryResult = {
  draw: { key: 'NYM', name: 'New York Midi' },
  date: '2026-11-05',
  drawn_at: '2026-11-05T19:20:00Z',
  lots: ['25', '07', '41'],
  pick3: '125',
  pick4: '0741',
  published_at: '2026-11-05T19:41:05Z',
};

const DRAWS = [
  {
    key: 'FLM', name: 'Florida Midi', draw_time: '13:20', timezone: 'America/Port-au-Prince', days: null,
    next_draw_at: '2099-01-01T18:20:00Z', latest_result: null,
  },
  {
    key: 'NYM', name: 'New York Midi', draw_time: '14:20', timezone: 'America/Port-au-Prince', days: null,
    next_draw_at: '2099-01-01T19:20:00Z', latest_result: RESULT_NYM,
  },
];

const htg = (minor: number) => ({ minor, currency: 'HTG' });

const BOLET: LotteryGameOffer = {
  key: 'bolet', digits: 2, count: 1, options: [], min_stake: htg(500), max_stake: htg(500000),
  payouts: [{ lot: 1, multiplier: '50' }, { lot: 2, multiplier: '20' }, { lot: 3, multiplier: '10' }],
};
const MARYAJ: LotteryGameOffer = {
  key: 'maryaj', digits: 2, count: 2, options: [], min_stake: htg(500), max_stake: htg(500000),
  payouts: [{ lot: null, multiplier: '1000' }],
};
const LOTO4: LotteryGameOffer = {
  key: 'loto4', digits: 4, count: 1, options: [1, 2, 3], min_stake: htg(500), max_stake: htg(500000),
  payouts: [{ lot: null, multiplier: '5000' }],
};

const SALES: LotterySales = { sales_open: true, close_before_minutes: 10, max_plays_per_ticket: 3, games: [BOLET, MARYAJ, LOTO4] };
const CLOSED: LotterySales = { sales_open: false, close_before_minutes: 10, max_plays_per_ticket: 10, games: [] };

const TICKET: LotteryTicket = {
  id: '41',
  reference: 'B16-7KQ2M9XAPB',
  draw: { key: 'NYM', name: 'New York Midi', date: '2099-01-01', draw_at: '2099-01-01T19:20:00Z' },
  status: 'pending',
  stake: htg(5000),
  max_payout: htg(250000),
  payout: null,
  plays: [{ game: 'bolet', option: null, numbers: ['25'], stake: htg(5000), max_payout: htg(250000), status: 'pending', winning_lot: null, payout: null }],
  result: null,
  placed_at: '2026-11-26T15:00:00Z',
  settled_at: null,
};

const paginated = (data: unknown[]) => ({
  data, links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 20, total: data.length, last_page: 1, from: 1, to: data.length },
});

type Handler = Parameters<typeof mockApi>[0][string];

function backend(options: {
  me?: Handler;
  draws?: Handler;
  games?: Handler;
  post?: Handler;
  tickets?: Handler;
} = {}) {
  return mockApi({
    'GET /me': options.me ?? guest,
    'GET /sports': { status: 200, body: { data: [{ slug: 'soccer', name: 'Football', events_count: 3, competitions: [] }] } },
    'GET /events': { status: 200, body: paginated([]) },
    'GET /wallet': { status: 200, body: { data: { currency: 'HTG', balance: htg(100000), status: 'active', simulated: true } } },
    'GET /bets': { status: 200, body: paginated([]) },
    'GET /lottery/draws': options.draws ?? { status: 200, body: { data: DRAWS } },
    'GET /lottery/games': options.games ?? { status: 200, body: { data: SALES } },
    'GET /lottery/results': (_init, url) => ({
      status: 200,
      body: paginated(url.searchParams.get('draw') === 'NYM' ? [RESULT_NYM, { ...RESULT_NYM, date: '2026-11-04', lots: ['00', '13', '99'], pick3: '300' }] : []),
    }),
    'POST /lottery/tickets': options.post ?? { status: 201, body: { data: TICKET } },
    'GET /lottery/tickets': options.tickets ?? { status: 200, body: paginated([]) },
  });
}

const loggedIn = { status: 200, body: { data: player } };

const error = (status: number, code: string, details: Record<string, unknown> = {}, message = 'Refusé.') =>
  ({ status, body: { error: { code, message, details } } });

/** Ajoute une ligne (jeu, numéros, mise) à la fiche ouverte. */
async function addPlay(slip: HTMLElement, game: string, numbers: string[], stake: string) {
  await userEvent.click(within(slip).getByRole('button', { name: game }));
  const labels = numbers.length > 1 ? numbers.map((_, i) => `Boule ${i + 1}`) : ['Numéro'];
  for (const [i, n] of numbers.entries()) {
    await userEvent.clear(within(slip).getByLabelText(labels[i]));
    await userEvent.type(within(slip).getByLabelText(labels[i]), n);
  }
  await userEvent.clear(within(slip).getByLabelText(/Mise/));
  await userEvent.type(within(slip).getByLabelText(/Mise/), stake);
  await userEvent.click(within(slip).getByRole('button', { name: 'Ajouter à la fiche' }));
}

/** Valide puis confirme la fiche. */
async function confirmSlip(slip: HTMLElement) {
  await userEvent.click(within(slip).getByRole('button', { name: 'Valider la fiche' }));
  await userEvent.click(within(slip).getByRole('button', { name: 'Confirmer et payer' }));
}

function posts(fetchMock: ReturnType<typeof mockApi>) {
  return fetchMock.mock.calls.filter(([url, init]) => String(url).endsWith('/lottery/tickets') && init?.method === 'POST');
}

describe('borlette dans la navigation', () => {
  it('apparaît à côté des sports et mène à la page des tirages', async () => {
    backend();
    const router = renderApp('/sports');

    const tabs = await screen.findByRole('navigation', { name: 'Sports' });
    const link = await within(tabs).findByRole('link', { name: /Borlette/ });
    await userEvent.click(link);

    expect(router.state.location.pathname).toBe('/borlette');
    expect(await screen.findByRole('heading', { name: 'Borlette', level: 1 })).toBeInTheDocument();
  });

  it('reste masquée quand la borlette est coupée côté serveur (404)', async () => {
    backend({ draws: error(404, 'not_found') });
    renderApp('/sports');

    await screen.findAllByRole('button', { name: /Football/ });
    expect(screen.queryByRole('link', { name: /Borlette/ })).not.toBeInTheDocument();
  });

  it("a sa pastille dans le bandeau d'accueil, après les sports", async () => {
    backend();
    renderApp('/');

    const hero = await screen.findByRole('navigation', { name: 'Sports disponibles' });
    const lottery = await within(hero).findByRole('link', { name: 'Borlette 2 tirages' });
    expect(lottery).toHaveAttribute('href', '/borlette');
    expect(within(hero).getAllByRole('link').map((a) => a.getAttribute('href'))).toEqual(['/sports?sport=soccer', '/borlette']);
  });
});

describe('page Borlette', () => {
  it('montre les tirages et les boules publiées, puis l\'historique du tirage choisi', async () => {
    backend();
    const router = renderApp('/borlette');

    const draws = await screen.findByRole('list', { name: 'Tirages' });
    const nym = within(draws).getByRole('button', { name: /New York Midi/ });
    expect(within(nym).getByRole('list', { name: '1er lot 25, 2e lot 07, 3e lot 41' })).toBeInTheDocument();
    expect(within(draws).getByRole('button', { name: /Florida Midi/ })).toHaveTextContent('Pas encore de résultat publié');

    // Par défaut : le tirage qui ferme le plus tôt (Florida Midi), sans résultat.
    expect(await screen.findByText('Aucun résultat publié pour ce tirage pour l\'instant.')).toBeInTheDocument();

    await userEvent.click(nym);
    expect(router.state.location.search).toBe('?tirage=NYM');
    const history = await screen.findByRole('region', { name: 'Résultats : New York Midi' });
    expect(await within(history).findByRole('list', { name: '1er lot 00, 2e lot 13, 3e lot 99' })).toBeInTheDocument();
  });

  it('vente fermée par le serveur : pas de fiche à valider', async () => {
    backend({ games: { status: 200, body: { data: CLOSED } }, me: loggedIn });
    renderApp('/borlette?tirage=NYM');

    const slip = await screen.findByRole('region', { name: 'Fiche : New York Midi' });
    expect(await within(slip).findByText(/La vente de fiches borlette est fermée pour le moment/)).toBeInTheDocument();
    expect(within(slip).queryByRole('button', { name: 'Valider la fiche' })).not.toBeInTheDocument();
    expect(within(slip).queryByLabelText('Numéro')).not.toBeInTheDocument();
  });

  it('prépare une fiche selon le barème du serveur : jeux, mises min/max, gains possibles, fin des ventes, total', async () => {
    backend();
    renderApp('/borlette?tirage=NYM');

    const slip = await screen.findByRole('region', { name: 'Fiche : New York Midi' });
    // Seuls les jeux en vente sont proposés (pas de Loto 3 ni Loto 5 ici).
    expect(within(slip).getByRole('group', { name: 'Jeu' }).textContent).toBe('BolètMaryajLoto 4');
    // Tirage 14:20 (heure d'Haïti), fin des ventes 10 min avant.
    expect(within(slip).getByText(/fin des ventes/)).toHaveTextContent(/14:10/);
    expect(within(slip).getByText(/Mise de 5,00.*à 5\s?000,00/)).toBeInTheDocument();

    await addPlay(slip, 'Bolèt', ['7'], '50');
    expect(within(slip).getByRole('alert')).toHaveTextContent('Saisissez un numéro de 2 chiffres.');

    await addPlay(slip, 'Bolèt', ['07'], '4');
    expect(within(slip).getByRole('alert')).toHaveTextContent(/La mise doit être comprise entre 5,00/);

    await addPlay(slip, 'Bolèt', ['07'], '50');
    await addPlay(slip, 'Maryaj', ['25', '41'], '25');

    const lines = within(slip).getByRole('list', { name: 'Lignes de la fiche' });
    const items = within(lines).getAllByRole('listitem');
    expect(items).toHaveLength(2);
    expect(items[0]).toHaveTextContent(/Bolèt07/);
    // Gain possible = mise × multiplicateur, par lot pour la bolèt.
    expect(items[0]).toHaveTextContent(/1er lot 2\s?500,00.*2e lot 1\s?000,00.*3e lot 500,00/);
    expect(items[1]).toHaveTextContent(/Maryaj25x41/);
    expect(items[1]).toHaveTextContent(/Gain possible : 25\s?000,00/);
    expect(within(slip).getByText('Total').parentElement).toHaveTextContent(/75,00/);

    // Visiteur : invité à se connecter pour valider.
    expect(within(slip).getByRole('link', { name: 'Se connecter pour jouer' })).toHaveAttribute('href', '/login?redirect=%2Fborlette%3Ftirage%3DNYM');

    // max_plays_per_ticket = 3.
    await addPlay(slip, 'Loto 4', ['2507'], '10');
    expect(within(slip).getByRole('button', { name: 'Ajouter à la fiche' })).toBeDisabled();
    expect(within(slip).getByText('Une fiche compte au plus 3 lignes.')).toBeInTheDocument();

    await userEvent.click(within(lines).getByRole('button', { name: 'Retirer Bolèt 07' }));
    expect(within(lines).getAllByRole('listitem')).toHaveLength(2);
  });

  it('envoie la fiche au contrat de l\'API, affiche le reçu et relit le solde', async () => {
    const fetchMock = backend({ me: loggedIn });
    renderApp('/borlette?tirage=NYM');

    const slip = await screen.findByRole('region', { name: 'Fiche : New York Midi' });
    await addPlay(slip, 'Bolèt', ['25'], '50');
    await addPlay(slip, 'Loto 4', ['2507'], '10');

    await userEvent.click(within(slip).getByRole('button', { name: 'Valider la fiche' }));
    const confirm = within(slip).getByRole('group', { name: 'Confirmer la fiche' });
    expect(confirm).toHaveTextContent(/New York Midi, tirage du jeu\. 1 janv\. : 2 lignes, 60,00/);
    const walletCalls = () => fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/wallet')).length;
    const before = walletCalls();
    await userEvent.click(within(confirm).getByRole('button', { name: 'Confirmer et payer' }));

    expect(await within(slip).findByText('B16-7KQ2M9XAPB')).toBeInTheDocument();
    expect(within(slip).getByRole('link', { name: 'Voir dans Mes paris' })).toHaveAttribute('href', '/bets?produit=borlette');
    expect(within(slip).queryByRole('list', { name: 'Lignes de la fiche' })).not.toBeInTheDocument();

    const [[, init]] = posts(fetchMock);
    expect(JSON.parse(String(init?.body))).toEqual({
      draw: 'NYM',
      date: '2099-01-01',
      plays: [
        { game: 'bolet', option: null, numbers: ['25'], stake: { minor: 5000, currency: 'HTG' } },
        { game: 'loto4', option: 1, numbers: ['2507'], stake: { minor: 1000, currency: 'HTG' } },
      ],
    });
    expect((init?.headers as Record<string, string>)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
    await waitFor(() => expect(walletCalls()).toBeGreaterThan(before));
  });

  it('renvoie la même clé d\'idempotence pour la même fiche après une réponse perdue', async () => {
    let calls = 0;
    const fetchMock = backend({ me: loggedIn, post: () => (++calls === 1 ? { status: 503 } : { status: 200, body: { data: TICKET } }) });
    renderApp('/borlette?tirage=NYM');

    const slip = await screen.findByRole('region', { name: 'Fiche : New York Midi' });
    await addPlay(slip, 'Bolèt', ['25'], '50');
    await confirmSlip(slip);
    expect(await within(slip).findByText(/votre fiche a peut-être été validée/)).toBeInTheDocument();

    await confirmSlip(slip);
    expect(await within(slip).findByText('B16-7KQ2M9XAPB')).toBeInTheDocument();
    const keys = posts(fetchMock).map(([, init]) => (init?.headers as Record<string, string>)['Idempotency-Key']);
    expect(keys).toHaveLength(2);
    expect(keys[0]).toBe(keys[1]);
  });

  it.each([
    [error(422, 'insufficient_funds'), /Votre solde ne suffit pas pour cette fiche/, null],
    [error(422, 'lottery_number_blocked', { play: 0, numbers: ['25'] }), /La boule 25 n'est plus acceptée pour ce tirage/, 'Boule bloquée pour ce tirage.'],
    [error(422, 'lottery_number_limit', { play: 0, numbers: ['25'], remaining_stake: htg(2000) }), /mise encore acceptée 20,00/, 'Mise maximale : 20,00'],
    [error(422, 'stake_limit_exceeded', { remaining_minor: 3000 }, 'Limite de mise atteinte.'), /Mise totale encore possible : 30,00/, null],
    [error(422, 'loss_limit_exceeded', { remaining_minor: 0 }, 'Limite de perte atteinte.'), /Vous ne pouvez plus jouer sur cette période/, null],
    [error(422, 'betting_paused'), /Vos paris sont en pause/, null],
    [error(403, 'lottery_sales_closed'), /La vente de fiches borlette est fermée pour le moment/, null],
    [error(422, 'validation_failed', { 'plays.0.numbers': ['Le numéro doit avoir 2 chiffres.'] }), /Corrigez les lignes signalées/, 'Le numéro doit avoir 2 chiffres.'],
  ])('explique le refus %#', async (response, message, lineError) => {
    backend({ me: loggedIn, post: response });
    renderApp('/borlette?tirage=NYM');

    const slip = await screen.findByRole('region', { name: 'Fiche : New York Midi' });
    await addPlay(slip, 'Bolèt', ['25'], '50');
    await confirmSlip(slip);

    expect(await within(slip).findByText(message)).toBeInTheDocument();
    // Rien n'est débité : la fiche reste là pour être corrigée.
    const lines = within(slip).getByRole('list', { name: 'Lignes de la fiche' });
    if (lineError) expect(within(lines).getByText(new RegExp(lineError.replace('.', '\\.')))).toBeInTheDocument();
  });

  it('tirage fermé : propose la date suivante et rejoue la fiche pour cette date', async () => {
    let calls = 0;
    const fetchMock = backend({
      me: loggedIn,
      post: () => (++calls === 1
        ? error(409, 'lottery_draw_closed', { next_date: '2099-01-02', closes_at: '2099-01-01T19:10:00Z' })
        : { status: 201, body: { data: { ...TICKET, draw: { ...TICKET.draw, date: '2099-01-02' } } } }),
    });
    renderApp('/borlette?tirage=NYM');

    const slip = await screen.findByRole('region', { name: 'Fiche : New York Midi' });
    await addPlay(slip, 'Bolèt', ['25'], '50');
    await confirmSlip(slip);

    expect(await within(slip).findByText(/Le prochain tirage a lieu le ven\. 2 janv\./)).toBeInTheDocument();
    await userEvent.click(within(slip).getByRole('button', { name: 'Jouer pour le ven. 2 janv.' }));
    expect(within(slip).getByRole('group', { name: 'Confirmer la fiche' })).toHaveTextContent(/tirage du ven\. 2 janv\./);
    await userEvent.click(within(slip).getByRole('button', { name: 'Confirmer et payer' }));

    expect(await within(slip).findByText('B16-7KQ2M9XAPB')).toBeInTheDocument();
    const bodies = posts(fetchMock).map(([, init]) => JSON.parse(String(init?.body)) as { date: string });
    expect(bodies.map((b) => b.date)).toEqual(['2099-01-01', '2099-01-02']);
  });
});

describe('Mes paris : fiches de borlette', () => {
  it('liste les fiches avec tirage, lignes, statut, gains et boules du résultat', async () => {
    const won: LotteryTicket = {
      ...TICKET,
      id: '40',
      reference: 'B16-WON0000001',
      draw: { key: 'NYM', name: 'New York Midi', date: '2026-11-05', draw_at: '2026-11-05T19:20:00Z' },
      status: 'won',
      stake: htg(7000),
      payout: htg(100000),
      plays: [
        { game: 'bolet', option: null, numbers: ['07'], stake: htg(5000), max_payout: htg(250000), status: 'won', winning_lot: 2, payout: htg(100000) },
        { game: 'maryaj', option: null, numbers: ['25', '99'], stake: htg(2000), max_payout: htg(2000000), status: 'lost', winning_lot: null, payout: htg(0) },
      ],
      result: RESULT_NYM,
      settled_at: '2026-11-05T20:00:00Z',
    };
    const fetchMock = backend({ me: loggedIn, tickets: { status: 200, body: paginated([TICKET, won]) } });
    renderApp('/bets');

    await userEvent.click(await screen.findByRole('button', { name: /Borlette/ }));
    const list = await screen.findByRole('list', { name: 'Fiches de borlette' });

    const pending = within(list).getByRole('listitem', { name: 'Fiche B16-7KQ2M9XAPB' });
    expect(pending).toHaveTextContent('Borlette - New York Midi');
    expect(pending).toHaveTextContent('Tirage du jeu. 1 janv.');
    expect(pending).toHaveTextContent('En cours');
    expect(pending).toHaveTextContent('Résultat pas encore publié.');
    expect(pending).toHaveTextContent(/Gain maximal\s*2\s?500,00/);

    const settled = within(list).getByRole('listitem', { name: 'Fiche B16-WON0000001' });
    expect(settled).toHaveTextContent('Gagné');
    expect(within(settled).getByRole('list', { name: '1er lot 25, 2e lot 07, 3e lot 41' })).toBeInTheDocument();
    expect(settled).toHaveTextContent(/Bolèt 07/);
    expect(settled).toHaveTextContent(/Gain 1\s?000,00.*\(2e lot\)/);
    expect(settled).toHaveTextContent(/Maryaj 25x99/);
    expect(settled).toHaveTextContent(/Gain versé\s*1\s?000,00/);

    // Filtre par statut, appliqué par le serveur.
    await userEvent.click(screen.getByRole('button', { name: 'Gagné' }));
    await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/lottery/tickets?') && String(url).includes('status=won'))).toBe(true));
  });
});

describe('jeux de borlette', () => {
  it('vérifie la forme et la mise de chaque ligne', () => {
    expect(validatePlay(BOLET, ['07'], '50', null)).toBeNull();
    expect(validatePlay(BOLET, ['7'], '50', null)).toMatch(/2 chiffres/);
    expect(validatePlay(MARYAJ, ['25', '25'], '10', null)).toMatch(/deux boules différentes/);
    expect(validatePlay(BOLET, ['25'], '', null)).toMatch(/mise/i);
    expect(validatePlay(MARYAJ, ['25', '07'], '5001', null)).toMatch(/entre 5,00.*et 5\s?000,00/);
    expect(validatePlay(LOTO4, ['2507'], '10', null)).toMatch(/option/);
    expect(validatePlay(LOTO4, ['2507'], '10', 2)).toBeNull();
    expect(validatePlay(LOTO4, ['2507'], '10', 4)).toMatch(/option/);
  });

  it('calcule le gain possible en entiers, arrondi au centime inférieur', () => {
    expect(multiplyMinor(5000, '50')).toBe(250000);
    expect(multiplyMinor(333, '12.5')).toBe(4162);
    expect(possibleWins(BOLET, htg(1000)).map((w) => [w.lot, w.win.minor])).toEqual([[1, 50000], [2, 20000], [3, 10000]]);
  });

  it('garde les zéros devant et libelle les lignes', () => {
    expect(randomNumber(2, () => 0.03)).toBe('03');
    expect(randomNumber(4, () => 0)).toBe('0000');
    expect(playLabel({ numbers: ['25', '07'], option: null })).toBe('25x07');
    expect(playLabel({ numbers: ['2507'], option: 1 })).toBe('2507 (opt. 1)');
  });
});
