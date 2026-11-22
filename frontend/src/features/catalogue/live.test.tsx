import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { currentQueryClient, mockApi, player, renderApp } from '../../test/renderApp';
import type { BetTicket } from '../betting/api/bets';
import type { LiveOddsResponse, LiveSuspendedReason } from './api/liveOdds';

/** Paris en direct (D-20) : cotes du moment relues toutes les 3 s, « Suspendu », délai d'acceptation dans le coupon. */

beforeEach(() => localStorage.clear());
afterEach(() => vi.unstubAllGlobals());

const minutesAgo = (m: number) => new Date(Date.now() - m * 60_000).toISOString();

const LIVE_EVENT = {
  id: '1', sport: { slug: 'soccer', name: 'Football' }, competition: { id: '1', name: 'Premier League', country: 'Angleterre' },
  home_team: 'Everton', away_team: 'Chelsea', starts_at: minutesAgo(40), status: 'live', score: { home: '0', away: '0' }, minute: 40,
  markets: [{
    id: 'm1', key: 'h2h', name: 'Résultat du match (1X2)', odds_version: 3, odds_updated_at: minutesAgo(1), bettable: true,
    suspended_reason: null,
    selections: [
      { id: '11', key: 'home', label: '1', name: 'Everton', odds: '2.00' },
      { id: '12', key: 'draw', label: 'X', name: 'Match nul', odds: '3.20' },
      { id: '13', key: 'away', label: '2', name: 'Chelsea', odds: '3.60' },
    ],
  }],
};

const paginated = (data: unknown[]) => ({
  data, links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 50, total: data.length, last_page: 1, from: 1, to: data.length },
});

/** Réponse de GET /live/odds : cote du moment d'Everton, ou marché suspendu. */
function liveOdds(home: string, reason: LiveSuspendedReason | null = null, score = { home: '0', away: '0' }): LiveOddsResponse {
  return {
    data: [{
      id: '1', status: 'live', score, minute: 41,
      markets: [{
        id: 'm1', key: 'h2h', line: null, odds_version: 3, bettable: reason === null, suspended_reason: reason,
        selections: [{ id: '11', key: 'home', odds: home }, { id: '12', key: 'draw', odds: '3.10' }, { id: '13', key: 'away', odds: '3.80' }],
      }],
    }],
    live_betting: { enabled: true, acceptance_delay_seconds: 8, max_stake_minor: 1000000, allow_multiple: false },
    server_time: new Date().toISOString(),
  };
}

function ticket(status: BetTicket['status'], rejection: BetTicket['rejection_reason'] = null): BetTicket {
  return {
    id: '7', reference: 'R16-LIVE000007', type: 'single', status, live: true, rejection_reason: rejection,
    acceptance_due_at: new Date(Date.now() + 8_000).toISOString(),
    stake: { minor: 10000, currency: 'HTG' }, total_odds: '1.85', potential_payout: { minor: 18500, currency: 'HTG' },
    payout: status === 'rejected' ? { minor: 10000, currency: 'HTG' } : null, placed_at: new Date().toISOString(), settled_at: null,
    selections: [{
      id: '1', event: { id: '1', name: 'Everton – Chelsea', starts_at: LIVE_EVENT.starts_at },
      market: { key: 'h2h', name: 'Résultat du match (1X2)', line: null },
      selection: { key: 'home', label: '1', name: 'Everton' }, odds: '1.85', status: 'pending',
    }],
  };
}

function api(live: () => LiveOddsResponse, extra: Parameters<typeof mockApi>[0] = {}) {
  return mockApi({
    'GET /me': { status: 200, body: { data: player } },
    'GET /sports': { status: 200, body: { data: [] } },
    'GET /events': { status: 200, body: paginated([LIVE_EVENT]) },
    'GET /wallet': { status: 200, body: { data: { currency: 'HTG', balance: { minor: 100000, currency: 'HTG' }, status: 'active', simulated: false } } },
    'GET /bets': { status: 200, body: paginated([]) },
    'GET /live/odds': () => ({ status: 200, body: live() }),
    ...extra,
  });
}

const refreshLive = () => act(() => currentQueryClient().invalidateQueries({ queryKey: ['live-odds'] }));

describe('paris en direct', () => {
  it('affiche la cote du moment, puis « Suspendu » quand le marché se ferme', async () => {
    let current = liveOdds('1.85');
    const fetchMock = api(() => current);
    renderApp('/');

    expect((await screen.findAllByRole('button', { name: '1 Everton à 1.85' }))[0]).toBeEnabled();
    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/live/odds?event_ids=1'))).toBe(true);
    expect(screen.getAllByText("41'").length).toBeGreaterThan(0);

    current = liveOdds('1.85', 'score_changed', { home: '1', away: '0' });
    await refreshLive();

    expect((await screen.findAllByText('Suspendu'))[0]).toBeInTheDocument();
    const button = screen.getAllByRole('button', { name: /1 Everton à 1.85 \(suspendu : but en cours de vérification\)/ })[0];
    expect(button).toBeDisabled();
  });

  it('met à jour la cote dans le coupon et attend la validation du pari en direct', async () => {
    let current = liveOdds('1.85');
    let reads = 0;
    const fetchMock = api(() => current, {
      'POST /bets': { status: 202, body: { data: ticket('awaiting') } },
      'GET /bets/7': () => {
        reads += 1;
        return { status: 200, body: { data: ticket(reads >= 1 ? 'pending' : 'awaiting') } };
      },
    });
    renderApp('/');

    await userEvent.click((await screen.findAllByRole('button', { name: '1 Everton à 1.85' }))[0]);
    const slip = screen.getByRole('complementary', { name: 'Coupon' });
    expect(within(slip).getByText('En direct')).toBeInTheDocument();

    // La cote bouge : le coupon suit sans rien demander au joueur.
    current = liveOdds('1.80');
    await refreshLive();
    expect((await within(slip).findAllByText('1.80')).length).toBeGreaterThan(0);
    expect(within(slip).queryByText('1.85')).not.toBeInTheDocument();

    await userEvent.type(within(slip).getByLabelText('Montant de la mise'), '100');
    await userEvent.click(within(slip).getByRole('button', { name: 'Placer mon pari' }));

    expect((await within(slip).findAllByText(/Pari en cours de validation/, {}, { timeout: 3000 })).length).toBeGreaterThan(0);
    expect(await within(slip).findByText('Pari placé', {}, { timeout: 4000 })).toBeInTheDocument();
    const posted = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/bets') && init?.method === 'POST');
    expect(JSON.parse(String(posted?.[1]?.body)).selections).toEqual([{ selection_id: 11, odds: '1.80', odds_version: 3 }]);
  });

  it('montre le refus et garde la sélection quand le score change pendant la validation', async () => {
    api(() => liveOdds('1.85'), {
      'POST /bets': { status: 202, body: { data: ticket('awaiting') } },
      'GET /bets/7': { status: 200, body: { data: ticket('rejected', 'score_changed') } },
    });
    renderApp('/');

    await userEvent.click((await screen.findAllByRole('button', { name: '1 Everton à 1.85' }))[0]);
    const slip = screen.getByRole('complementary', { name: 'Coupon' });
    await userEvent.type(within(slip).getByLabelText('Montant de la mise'), '100');
    await userEvent.click(within(slip).getByRole('button', { name: 'Placer mon pari' }));

    expect(await within(slip).findByText('Pari refusé : le score a changé. Votre mise vous a été rendue.', {}, { timeout: 4000 }))
      .toBeInTheDocument();
    expect(within(slip).getByRole('button', { name: /Retirer Everton/ })).toBeInTheDocument();
  });

  it('bloque le pari tant que la sélection en direct est suspendue', async () => {
    let current = liveOdds('1.85');
    api(() => current);
    renderApp('/');

    await userEvent.click((await screen.findAllByRole('button', { name: '1 Everton à 1.85' }))[0]);
    const slip = screen.getByRole('complementary', { name: 'Coupon' });
    await userEvent.type(within(slip).getByLabelText('Montant de la mise'), '100');

    current = liveOdds('1.85', 'score_stale');
    await refreshLive();

    expect(await within(slip).findByText(/Suspendu : score en cours de vérification/)).toBeInTheDocument();
    expect(within(slip).getByRole('button', { name: 'Placer mon pari' })).toBeDisabled();
    expect(within(slip).getByText(/attendez sa réouverture ou retirez-la/)).toBeInTheDocument();
  });
});
