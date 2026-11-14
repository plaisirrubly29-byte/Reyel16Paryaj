import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { currentQueryClient, mockApi, player, renderApp } from '../../test/renderApp';

afterEach(() => vi.unstubAllGlobals());

const NBSP = new RegExp(`[${String.fromCharCode(0xa0, 0x202f)}]`, 'g');
const clean = (el: Element | null) => (el?.textContent ?? '').replace(NBSP, ' ');
const htg = (minor: number) => ({ minor, currency: 'HTG' });
const page = (data: unknown[]) => ({
  data,
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 5, total: data.length, last_page: 1, from: 1, to: data.length },
});

const withdrawal = (status: string, extra: Record<string, unknown> = {}) => ({
  reference: 'pay_01abc', provider: 'plopplop', status, amount: htg(400_00), method: 'moncash', method_label: 'MonCash', recipient: '****3456',
  cancellable: status === 'requested', created_at: '2026-11-13T10:00:00+00:00', completed_at: null, ...extra,
});

const methods = (enabled: boolean) => ({
  status: 200,
  body: { data: {
    methods: [], min_amount: htg(100_00), max_amount: htg(100_000_00),
    withdrawal: { methods: enabled ? [{ provider: 'plopplop', method: 'moncash', label: 'MonCash' }, { provider: 'plopplop', method: 'natcash', label: 'NatCash' }] : [], min_amount: htg(100_00), max_amount: htg(50_000_00) },
  } },
});

const base = {
  'GET /me': { status: 200, body: { data: { ...player, phone: '34123456' } } },
  'GET /wallet': { status: 200, body: { data: { currency: 'HTG', balance: htg(1_000_00), status: 'active', simulated: false } } },
  'GET /wallet/transactions': { status: 200, body: page([]) },
  'GET /payments/methods': methods(true),
  'GET /payments/deposits': { status: 200, body: page([]) },
  'GET /payments/withdrawals': { status: 200, body: page([]) },
};

const card = () => screen.getByRole('region', { name: 'Retrait' });

describe('retrait en ligne', () => {
  it('demande un retrait vers MonCash avec le numéro du compte, et le montant est réservé', async () => {
    const fetchMock = mockApi({ ...base, 'POST /payments/withdrawals': { status: 201, body: { data: withdrawal('requested') } } });
    renderApp('/wallet');

    const phone = await screen.findByLabelText('Numéro du portefeuille');
    expect(phone).toHaveValue('34123456'); // prérempli avec le téléphone du compte
    await userEvent.type(screen.getByLabelText('Montant du retrait'), '400');
    await userEvent.click(screen.getByRole('button', { name: 'Demander le retrait' }));

    const done = await screen.findByText('Retrait demandé');
    expect(clean(done.closest('.alert'))).toContain('400,00 HTG seront versés sur votre MonCash au +509 ****3456');
    const post = fetchMock.mock.calls.find(([u, init]) => String(u).endsWith('/payments/withdrawals') && init?.method === 'POST')!;
    expect(JSON.parse(String(post[1]!.body))).toEqual({ provider: 'plopplop', method: 'moncash', recipient: '34123456', amount_minor: 40000 });
    expect(new Headers(post[1]!.headers).get('Idempotency-Key')).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('refuse avant tout appel un montant supérieur au solde ou un numéro invalide', async () => {
    const fetchMock = mockApi(base);
    renderApp('/wallet');

    await userEvent.type(await screen.findByLabelText('Montant du retrait'), '1500');
    expect(within(card()).getByText('Ce montant dépasse votre solde disponible.')).toBeInTheDocument();

    await userEvent.clear(screen.getByLabelText('Numéro du portefeuille'));
    await userEvent.type(screen.getByLabelText('Numéro du portefeuille'), '1234');
    expect(within(card()).getByText('Indiquez un numéro haïtien à 8 chiffres.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Demander le retrait' })).toBeDisabled();
    expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false);
  });

  it('suit ses retraits et peut annuler celui qui attend son versement', async () => {
    let cancelled = false;
    const fetchMock = mockApi({
      ...base,
      'GET /payments/withdrawals': () => ({ status: 200, body: page([cancelled ? withdrawal('cancelled') : withdrawal('requested'), withdrawal('paid', { reference: 'pay_02' })]) }),
      'POST /payments/withdrawals/pay_01abc/cancel': () => { cancelled = true; return { status: 200, body: { data: withdrawal('cancelled') } }; },
    });
    renderApp('/wallet');

    await screen.findByRole('region', { name: 'Retrait' });
    expect(await within(card()).findByText(/En attente de versement/)).toBeInTheDocument();
    expect(within(card()).getByText(/Versé/)).toBeInTheDocument();

    await userEvent.click(within(card()).getByRole('button', { name: 'Annuler' }));

    expect(await within(card()).findByText(/Annulé, montant rendu/)).toBeInTheDocument();
    expect(within(card()).queryByRole('button', { name: 'Annuler' })).toBeNull();
    await waitFor(() => expect(fetchMock.mock.calls.filter(([u]) => String(u).endsWith('/wallet')).length).toBeGreaterThan(1));
  });

  it('explique le plafond de retraits sur 24 heures', async () => {
    mockApi({
      ...base,
      'POST /payments/withdrawals': { status: 422, body: { error: { code: 'withdrawal_daily_limit_exceeded', message: 'Plafond.', details: { limit_minor: 100_000_00, remaining_minor: 200_00 } } } },
    });
    renderApp('/wallet');

    await userEvent.type(await screen.findByLabelText('Montant du retrait'), '400');
    await userEvent.click(screen.getByRole('button', { name: 'Demander le retrait' }));

    const alert = await screen.findByText('Plafond de retraits atteint');
    expect(clean(alert.closest('.alert'))).toContain('Vous pouvez encore retirer 200,00 HTG');
  });

  it('suit le versement : retrait échoué annoncé et solde relu (montant rendu)', async () => {
    let state = 'none';
    const fetchMock = mockApi({
      ...base,
      'GET /wallet': () => ({ status: 200, body: { data: { currency: 'HTG', balance: htg(state === 'failed' ? 1_000_00 : 600_00), status: 'active', simulated: false } } }),
      'GET /payments/withdrawals': () => ({ status: 200, body: page(state === 'none' ? [] : [withdrawal(state, { recipient: '****0000' })]) }),
      'POST /payments/withdrawals': () => { state = 'requested'; return { status: 201, body: { data: withdrawal('requested', { recipient: '****0000' }) } }; },
    });
    renderApp('/wallet');

    await userEvent.clear(await screen.findByLabelText('Numéro du portefeuille'));
    await userEvent.type(screen.getByLabelText('Numéro du portefeuille'), '34120000');
    await userEvent.type(screen.getByLabelText('Montant du retrait'), '400');
    await userEvent.click(screen.getByRole('button', { name: 'Demander le retrait' }));
    expect(await screen.findByText('Retrait demandé')).toBeInTheDocument();

    // Le prestataire refuse ; la liste rafraîchie l'annonce et le solde est relu.
    state = 'failed';
    const before = fetchMock.mock.calls.filter(([u]) => String(u).endsWith('/wallet')).length;
    await currentQueryClient().invalidateQueries({ queryKey: ['withdrawals'] });

    const failed = await screen.findByText('Retrait échoué');
    expect(clean(failed.closest('.alert'))).toContain('400,00 HTG ont été rendus sur votre solde');
    await waitFor(() => expect(fetchMock.mock.calls.filter(([u]) => String(u).endsWith('/wallet')).length).toBeGreaterThan(before));
    await waitFor(() => expect(clean(document.querySelector('.balance'))).toBe('1 000,00 HTG'));
  });

  it('un seul retrait à la fois : le formulaire attend la fin du retrait en cours', async () => {
    mockApi({ ...base, 'GET /payments/withdrawals': { status: 200, body: page([withdrawal('processing')]) } });
    renderApp('/wallet');

    const banner = await screen.findByText('Retrait en cours');
    expect(clean(banner.closest('.alert'))).toContain('Votre retrait de 400,00 HTG est en cours');
    await userEvent.type(screen.getByLabelText('Montant du retrait'), '100');
    expect(screen.getByRole('button', { name: 'Demander le retrait' })).toBeDisabled();
    expect(within(card()).getByText(/Versement en cours/)).toBeInTheDocument();
    expect(within(card()).queryByRole('button', { name: 'Annuler' })).toBeNull(); // déjà envoyé au prestataire
  });

  it("garde les instructions de retrait auprès de l'opérateur quand les retraits en ligne sont fermés", async () => {
    mockApi({ ...base, 'GET /payments/methods': methods(false) });
    renderApp('/wallet');

    await screen.findByRole('region', { name: 'Retrait' });
    expect(await within(card()).findByText(/Demandez votre retrait auprès de REYEL 16 PARYAJ/)).toBeInTheDocument();
    expect(screen.queryByLabelText('Montant du retrait')).toBeNull();
  });
});
