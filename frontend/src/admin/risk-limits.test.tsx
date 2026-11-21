import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, player, renderApp } from '../test/renderApp';

// Plafonds d'engagement (V2-D05) sur l'écran « Risques & exposition ».

afterEach(() => vi.unstubAllGlobals());

const NBSP = new RegExp(`[${String.fromCharCode(0xa0, 0x202f)}]`, 'g');
const text = (el: HTMLElement) => (el.textContent ?? '').replace(NBSP, ' ');

const me = (permissions: string[]) => ({
  status: 200,
  body: {
    data: {
      ...player,
      id: '99',
      name: 'Admin',
      is_admin: true,
      roles: ['super_admin'],
      permissions: ['dashboard.view', 'tickets.view', ...permissions],
      mfa: { enabled: true, verified: true, required: true },
    },
  },
});

const setting = (key: string, minor: number) => ({ key, group: 'Risque', label: key, type: 'money', help: null, options: null, currency: 'HTG', value: minor, default: minor });
const settings = (eventLimit: number, marketLimit: number) => ({
  status: 200,
  body: { data: {
    version: 3, updated_at: null, updated_by: null,
    items: [setting('betting.max_event_liability_minor', eventLimit), setting('betting.max_market_liability_minor', marketLimit)],
  } },
});

/** Match dont le pire cas (perte possible) vaut `worst`, réparti sur ses marchés. */
const match = (id: string, name: string, worst: number, markets: { key: string; worst: number }[], multiples = 0) => ({
  event_id: id,
  name,
  starts_at: '2026-11-12T19:00:00+00:00',
  single_tickets: 4,
  single_stakes_minor: 1_000_00,
  worst_case_minor: worst,
  markets: markets.map((m) => ({
    market_key: m.key,
    line: null,
    stakes_minor: 500_00,
    worst_case_minor: m.worst,
    selections: [{ selection_id: `${id}-${m.key}`, name: 'Issue', tickets: 2, stakes_minor: 500_00, potential_payout_minor: 500_00 + m.worst }],
  })),
  multiple_tickets: multiples,
  multiple_potential_payout_minor: multiples * 900_00,
});

const exposure = (events: unknown[]) => ({ status: 200, body: { data: { currency: 'HTG', total_events: events.length, events } } });

const EVENTS = [
  // Plafond du match atteint (500 000) ; son marché 1X2 dépasse aussi le sien (250 000).
  match('1', 'Everton – Chelsea', 500_000_00, [{ key: 'h2h', worst: 300_000_00 }, { key: 'totals', worst: 200_000_00 }], 2),
  // 62 % du plafond du match.
  match('2', 'Lyon – Nice', 310_000_00, [{ key: 'h2h', worst: 110_000_00 }, { key: 'totals', worst: 200_000_00 }]),
  // La maison gagne quelle que soit l'issue : aucun plafond en jeu.
  match('3', 'Lille – Lens', -40_00, [{ key: 'h2h', worst: -40_00 }]),
];

describe('plafonds d\'engagement sur l\'écran des risques', () => {
  it('affiche les plafonds et la part atteinte par chaque match et chaque marché', async () => {
    mockApi({
      'GET /me': me(['settings.view', 'settings.update']),
      'GET /admin/risk/exposure': exposure(EVENTS),
      'GET /admin/settings': settings(500_000_00, 250_000_00),
    });

    renderApp('/admin/risk');

    const limits = await screen.findByRole('region', { name: "Plafonds d'engagement" });
    expect(text(limits)).toContain('Perte maximale sur un match500 000,00 HTG');
    expect(text(limits)).toContain('Perte maximale sur un marché250 000,00 HTG');
    expect(text(limits)).toContain('Matchs au plafond1');
    expect(within(limits).getByRole('link', { name: 'Paramètres' })).toHaveAttribute('href', '/admin/settings');

    const everton = screen.getByRole('button', { name: /Everton – Chelsea/ });
    expect(within(everton).getByText('Plafond atteint')).toBeInTheDocument();
    expect(within(screen.getByRole('button', { name: /Lyon – Nice/ })).getByText('62 % du plafond')).toBeInTheDocument();
    // Pas de perte possible : rien à comparer au plafond.
    expect(within(screen.getByRole('button', { name: /Lille – Lens/ })).queryByText(/plafond/i)).toBeNull();

    await userEvent.click(everton);
    expect(text(screen.getByRole('table', { name: /Résultat du match/ }))).toContain('Plafond atteint');
    expect(text(screen.getByRole('table', { name: /Plus\/Moins/ }))).toContain('80 % du plafond');
    expect(screen.getByText(/un combiné compte sur ce match dès que toutes ses autres sélections sont gagnées ou annulées/)).toBeInTheDocument();
  });

  it('compte un match dont un seul marché est au plafond', async () => {
    mockApi({
      'GET /me': me(['settings.view']),
      'GET /admin/risk/exposure': exposure([match('4', 'Metz – Brest', 260_000_00, [{ key: 'h2h', worst: 260_000_00 }])]),
      'GET /admin/settings': settings(500_000_00, 250_000_00),
    });

    renderApp('/admin/risk');

    const limits = await screen.findByRole('region', { name: "Plafonds d'engagement" });
    expect(text(limits)).toContain('Matchs au plafond1');
    expect(within(screen.getByRole('button', { name: /Metz – Brest/ })).getByText('52 % du plafond')).toBeInTheDocument();
    // Sans settings.update : pas de lien vers la modification.
    expect(within(limits).queryByRole('link', { name: 'Paramètres' })).toBeNull();
  });

  it('affiche l\'exposition seule sans la permission settings.view, sans appeler les paramètres', async () => {
    const fetchMock = mockApi({
      'GET /me': me([]),
      'GET /admin/risk/exposure': exposure(EVENTS),
    });

    renderApp('/admin/risk');

    expect(await screen.findByRole('button', { name: /Everton – Chelsea/ })).toBeInTheDocument();
    expect(screen.queryByRole('region', { name: "Plafonds d'engagement" })).toBeNull();
    expect(screen.queryByText(/plafond/i)).toBeNull();
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('/admin/settings'))).toBe(false);
  });
});
