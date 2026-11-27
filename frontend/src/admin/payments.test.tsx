import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, player, renderApp } from '../test/renderApp';
import { paymentStatusLabel } from './api/payments';
import { visibleSections } from './navigation';

afterEach(() => vi.unstubAllGlobals());

const me = (permissions: string[]) => ({
  status: 200,
  body: {
    data: {
      ...player,
      id: '99',
      name: 'Fabien Finance',
      is_admin: true,
      roles: ['finance'],
      permissions: ['dashboard.view', ...permissions],
      mfa: { enabled: true, verified: true, required: true },
    },
  },
});

const page = (data: unknown[]) => ({
  data,
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 25, total: data.length, last_page: 1, from: data.length ? 1 : null, to: data.length },
});

const payment = (overrides: Record<string, unknown> = {}) => ({
  id: '31',
  user_id: '41',
  provider: 'plopplop',
  direction: 'payout',
  status: 'failed',
  amount: { minor: 400_00, currency: 'HTG' },
  reference: 'pay_retrait31',
  provider_reference: 'PP-889',
  ledger_entry_id: '120',
  review_reason: null,
  reported: null,
  late: false,
  expires_at: null,
  completed_at: '2026-11-27T10:05:00+00:00',
  created_at: '2026-11-27T10:00:00+00:00',
  ...overrides,
});

describe('suivi des paiements (V2-B03, #145)', () => {
  it('est dans le menu Contrôle seulement avec ledger.view (Super Admin, Finance, Conformité)', () => {
    expect(visibleSections(['ledger.view']).map((s) => s.label)).toContain('Paiements');
    expect(visibleSections(['dashboard.view', 'users.view', 'tickets.view']).map((s) => s.label)).not.toContain('Paiements');
  });

  it('nomme l\'étape selon le sens du paiement', () => {
    expect(paymentStatusLabel('succeeded', 'payout')).toBe('Versé');
    expect(paymentStatusLabel('succeeded', 'deposit')).toBe('Crédité');
    expect(paymentStatusLabel('failed', 'payout')).toBe('Échoué, montant rendu');
    expect(paymentStatusLabel('pending', 'deposit')).toBe('En attente du paiement');
  });

  it('montre les retraits par défaut, puis filtre par statut et par référence', async () => {
    const fetchMock = mockApi({
      'GET /me': me(['ledger.view']),
      'GET /admin/payments': { status: 200, body: page([payment(), payment({ id: '32', status: 'pending', reference: 'pay_attente32', completed_at: null })]) },
    });

    const router = renderApp('/admin/payments');

    const table = await screen.findByRole('table', { name: 'Retraits' });
    expect(within(table).getByRole('link', { name: 'pay_retrait31' })).toHaveAttribute('href', '/admin/payments/31');
    expect(within(table).getAllByRole('link', { name: 'Joueur n° 41' })[0]).toHaveAttribute('href', '/admin/users/41');
    expect(within(table).getByText('Échoué, montant rendu')).toBeInTheDocument();
    expect(within(table).getByText('Demandé')).toBeInTheDocument();
    expect(within(table).getAllByText('Plop Plop')).toHaveLength(2);
    const urls = () => fetchMock.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('/admin/payments'));
    expect(urls()[0]).toContain('direction=payout');

    await userEvent.selectOptions(screen.getByLabelText('Statut'), 'failed');
    await waitFor(() => expect(router.state.location.search).toBe('?status=failed'));
    await waitFor(() => expect(urls()[urls().length - 1]).toContain('status=failed'));

    await userEvent.type(screen.getByLabelText('Référence'), 'PP-889{Enter}');
    await waitFor(() => expect(urls()[urls().length - 1]).toContain('reference=PP-889'));
  });

  it('passe aux dépôts', async () => {
    const fetchMock = mockApi({
      'GET /me': me(['ledger.view']),
      'GET /admin/payments': { status: 200, body: page([payment({ direction: 'deposit', status: 'review', reference: 'pay_depot7' })]) },
    });

    renderApp('/admin/payments?direction=deposit');

    const table = await screen.findByRole('table', { name: 'Dépôts' });
    expect(within(table).getByText('À vérifier')).toBeInTheDocument();
    expect(String(fetchMock.mock.calls.find(([u]) => String(u).includes('/admin/payments'))?.[0])).toContain('direction=deposit');
  });
});

describe('fiche d\'un paiement', () => {
  it('explique un retrait échoué, donne les références et les messages du prestataire', async () => {
    mockApi({
      'GET /me': me(['ledger.view', 'kyc.view', 'aml.view']),
      'GET /admin/payments/31': {
        status: 200,
        body: { data: payment({ webhooks: [{ id: '5', event_id: 'evt_1', signature_valid: true, outcome: 'applied', payload: { status: 'rejected' }, ip_address: null, created_at: '2026-11-27T10:05:00+00:00' }] }) },
      },
    });

    renderApp('/admin/payments/31');

    expect(await screen.findByRole('heading', { name: 'Retrait pay_retrait31' })).toBeInTheDocument();
    expect(screen.getByText(/le montant a été rendu au solde du joueur par une écriture compensatoire/)).toBeInTheDocument();
    expect(screen.getByText('PP-889')).toBeInTheDocument();
    expect(screen.getByText('n° 120')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Grand livre du joueur' })).toHaveAttribute('href', '/admin/finance?user_id=41');
    expect(screen.getByRole('link', { name: 'Dossiers KYC du joueur' })).toBeInTheDocument();
    const messages = screen.getByRole('table', { name: 'Messages du prestataire' });
    expect(within(messages).getByText('applied')).toBeInTheDocument();
    expect(within(messages).getByText('Valide')).toBeInTheDocument();
  });

  it('signale un dépôt mis en revue avec le montant annoncé par le prestataire', async () => {
    mockApi({
      'GET /me': me(['ledger.view']),
      'GET /admin/payments/7': {
        status: 200,
        body: { data: payment({ id: '7', direction: 'deposit', status: 'review', review_reason: 'amount_mismatch', reported: { amount_minor: 900_00, currency: 'HTG' }, ledger_entry_id: null, webhooks: [] }) },
      },
    });

    renderApp('/admin/payments/7');

    expect(await screen.findByText(/Mis en revue : le prestataire a annoncé un autre montant/)).toBeInTheDocument();
    expect(screen.getByText(/amount_mismatch/)).toBeInTheDocument();
    expect(screen.getByText(/montant annoncé par le prestataire : 900,00/)).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Dossiers KYC du joueur' })).not.toBeInTheDocument();
    expect(screen.getByText('Aucun message reçu du prestataire.')).toBeInTheDocument();
  });
});
