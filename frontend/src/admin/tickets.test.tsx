import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, player, renderApp } from '../test/renderApp';

afterEach(() => vi.unstubAllGlobals());

const me = (permissions: string[]) => ({
  status: 200,
  body: {
    data: {
      ...player,
      id: '99',
      name: 'Admin',
      is_admin: true,
      roles: ['support'],
      permissions: ['dashboard.view', ...permissions],
      mfa: { enabled: true, verified: true, required: true },
    },
  },
});

const htg = (minor: number) => ({ minor, currency: 'HTG' });

const ticket = (overrides: Record<string, unknown> = {}) => ({
  id: '41',
  reference: 'R16-7KQ2M9XAPB',
  type: 'single',
  status: 'pending',
  user: { id: '7', name: 'Ana Joueuse' },
  wallet_id: '3',
  stake: htg(100_00),
  total_odds: '2.05',
  potential_payout: htg(205_00),
  payout: null,
  rules_version: '2026-10-01',
  placed_at: '2026-10-20T15:00:00+00:00',
  settled_at: null,
  ...overrides,
});

const page = (data: unknown[]) => ({
  data,
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 20, total: data.length, last_page: 1, from: 1, to: data.length },
});

describe('écran Tickets', () => {
  it('liste les tickets et transmet les filtres à l\'API', async () => {
    const fetchMock = mockApi({
      'GET /me': me(['tickets.view']),
      'GET /admin/tickets': { status: 200, body: page([ticket(), ticket({ id: '42', reference: 'R16-AAAA', status: 'won', payout: htg(205_00) })]) },
    });

    renderApp('/admin/tickets?user_id=7');

    const table = await screen.findByRole('table', { name: 'Tickets' });
    expect(within(table).getByRole('link', { name: 'R16-7KQ2M9XAPB' })).toHaveAttribute('href', '/admin/tickets/41');
    expect(within(table).getByText('Gagné')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Joueur n° 7/ })).toBeInTheDocument();

    await userEvent.selectOptions(screen.getByLabelText('Statut'), 'won');
    await userEvent.type(screen.getByLabelText('Référence'), 'r16-aaaa');
    await userEvent.click(screen.getByRole('button', { name: 'Rechercher' }));

    await waitFor(() => {
      const url = new URL(String(fetchMock.mock.calls.filter(([u]) => String(u).includes('/admin/tickets')).pop()?.[0]));
      expect(url.searchParams.get('reference')).toBe('r16-aaaa');
      expect(url.searchParams.get('status')).toBe('won');
      expect(url.searchParams.get('user_id')).toBe('7');
    });
  });

  it('affiche la fiche d\'un ticket avec ses sélections, sans écritures pour le Support', async () => {
    mockApi({
      'GET /me': me(['tickets.view']),
      'GET /admin/tickets/41': {
        status: 200,
        body: {
          data: {
            ...ticket(),
            rules_snapshot: { min_stake_minor: 1000 },
            selections: [{
              id: '1',
              event: { id: '5', name: 'Everton – Chelsea', starts_at: '2026-10-21T19:00:00+00:00' },
              market: { key: 'h2h', name: 'Résultat du match', line: null },
              selection: { key: 'home', label: '1', name: 'Everton' },
              odds: '2.05',
              status: 'pending',
            }],
          },
        },
      },
    });

    renderApp('/admin/tickets/41');

    expect(await screen.findByRole('heading', { name: 'R16-7KQ2M9XAPB' })).toBeInTheDocument();
    expect(screen.getByText('Everton – Chelsea')).toBeInTheDocument();
    expect(screen.getByText('Gain potentiel')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Tous ses tickets' })).toHaveAttribute('href', '/admin/tickets?user_id=7');
    expect(screen.queryByRole('link', { name: 'Fiche du compte' })).toBeNull();
    expect(screen.queryByText('Écritures du portefeuille')).toBeNull();
  });
});
