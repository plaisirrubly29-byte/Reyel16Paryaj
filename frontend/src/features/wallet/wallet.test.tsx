import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, player, renderApp } from '../../test/renderApp';

afterEach(() => vi.unstubAllGlobals());

const NBSP = new RegExp(`[${String.fromCharCode(0xa0, 0x202f)}]`, 'g');
const text = (el: HTMLElement) => (el.textContent ?? '').replace(NBSP, ' ');

const htg = (minor: number) => ({ minor, currency: 'HTG' });

const wallet = { data: { currency: 'HTG', balance: htg(1_234_50), status: 'active', simulated: true } };

const entry = (id: string, type: string, amount: number, balanceAfter: number, related: unknown = null) => ({
  id, type, amount: htg(amount), balance_after: htg(balanceAfter), related, description: null, created_at: '2026-10-15T18:30:00+00:00',
});

const page = (data: unknown[], meta: Partial<{ current_page: number; last_page: number; total: number }> = {}) => ({
  data,
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 20, total: data.length, last_page: 1, from: 1, to: data.length, ...meta },
});

describe('page portefeuille', () => {
  it('affiche le solde fourni par le serveur, sans le recalculer', async () => {
    mockApi({
      'GET /me': { status: 200, body: { data: player } },
      'GET /wallet': { status: 200, body: wallet },
      // Écritures dont la somme (50,00) diffère volontairement du solde de l'API (1 234,50).
      'GET /wallet/transactions': { status: 200, body: page([entry('2', 'stake', -50_00, 1_234_50, { type: 'bet', id: '7' }), entry('1', 'simulated_credit', 100_00, 1_284_50)]) },
    });

    renderApp('/wallet');

    const balance = await screen.findByText((_, el) => el?.classList.contains('balance') ?? false);
    expect(text(balance)).toBe('1 234,50 HTG');
    expect(screen.getByText('Fonds fictifs')).toBeInTheDocument();
  });

  it("liste l'historique avec les montants signés et le lien vers le ticket", async () => {
    mockApi({
      'GET /me': { status: 200, body: { data: player } },
      'GET /wallet': { status: 200, body: wallet },
      'GET /wallet/transactions': { status: 200, body: page([entry('2', 'stake', -50_00, 1_234_50, { type: 'bet', id: '7' })]) },
    });

    renderApp('/wallet');

    const row = (await screen.findByRole('link', { name: 'ticket 7' })).closest('tr')!;
    expect(within(row).getByText('Mise', { exact: false })).toBeInTheDocument();
    expect(text(within(row).getByText((_, el) => el?.classList.contains('debit') ?? false))).toBe('-50,00 HTG');
  });

  it('filtre par type et pagine via les paramètres de requête', async () => {
    const fetchMock = mockApi({
      'GET /me': { status: 200, body: { data: player } },
      'GET /wallet': { status: 200, body: wallet },
      'GET /wallet/transactions': (_, url) => ({
        status: 200,
        body: page([entry(url.searchParams.get('page') ?? '1', 'payout', 10_00, 1_234_50)], { current_page: Number(url.searchParams.get('page') ?? 1), last_page: 3, total: 41 }),
      }),
    });
    const router = renderApp('/wallet');

    await userEvent.click(await screen.findByRole('button', { name: 'Suivant' }));
    await waitFor(() => expect(router.state.location.search).toBe('?page=2'));

    await userEvent.selectOptions(screen.getByLabelText('Type'), 'payout');
    await waitFor(() => expect(router.state.location.search).toBe('?type=payout'));

    const urls = fetchMock.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('/wallet/transactions'));
    expect(urls.some((u) => u.includes('page=2'))).toBe(true);
    expect(urls[urls.length - 1]).toContain('type=payout');
  });

  it('affiche un état vide et prévient si le portefeuille est gelé', async () => {
    mockApi({
      'GET /me': { status: 200, body: { data: player } },
      'GET /wallet': { status: 200, body: { data: { ...wallet.data, status: 'frozen' } } },
      'GET /wallet/transactions': { status: 200, body: page([]) },
    });

    renderApp('/wallet');

    expect(await screen.findByText('Aucune opération pour le moment.')).toBeInTheDocument();
    expect(screen.getByText('Portefeuille gelé')).toBeInTheDocument();
  });
});
