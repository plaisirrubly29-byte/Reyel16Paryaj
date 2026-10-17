import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { guest, mockApi, renderApp } from '../../test/renderApp';

beforeEach(() => localStorage.clear());
afterEach(() => vi.unstubAllGlobals());

const inHours = (h: number) => new Date(Date.now() + h * 3_600_000).toISOString();

const MATCH = {
  id: '11',
  sport: { slug: 'soccer', name: 'Football' },
  competition: { id: '2', name: 'Premier League', country: 'Angleterre' },
  home_team: 'Arsenal',
  away_team: 'Chelsea',
  starts_at: inHours(20),
  status: 'scheduled',
  markets: [
    {
      id: 'm1', key: 'h2h', name: 'Résultat du match (1X2)', line: null, odds_version: 3, odds_updated_at: inHours(0), bettable: true,
      selections: [
        { id: 's1', key: 'home', label: '1', name: 'Arsenal', odds: '2.10' },
        { id: 's2', key: 'draw', label: 'X', name: 'Match nul', odds: '3.25' },
        { id: 's3', key: 'away', label: '2', name: 'Chelsea', odds: '3.40' },
      ],
    },
    {
      id: 'm2', key: 'totals', name: 'Nombre de buts', line: '2.5', odds_version: 1, odds_updated_at: inHours(0), bettable: true,
      selections: [
        { id: 's4', key: 'over', label: '+2.5', name: 'Plus de 2.5', odds: '1.80' },
        { id: 's5', key: 'under', label: '-2.5', name: 'Moins de 2.5', odds: '2.00' },
      ],
    },
  ],
};

function api(match: unknown = MATCH, status = 200) {
  return mockApi({
    'GET /me': guest,
    'GET /sports': { status: 200, body: { data: [] } },
    'GET /events/11/markets': { status, body: status === 200 ? { data: match } : { error: { code: 'not_found', message: 'Introuvable.' } } },
  });
}

describe("page d'un match", () => {
  it('affiche la compétition, les équipes, le coup d\'envoi et tous les marchés', async () => {
    api();
    renderApp('/events/11');

    expect(await screen.findByRole('heading', { level: 1 })).toHaveTextContent(/Arsenal.*Chelsea/);
    expect(screen.getByText(/Angleterre · Premier League/)).toBeInTheDocument();

    const result = screen.getByRole('region', { name: 'Résultat du match (1X2)' });
    expect(within(result).getAllByRole('button')).toHaveLength(3);
    const goals = screen.getByRole('region', { name: 'Nombre de buts' });
    expect(within(goals).getByRole('button', { name: 'Plus de 2.5 à 1.80' })).toHaveClass('favorite');
    expect(within(goals).getByRole('button', { name: 'Moins de 2.5 à 2.00' })).toBeInTheDocument();
  });

  it('ajoute un pari « Nombre de buts » au coupon avec le nom du marché', async () => {
    api();
    renderApp('/events/11');

    await userEvent.click(await screen.findByRole('button', { name: 'Plus de 2.5 à 1.80' }));

    const slip = screen.getByRole('complementary', { name: 'Coupon' });
    expect(within(slip).getByText('+2.5 · Plus de 2.5')).toBeInTheDocument();
    expect(within(slip).getByText(/Nombre de buts ·/)).toBeInTheDocument();
    expect(JSON.parse(localStorage.getItem('r16p.betslip') ?? '[]')).toEqual([
      expect.objectContaining({ selectionId: 's4', marketId: 'm2', oddsVersion: 1, odds: '1.80', market: 'Nombre de buts' }),
    ]);

    // Un seul choix par match : le 1X2 remplace le pari sur les buts.
    await userEvent.click(screen.getByRole('button', { name: 'Arsenal à 2.10' }));
    expect(within(slip).getByLabelText('1 sélection')).toBeInTheDocument();
    expect(within(slip).getByText('1 · Arsenal')).toBeInTheDocument();
  });

  it('bloque un marché dont la cote est trop ancienne', async () => {
    api({ ...MATCH, markets: [MATCH.markets[0], { ...MATCH.markets[1], bettable: false }] });
    renderApp('/events/11');

    expect(await screen.findByText('Cotes en cours de mise à jour')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Plus de 2.5 à 1.80 (cote en cours de mise à jour)' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Arsenal à 2.10' })).toBeEnabled();
  });

  it("annonce un match qui n'est plus proposé", async () => {
    api(null, 404);
    renderApp('/events/11');

    expect(await screen.findByText("Ce match n'est plus proposé")).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Voir les autres matchs' })).toHaveAttribute('href', '/sports');
  });
});
