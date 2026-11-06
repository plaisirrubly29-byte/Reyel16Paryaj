import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { guest, mockApi, renderApp } from '../../test/renderApp';
import { lotteryGame, playLabel, randomNumber, validatePlay } from './games';

const RESULT_NYM = {
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

const SPORTS = [{ slug: 'soccer', name: 'Football', events_count: 3, competitions: [] }];

function backend(draws: { status: number; body?: unknown } = { status: 200, body: { data: DRAWS } }) {
  return mockApi({
    'GET /me': guest,
    'GET /sports': { status: 200, body: { data: SPORTS } },
    'GET /events': { status: 200, body: { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } } },
    'GET /lottery/draws': draws,
    'GET /lottery/results': (_init, url) => ({
      status: 200,
      body: {
        data: url.searchParams.get('draw') === 'NYM' ? [RESULT_NYM, { ...RESULT_NYM, date: '2026-11-04', lots: ['00', '13', '99'], pick3: '300' }] : [],
        meta: { current_page: 1, per_page: 10, total: 2, last_page: 1, from: 1, to: 2 },
        links: { first: null, last: null, prev: null, next: null },
      },
    }),
  });
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
    backend({ status: 404, body: { error: { code: 'not_found', message: 'Introuvable.' } } });
    renderApp('/sports');

    await screen.findAllByRole('button', { name: /Football/ });
    expect(screen.queryByRole('link', { name: /Borlette/ })).not.toBeInTheDocument();
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

  it('prépare une fiche : lignes, contrôle de saisie, total ; l\'envoi attend l\'ouverture de la vente', async () => {
    backend();
    renderApp('/borlette?tirage=NYM');

    const slip = await screen.findByRole('region', { name: 'Fiche : New York Midi' });
    await userEvent.type(within(slip).getByLabelText('Numéro'), '7');
    await userEvent.type(within(slip).getByLabelText(/Mise/), '50');
    await userEvent.click(within(slip).getByRole('button', { name: 'Ajouter à la fiche' }));
    expect(within(slip).getByRole('alert')).toHaveTextContent('Saisissez un numéro de 2 chiffres.');

    await userEvent.type(within(slip).getByLabelText('Numéro'), '7');
    await userEvent.click(within(slip).getByRole('button', { name: 'Ajouter à la fiche' }));

    await userEvent.click(within(slip).getByRole('button', { name: 'Maryaj' }));
    await userEvent.type(within(slip).getByLabelText('Boule 1'), '25');
    await userEvent.type(within(slip).getByLabelText('Boule 2'), '41');
    await userEvent.clear(within(slip).getByLabelText(/Mise/));
    await userEvent.type(within(slip).getByLabelText(/Mise/), '25');
    await userEvent.click(within(slip).getByRole('button', { name: 'Ajouter à la fiche' }));

    const lines = within(slip).getByRole('list', { name: 'Lignes de la fiche' });
    expect(within(lines).getAllByRole('listitem').map((li) => li.textContent?.replace(/\s/g, ' '))).toEqual([
      expect.stringContaining('Bolèt77'),
      expect.stringContaining('Maryaj25x41'),
    ]);
    expect(within(slip).getByText('Total').parentElement).toHaveTextContent(/75,00/);

    await userEvent.click(within(lines).getByRole('button', { name: 'Retirer Bolèt 77' }));
    expect(within(lines).getAllByRole('listitem')).toHaveLength(1);

    expect(within(slip).getByRole('button', { name: 'Valider la fiche' })).toBeDisabled();
    expect(within(slip).getByText(/La vente de fiches borlette ouvre bientôt/)).toBeInTheDocument();
  });
});

describe('jeux de borlette', () => {
  it('vérifie la forme de chaque ligne', () => {
    expect(validatePlay(lotteryGame('bolet'), ['07'], '50', null)).toBeNull();
    expect(validatePlay(lotteryGame('bolet'), ['7'], '50', null)).toMatch(/2 chiffres/);
    expect(validatePlay(lotteryGame('maryaj'), ['25', '25'], '10', null)).toMatch(/deux boules différentes/);
    expect(validatePlay(lotteryGame('loto3'), ['125'], '0', null)).toMatch(/mise/i);
    expect(validatePlay(lotteryGame('loto4'), ['2507'], '10', null)).toMatch(/option/);
    expect(validatePlay(lotteryGame('loto4'), ['2507'], '10', 2)).toBeNull();
    expect(validatePlay(lotteryGame('loto5'), ['12507'], '10', 3)).toMatch(/option/);
  });

  it('garde les zéros devant et libelle les lignes', () => {
    expect(randomNumber(2, () => 0.03)).toBe('03');
    expect(randomNumber(4, () => 0)).toBe('0000');
    expect(playLabel({ game: 'maryaj', numbers: ['25', '07'], option: null })).toBe('25x07');
    expect(playLabel({ game: 'loto4', numbers: ['2507'], option: 1 })).toBe('2507 (opt. 1)');
  });
});
