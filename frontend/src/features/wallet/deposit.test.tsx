import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, player, renderApp } from '../../test/renderApp';
import { providerNavigation } from './api/deposits';

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

const NBSP = new RegExp(`[${String.fromCharCode(0xa0, 0x202f)}]`, 'g');
const htg = (minor: number) => ({ minor, currency: 'HTG' });
const page = (data: unknown[]) => ({
  data,
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 5, total: data.length, last_page: 1, from: 1, to: data.length },
});

const deposit = (status: string, extra: Record<string, unknown> = {}) => ({
  reference: 'pay_01abc', provider: 'fake', provider_label: 'Paiement test', status, amount: htg(1_500_00),
  redirect_url: status === 'pending' ? '/api/v1/payments/fake/checkout/pay_01abc' : null,
  created_at: '2026-11-12T10:00:00+00:00', expires_at: '2026-11-12T10:30:00+00:00', completed_at: null, ...extra,
});

const base = {
  'GET /me': { status: 200, body: { data: player } },
  'GET /wallet': { status: 200, body: { data: { currency: 'HTG', balance: htg(0), status: 'active', simulated: false } } },
  'GET /wallet/transactions': { status: 200, body: page([]) },
  'GET /payments/methods': { status: 200, body: { data: { methods: [{ provider: 'fake', label: 'Paiement test' }], min_amount: htg(100_00), max_amount: htg(100_000_00) } } },
  'GET /payments/deposits': { status: 200, body: page([]) },
};

describe('dépôt en ligne', () => {
  it("ouvre le dépôt puis envoie le joueur sur la page du prestataire, sans créditer quoi que ce soit côté client", async () => {
    const open = vi.spyOn(providerNavigation, 'open').mockImplementation(() => {});
    const fetchMock = mockApi({ ...base, 'POST /payments/deposits': { status: 201, body: { data: deposit('pending') } } });

    renderApp('/wallet');

    await userEvent.type(await screen.findByLabelText('Montant du dépôt'), '1500');
    await userEvent.click(screen.getByRole('button', { name: 'Déposer avec Paiement test' }));

    await waitFor(() => expect(open).toHaveBeenCalledWith('http://localhost:8000/api/v1/payments/fake/checkout/pay_01abc'));
    const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST' && String(init.body).includes('amount_minor'))!;
    expect(JSON.parse(String(post[1]!.body))).toEqual({ provider: 'fake', amount_minor: 150000 });
    expect(new Headers(post[1]!.headers).get('Idempotency-Key')).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('refuse un montant hors des bornes du serveur avant tout appel', async () => {
    const fetchMock = mockApi(base);
    renderApp('/wallet');

    await userEvent.type(await screen.findByLabelText('Montant du dépôt'), '50');

    expect((await screen.findByRole('alert')).textContent?.replace(NBSP, ' ')).toContain('Le montant doit être compris entre 100,00');
    expect(screen.getByRole('button', { name: 'Déposer avec Paiement test' })).toBeDisabled();
    expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false);
  });

  it('explique un refus pour limite de dépôt avec le montant encore possible', async () => {
    mockApi({
      ...base,
      'POST /payments/deposits': {
        status: 422,
        body: { error: { code: 'deposit_limit_exceeded', message: 'Limite.', details: { period: 'day', limit_minor: 1_000_00, remaining_minor: 400_00 } } },
      },
    });
    renderApp('/wallet');

    await userEvent.type(await screen.findByLabelText('Montant du dépôt'), '500');
    await userEvent.click(screen.getByRole('button', { name: 'Déposer avec Paiement test' }));

    const alert = await screen.findByText('Limite de dépôt atteinte');
    const text = (alert.closest('.alert')?.textContent ?? '').replace(NBSP, ' ');
    expect(text).toContain('1 000,00 HTG sur 24 heures');
    expect(text).toContain('Vous pouvez encore déposer 400,00 HTG');
    expect(screen.getByRole('link', { name: 'Gérer mes limites' })).toHaveAttribute('href', '/responsible-gaming');
  });

  it('au retour du prestataire, annonce le dépôt crédité et rafraîchit le solde', async () => {
    let credited = false;
    const fetchMock = mockApi({
      ...base,
      'GET /wallet': () => ({ status: 200, body: { data: { currency: 'HTG', balance: htg(credited ? 1_500_00 : 0), status: 'active', simulated: false } } }),
      'GET /payments/deposits/pay_01abc': () => { credited = true; return { status: 200, body: { data: deposit('succeeded') } }; },
    });

    renderApp('/wallet?depot=pay_01abc');

    expect(await screen.findByText('Dépôt crédité')).toBeInTheDocument();
    await waitFor(() => expect((document.querySelector('.balance')?.textContent ?? '').replace(NBSP, ' ')).toBe('1 500,00 HTG'));
    expect(fetchMock.mock.calls.filter(([u]) => String(u).endsWith('/wallet')).length).toBeGreaterThan(1);
  });

  it('suit aussi le dépôt au retour de Plop Plop (?refference_id=…), et ignore une référence invalide', async () => {
    mockApi({ ...base, 'GET /payments/deposits/pay_01abc': { status: 200, body: { data: deposit('succeeded') } } });

    renderApp('/wallet?statut=ok&id_transaction=173763124912345&refference_id=pay_01abc');
    expect(await screen.findByText('Dépôt crédité')).toBeInTheDocument();
    cleanup();

    const fetchMock = mockApi(base);
    renderApp('/wallet?refference_id=../../admin');
    expect(await screen.findByLabelText('Montant du dépôt')).toBeInTheDocument();
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('/payments/deposits/'))).toBe(false);
  });

  it('explique un paiement refusé et un dépôt encore en attente', async () => {
    const open = vi.spyOn(providerNavigation, 'open').mockImplementation(() => {});
    mockApi({ ...base, 'GET /payments/deposits/pay_01abc': { status: 200, body: { data: deposit('failed') } } });
    renderApp('/wallet?depot=pay_01abc');
    expect(await screen.findByText('Paiement refusé')).toBeInTheDocument();
    expect(screen.getByText(/Votre solde n'a pas changé/)).toBeInTheDocument();
    cleanup();

    mockApi({ ...base, 'GET /payments/deposits/pay_01abc': { status: 200, body: { data: deposit('pending') } } });
    renderApp('/wallet?depot=pay_01abc');
    expect(await screen.findByText('En attente de confirmation')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Reprendre le paiement' }));
    expect(open).toHaveBeenCalledWith('http://localhost:8000/api/v1/payments/fake/checkout/pay_01abc');
  });

  it("n'affiche pas le formulaire quand le solde est gelé", async () => {
    mockApi({ ...base, 'GET /wallet': { status: 200, body: { data: { currency: 'HTG', balance: htg(0), status: 'frozen', simulated: false } } } });
    renderApp('/wallet');

    expect(await screen.findByText('Les dépôts sont indisponibles tant que votre solde est gelé.')).toBeInTheDocument();
    expect(screen.queryByLabelText('Montant du dépôt')).toBeNull();
  });
});
