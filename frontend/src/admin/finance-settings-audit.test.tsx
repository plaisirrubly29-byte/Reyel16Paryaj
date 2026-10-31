import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, player, renderApp } from '../test/renderApp';

afterEach(() => vi.unstubAllGlobals());

const me = (permissions: string[], id = '99') => ({
  status: 200,
  body: {
    data: {
      ...player,
      id,
      name: 'Admin',
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
  meta: { current_page: 1, per_page: 20, total: data.length, last_page: 1, from: 1, to: data.length },
});

const htg = (minor: number) => ({ minor, currency: 'HTG' });

const entry = (overrides: Record<string, unknown> = {}) => ({
  id: '31',
  wallet_id: '4',
  user_id: '7',
  type: 'simulated_credit',
  amount: htg(500_00),
  balance_after: htg(500_00),
  reference: 'welcome:7',
  related: null,
  reversal_of_id: null,
  reversed_by_id: null,
  description: 'Crédit de bienvenue',
  created_by: null,
  created_at: '2026-10-29T10:00:00+00:00',
  correctable: true,
  ...overrides,
});

const financeCorrection = (overrides: Record<string, unknown> = {}) => ({
  id: '5',
  status: 'pending',
  reason: 'Crédit attribué deux fois',
  ledger_entry: entry(),
  requested_by: { id: '12', name: 'Fanny Finance' },
  requested_at: '2026-10-29T11:00:00+00:00',
  decided_by: null,
  decision_reason: null,
  decided_at: null,
  reversal_entry_id: null,
  ...overrides,
});

const headers = (init?: RequestInit) => init?.headers as Record<string, string>;

describe('écrans Finance', () => {
  it("liste le grand livre, transmet les filtres et demande une correction avec motif", async () => {
    let post: RequestInit | undefined;
    const fetchMock = mockApi({
      'GET /me': me(['ledger.view', 'finance.correct']),
      'GET /admin/ledger': { status: 200, body: page([entry(), entry({ id: '32', type: 'stake', amount: htg(-100_00), related: { type: 'bet', id: '41' }, correctable: false })]) },
      'POST /admin/finance/corrections': (init) => {
        post = init;
        return { status: 201, body: { data: financeCorrection() } };
      },
    });

    const router = renderApp('/admin/finance');

    const table = await screen.findByRole('table', { name: 'Grand livre' });
    expect(within(table).getByRole('link', { name: 'Ticket n° 41' })).toHaveAttribute('href', '/admin/tickets/41');
    expect(within(table).getAllByText('Demander une correction')).toHaveLength(1); // l'écriture du ticket n'est pas corrigeable

    await userEvent.selectOptions(screen.getByLabelText('Type'), 'stake');
    await waitFor(() => expect(router.state.location.search).toBe('?type=stake'));
    const urls = fetchMock.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('/admin/ledger'));
    expect(urls[urls.length - 1]).toContain('type=stake');

    await userEvent.click(within(table).getByText('Demander une correction'));
    await userEvent.type(screen.getByLabelText('Motif (obligatoire)'), 'Crédit attribué deux fois');
    await userEvent.click(screen.getByRole('button', { name: 'Envoyer la demande' }));

    expect(await screen.findByText(/attend la validation d'un second administrateur/)).toBeInTheDocument();
    expect(JSON.parse(String(post?.body))).toEqual({ ledger_entry_id: 31, reason: 'Crédit attribué deux fois' });
    expect(headers(post)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('sans finance.correct, le grand livre est en lecture seule', async () => {
    mockApi({
      'GET /me': me(['ledger.view']),
      'GET /admin/ledger': { status: 200, body: page([entry()]) },
    });

    renderApp('/admin/finance');

    await screen.findByRole('table', { name: 'Grand livre' });
    expect(screen.queryByText('Demander une correction')).toBeNull();
  });

  it('le rapprochement signale les écarts', async () => {
    mockApi({
      'GET /me': me(['ledger.view']),
      'GET /admin/finance/reconciliation': {
        status: 200,
        body: {
          data: {
            generated_at: '2026-10-29T12:00:00+00:00',
            checked: { wallets: 3, ledger_entries: 12, tickets: 4 },
            totals: [{ currency: 'HTG', type: 'stake', entries: 4, sum: htg(-400_00) }],
            discrepancies_count: 1,
            discrepancies: [{ kind: 'ticket_amount', ticket_id: '41', reference: 'R16-7KQ2M9XAPB', status: 'won', expected: htg(110_00), actual: htg(-100_00) }],
            truncated: false,
          },
        },
      },
    });

    renderApp('/admin/finance/reconciliation');

    expect(await screen.findByText('1 écart à analyser')).toBeInTheDocument();
    const table = screen.getByRole('table', { name: 'Écarts' });
    expect(within(table).getByText('Montant de ticket incohérent')).toBeInTheDocument();
    expect(within(table).getByRole('link', { name: 'R16-7KQ2M9XAPB' })).toHaveAttribute('href', '/admin/tickets/41');
  });

  it('un second administrateur valide la correction avec un motif', async () => {
    let post: RequestInit | undefined;
    mockApi({
      'GET /me': me(['ledger.view', 'finance.correct', 'finance.approve']),
      'GET /admin/finance/corrections/5': { status: 200, body: { data: financeCorrection() } },
      'POST /admin/finance/corrections/5/approve': (init) => {
        post = init;
        return { status: 200, body: { data: financeCorrection({ status: 'approved', decided_by: { id: '99', name: 'Admin' }, decision_reason: 'Vérifié sur le relevé', decided_at: '2026-10-29T12:00:00+00:00', reversal_entry_id: '33' }) } };
      },
    });

    renderApp('/admin/finance/corrections/5');

    expect(await screen.findByText(/Double validation/)).toBeInTheDocument();
    const approve = screen.getByRole('button', { name: 'Valider' });
    expect(approve).toBeDisabled();
    await userEvent.type(screen.getAllByLabelText('Motif (obligatoire)')[0], 'Vérifié sur le relevé');
    await userEvent.click(approve);

    expect(await screen.findByRole('heading', { name: 'Validée' })).toBeInTheDocument();
    expect(screen.getByText('Écriture n° 33')).toBeInTheDocument();
    expect(JSON.parse(String(post?.body))).toEqual({ reason: 'Vérifié sur le relevé' });
    expect(headers(post)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('le demandeur ne peut que retirer sa demande', async () => {
    mockApi({
      'GET /me': me(['ledger.view', 'finance.correct', 'finance.approve'], '12'),
      'GET /admin/finance/corrections/5': { status: 200, body: { data: financeCorrection() } },
    });

    renderApp('/admin/finance/corrections/5');

    expect(await screen.findByRole('button', { name: 'Retirer' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Valider' })).toBeNull();
  });
});

const settings = (overrides: Record<string, unknown> = {}) => ({
  version: 0,
  updated_at: null,
  updated_by: null,
  items: [
    { key: 'betting.min_stake_minor', group: 'Paris', label: 'Mise minimale', type: 'money', help: null, options: null, currency: 'HTG', value: 10_00, default: 10_00 },
    { key: 'betting.allow_live', group: 'Paris', label: 'Paris en direct autorisés', type: 'boolean', help: null, options: null, currency: null, value: false, default: false },
    { key: 'sports_data.provider', group: 'Fournisseur', label: 'Fournisseur de données sportives', type: 'choice', help: null, options: ['fake', 'theodds'], currency: null, value: 'fake', default: 'fake' },
  ],
  ...overrides,
});

describe('écran Paramètres', () => {
  it('la Conformité consulte sans pouvoir modifier', async () => {
    mockApi({
      'GET /me': me(['settings.view']),
      'GET /admin/settings': { status: 200, body: { data: settings() } },
      'GET /admin/settings/versions': { status: 200, body: page([]) },
    });

    renderApp('/admin/settings');

    expect(await screen.findByText(/Consultation seule/)).toBeInTheDocument();
    expect(screen.getByText('10,00 HTG')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Enregistrer la nouvelle version' })).toBeNull();
  });

  it('enregistre une nouvelle version : montants en unité mineure, version de base et motif', async () => {
    let put: RequestInit | undefined;
    mockApi({
      'GET /me': me(['settings.view', 'settings.update']),
      'GET /admin/settings': { status: 200, body: { data: settings() } },
      'GET /admin/settings/versions': { status: 200, body: page([]) },
      'PUT /admin/settings': (init) => {
        put = init;
        return { status: 200, body: { data: settings({ version: 1, updated_at: '2026-10-29T12:00:00+00:00', updated_by: { id: '99', name: 'Admin' } }) } };
      },
    });

    renderApp('/admin/settings');

    const stake = await screen.findByLabelText('Mise minimale (HTG)');
    const submit = screen.getByRole('button', { name: 'Enregistrer la nouvelle version' });
    await userEvent.type(screen.getByLabelText('Motif (obligatoire)'), 'Ajustement des limites V1');
    expect(submit).toBeDisabled(); // aucune modification

    await userEvent.clear(stake);
    await userEvent.type(stake, '25,5');
    await userEvent.click(screen.getByLabelText('Paris en direct autorisés'));
    expect(screen.getByText('Enregistrer 2 modifications (version 1)')).toBeInTheDocument();
    await userEvent.click(submit);

    expect(await screen.findByText(/Version 1 enregistrée/)).toBeInTheDocument();
    expect(JSON.parse(String(put?.body))).toEqual({
      values: { betting: { min_stake_minor: 25_50, allow_live: true } },
      base_version: 0,
      reason: 'Ajustement des limites V1',
    });
    expect(headers(put)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('refuse un montant invalide avant envoi', async () => {
    mockApi({
      'GET /me': me(['settings.view', 'settings.update']),
      'GET /admin/settings': { status: 200, body: { data: settings() } },
      'GET /admin/settings/versions': { status: 200, body: page([]) },
    });

    renderApp('/admin/settings');

    const stake = await screen.findByLabelText('Mise minimale (HTG)');
    await userEvent.clear(stake);
    await userEvent.type(stake, '12,345');
    await userEvent.type(screen.getByLabelText('Motif (obligatoire)'), 'Ajustement des limites V1');

    expect(screen.getByText('Montant invalide.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Enregistrer la nouvelle version' })).toBeDisabled();
  });
});

describe('écran Audit & rapports', () => {
  it("filtre le journal et exporte les lignes filtrées avec un motif", async () => {
    let exported: RequestInit | undefined;
    const createObjectURL = vi.fn(() => 'blob:export');
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL, revokeObjectURL: vi.fn() }));
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {}); // jsdom ne télécharge pas
    const fetchMock = mockApi({
      'GET /me': me(['audit.view', 'audit.export']),
      'GET /admin/audit-logs': {
        status: 200,
        body: page([{
          id: '200', action: 'finance.correction_approved', actor: { id: '99', email: 'finance@example.com' }, subject: { type: 'finance_correction', id: '5' },
          before: { status: 'pending' }, after: { status: 'approved' }, reason: 'Vérifié sur le relevé', metadata: null,
          ip_address: '127.0.0.1', request_id: 'abc', created_at: '2026-10-29T12:00:00+00:00',
        }]),
      },
      'POST /admin/audit-logs/export': (init) => {
        exported = init;
        return { status: 200, body: 'id;created_at' };
      },
    });

    const router = renderApp('/admin/audit');

    const table = await screen.findByRole('table', { name: "Journal d'audit" });
    expect(within(table).getByText('Correction financière validée')).toBeInTheDocument();
    expect(within(table).getByText('finance_correction #5')).toBeInTheDocument();

    await userEvent.selectOptions(screen.getByLabelText('Action'), 'finance.correction_approved');
    await waitFor(() => expect(router.state.location.search).toBe('?action=finance.correction_approved'));
    const urls = fetchMock.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('/admin/audit-logs?'));
    expect(urls[urls.length - 1]).toContain('action=finance.correction_approved');

    await userEvent.click(screen.getByText('Exporter ces lignes (CSV)'));
    await userEvent.type(screen.getByLabelText('Motif (obligatoire)'), 'Contrôle trimestriel');
    await userEvent.click(screen.getByRole('button', { name: 'Télécharger le CSV' }));

    expect(await screen.findByText('Export téléchargé et journalisé.')).toBeInTheDocument();
    expect(JSON.parse(String(exported?.body))).toEqual({ action: 'finance.correction_approved', reason: 'Contrôle trimestriel' });
    expect(createObjectURL).toHaveBeenCalled();
    expect(click).toHaveBeenCalled();
    click.mockRestore();
  });

  it("sans audit.export, pas d'export", async () => {
    mockApi({
      'GET /me': me(['audit.view']),
      'GET /admin/audit-logs': { status: 200, body: page([]) },
    });

    renderApp('/admin/audit');

    expect(await screen.findByText('Journal vide.')).toBeInTheDocument();
    expect(screen.queryByText('Exporter ces lignes (CSV)')).toBeNull();
  });
});
