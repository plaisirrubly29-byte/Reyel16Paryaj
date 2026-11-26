import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { guest, mockApi, renderApp } from '../../test/renderApp';

const SPORTS = [
  {
    // Ordre de GET /sports : par pays (pays des « top compétitions » d'abord), puis D1 → D2 → coupes.
    slug: 'soccer', name: 'Football', events_count: 39, competitions: [
      { id: '3', name: 'Ligue des champions', country: 'Europe', tier: null, events_count: 9 },
      { id: '2', name: 'Premier League', country: 'Angleterre', tier: 'd1', events_count: 10 },
      { id: '8', name: 'LaLiga', country: 'Espagne', tier: 'd1', events_count: 4 },
      { id: '9', name: 'LaLiga 2', country: 'Espagne', tier: 'd2', events_count: 3 },
      { id: '10', name: 'Coupe du Roi', country: 'Espagne', tier: 'cup', events_count: 2 },
      { id: '7', name: 'Ligue 1', country: 'France', tier: 'd1', events_count: 11 },
    ],
  },
  { slug: 'basketball', name: 'Basketball', events_count: 12, competitions: [{ id: '5', name: 'NBA', country: 'États-Unis', tier: null, events_count: 12 }] },
];

function catalogue() {
  return mockApi({
    'GET /me': guest,
    'GET /health': { status: 200, body: { status: 'ok', version: 'v1', time: '' } },
    'GET /sports': { status: 200, body: { data: SPORTS } },
    'GET /events': { status: 200, body: { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } } },
  });
}

describe('menu « Championnats » de l\'en-tête', () => {
  it('liste les championnats du sport choisi et ouvre celui cliqué', async () => {
    catalogue();
    const router = renderApp('/');

    const toggle = screen.getByRole('button', { name: 'Championnats' });
    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await userEvent.click(toggle);

    const panel = await screen.findByRole('region', { name: 'Championnats' });
    const football = within(panel).getByRole('list', { name: 'Championnats : Football' });
    expect(within(football).getAllByRole('link').map((l) => l.textContent)).toEqual([
      'Tous les matchs : Football', 'Ligue des champions9', 'Premier LeagueDivision 110',
      'LaLigaDivision 14', 'LaLiga 2Division 23', 'Coupe du RoiCoupe nationale2', 'Ligue 1Division 111',
    ]);
    // Les pays en titres, chacun avec ses championnats.
    expect(within(football).getAllByRole('list').map((l) => l.getAttribute('aria-label'))).toEqual([
      'Europe', 'Angleterre', 'Espagne', 'France',
    ]);
    expect(within(within(football).getByRole('list', { name: 'Espagne' })).getAllByRole('link').map((l) => l.textContent)).toEqual([
      'LaLigaDivision 14', 'LaLiga 2Division 23', 'Coupe du RoiCoupe nationale2',
    ]);

    await userEvent.click(within(panel).getByRole('button', { name: /Basketball/ }));
    await userEvent.click(within(panel).getByRole('link', { name: /NBA/ }));

    expect(router.state.location.pathname).toBe('/sports');
    expect(router.state.location.search).toBe('?sport=basketball&competition=5');
    expect(screen.queryByRole('region', { name: 'Championnats' })).not.toBeInTheDocument();
  });

  it('cherche un championnat dans tous les sports et se ferme avec Échap', async () => {
    catalogue();
    renderApp('/');

    await userEvent.click(screen.getByRole('button', { name: 'Championnats' }));
    await userEvent.type(await screen.findByRole('searchbox', { name: 'Rechercher un championnat' }), 'angleterre');

    const panel = screen.getByRole('region', { name: 'Championnats' });
    expect(within(panel).getAllByRole('link').map((l) => l.textContent)).toEqual(['Premier LeagueFootball · Angleterre · Division 110']);

    await userEvent.keyboard('{Escape}');
    expect(screen.queryByRole('region', { name: 'Championnats' })).not.toBeInTheDocument();
  });
});

describe('compétitions de la barre latérale', () => {
  it('sont regroupées par pays et filtrent la liste des matchs', async () => {
    catalogue();
    const router = renderApp('/');

    const menu = await screen.findByRole('navigation', { name: 'Compétitions' });
    await within(menu).findByRole('list', { name: 'Espagne' });
    expect(within(menu).getAllByRole('list').map((l) => l.getAttribute('aria-label'))).toEqual([
      'Europe', 'Angleterre', 'Espagne', 'France', 'États-Unis',
    ]);
    const spain = within(menu).getByRole('list', { name: 'Espagne' });
    expect(within(spain).getAllByRole('link').map((l) => l.textContent)).toEqual([
      'LaLigaDivision 1', 'LaLiga 2Division 2', 'Coupe du RoiCoupe nationale',
    ]);

    await userEvent.click(within(spain).getByRole('link', { name: /LaLiga 2/ }));
    expect(router.state.location.pathname).toBe('/sports');
    expect(router.state.location.search).toBe('?competition=9');
  });
});

describe('panier flottant (ordinateur)', () => {
  it('reste fermé tant qu\'on ne clique pas sur le bouton Panier', async () => {
    catalogue();
    renderApp('/');

    const fab = screen.getByRole('button', { name: /Panier/ });
    const slip = screen.getByRole('complementary', { name: 'Coupon' });
    expect(fab).toHaveAttribute('aria-expanded', 'false');
    expect(slip).not.toHaveClass('open');

    await userEvent.click(fab);
    expect(fab).toHaveAttribute('aria-expanded', 'true');
    expect(slip).toHaveClass('open');

    await userEvent.click(within(slip).getByRole('button', { name: 'Fermer le coupon' }));
    expect(fab).toHaveAttribute('aria-expanded', 'false');
    expect(slip).not.toHaveClass('open');

    await userEvent.click(fab);
    await userEvent.keyboard('{Escape}');
    expect(slip).not.toHaveClass('open');
  });
});
