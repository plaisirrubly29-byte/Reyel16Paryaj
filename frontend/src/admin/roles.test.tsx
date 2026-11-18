import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, player, renderApp } from '../test/renderApp';
import { visibleSections } from './navigation';

afterEach(() => vi.unstubAllGlobals());

/** Permissions des deux rôles de V2-G03 (backend/config/permissions.php). */
const RISK_FRAUD = [
  'dashboard.view', 'events.view', 'markets.suspend', 'users.view', 'tickets.view', 'settings.view', 'risk.manage',
  'aml.view', 'aml.review', 'audit.view', 'audit.export',
];
const AGENT_MANAGER = ['dashboard.view', 'users.view', 'agents.view', 'agents.manage', 'commissions.view'];

const me = (roles: string[], permissions: string[], id = '99') => ({
  status: 200,
  body: {
    data: {
      ...player,
      id,
      name: 'Admin',
      is_admin: true,
      roles,
      permissions,
      mfa: { enabled: true, verified: true, required: true },
    },
  },
});

const account = (overrides: Record<string, unknown> = {}) => ({
  id: '7',
  name: 'Ana Joueuse',
  phone: '34123456',
  email: 'ana@example.com',
  status: 'active',
  status_changed_at: null,
  is_admin: false,
  created_at: '2026-10-01T12:00:00+00:00',
  last_login_at: '2026-10-14T09:30:00+00:00',
  max_stake_minor: null,
  kyc_status: 'none',
  ...overrides,
});

const page = (data: unknown[]) => ({
  data,
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 20, total: data.length, last_page: 1, from: 1, to: data.length },
});

const settings = (overrides: Record<string, unknown> = {}) => ({
  version: 2,
  updated_at: '2026-11-18T10:00:00+00:00',
  updated_by: { id: '1', name: 'Super Admin' },
  items: [
    { key: 'betting.max_stake_minor', group: 'Paris', label: 'Mise maximale', type: 'money', help: null, options: null, currency: 'HTG', value: 50_000_00, default: 50_000_00 },
    { key: 'betting.max_event_liability_minor', group: 'Risque', label: 'Perte maximale sur un match', type: 'money', help: null, options: null, currency: 'HTG', value: 1_000_000_00, default: 1_000_000_00 },
  ],
  ...overrides,
});

const headers = (init?: RequestInit) => init?.headers as Record<string, string>;

describe('menu des nouveaux rôles (V2-G03)', () => {
  it('Risk & Fraud voit le risque, les événements, les utilisateurs, l\'audit et les paramètres, sans la finance', () => {
    const labels = visibleSections(RISK_FRAUD).map((s) => s.label);

    expect(labels).toEqual(expect.arrayContaining(['Événements & marchés', 'Tickets', 'Utilisateurs', 'Risques & exposition', 'Audit & rapports', 'Paramètres']));
    expect(labels).not.toContain('Finance');
    expect(labels).not.toContain("Vérification d'identité");
    expect(labels).not.toContain('Résultats & règlement');
  });

  it('Gestion des agents ne voit que la vue d\'ensemble et les utilisateurs', () => {
    expect(visibleSections(AGENT_MANAGER).map((s) => s.label)).toEqual(["Vue d'ensemble", 'Utilisateurs']);
  });

  it('affiche le nom des nouveaux rôles dans la barre et le filtre de la liste', async () => {
    mockApi({
      'GET /me': me(['risk_fraud'], RISK_FRAUD),
      'GET /admin/users': { status: 200, body: page([account()]) },
    });

    renderApp('/admin/users');

    const filter = await screen.findByLabelText('Rôle');
    expect(within(filter).getByRole('option', { name: 'Risk & Fraud' })).toBeInTheDocument();
    expect(within(filter).getByRole('option', { name: 'Gestion des agents' })).toBeInTheDocument();
    expect(screen.getAllByText('Risk & Fraud').length).toBeGreaterThan(1); // filtre + menu du compte
  });
});

describe('attribution des rôles dans la fiche utilisateur', () => {
  const superAdmin = me(['super_admin'], ['dashboard.view', 'users.view', 'users.view_personal_data', 'roles.manage']);

  it('le Super Admin donne un rôle avec un motif ; le joueur devient administrateur', async () => {
    let put: RequestInit | undefined;
    mockApi({
      'GET /me': superAdmin,
      'GET /admin/users/7': { status: 200, body: { data: account({ roles: [], mfa_enabled: false }) } },
      'PUT /admin/users/7/roles': (init) => {
        put = init;
        return { status: 200, body: { data: { id: '7', roles: ['risk_fraud'] } } };
      },
    });

    renderApp('/admin/users/7');

    const form = await screen.findByRole('form', { name: 'Rôles administratifs' });
    const submit = within(form).getByRole('button', { name: 'Enregistrer les rôles' });
    await userEvent.type(within(form).getByLabelText('Motif (obligatoire)'), 'Arrivée du responsable des risques');
    expect(submit).toBeDisabled(); // aucun changement

    await userEvent.click(within(form).getByRole('checkbox', { name: /Risk & Fraud/ }));
    expect(within(form).getByText(/devient administrateur/)).toBeInTheDocument();
    await userEvent.click(submit);

    expect(await within(form).findByText(/Rôles enregistrés/)).toBeInTheDocument();
    expect(JSON.parse(String(put?.body))).toEqual({ roles: ['risk_fraud'], reason: 'Arrivée du responsable des risques' });
    expect(headers(put)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('retire un rôle en gardant les autres, dans l\'ordre de la matrice', async () => {
    let put: RequestInit | undefined;
    mockApi({
      'GET /me': superAdmin,
      'GET /admin/users/7': { status: 200, body: { data: account({ is_admin: true, roles: ['agent_manager', 'support'], mfa_enabled: true }) } },
      'PUT /admin/users/7/roles': (init) => {
        put = init;
        return { status: 200, body: { data: { id: '7', roles: ['agent_manager'] } } };
      },
    });

    renderApp('/admin/users/7');

    const form = await screen.findByRole('form', { name: 'Rôles administratifs' });
    expect(within(form).getByRole('checkbox', { name: /Gestion des agents/ })).toBeChecked();
    await userEvent.click(within(form).getByRole('checkbox', { name: /Support/ }));
    await userEvent.click(within(form).getByRole('checkbox', { name: /Super Admin/ }));
    expect(within(form).getByText(/donne tous les droits/)).toBeInTheDocument();
    await userEvent.click(within(form).getByRole('checkbox', { name: /Super Admin/ }));
    await userEvent.type(within(form).getByLabelText('Motif (obligatoire)'), 'Changement de poste');
    await userEvent.click(within(form).getByRole('button', { name: 'Enregistrer les rôles' }));

    await within(form).findByText(/Rôles enregistrés/);
    expect(JSON.parse(String(put?.body)).roles).toEqual(['agent_manager']);
  });

  it("n'est pas proposé sur son propre compte", async () => {
    mockApi({
      'GET /me': me(['super_admin'], ['dashboard.view', 'users.view', 'users.view_personal_data', 'roles.manage'], '7'),
      'GET /admin/users/7': { status: 200, body: { data: account({ is_admin: true, roles: ['super_admin'], mfa_enabled: true }) } },
    });

    renderApp('/admin/users/7');

    expect(await screen.findByText('Double authentification')).toBeInTheDocument();
    expect(screen.queryByRole('form', { name: 'Rôles administratifs' })).toBeNull();
  });

  it("n'est pas proposé sans la permission roles.manage", async () => {
    mockApi({
      'GET /me': me(['compliance'], ['dashboard.view', 'users.view', 'users.view_personal_data']),
      'GET /admin/users/7': { status: 200, body: { data: account({ roles: [], mfa_enabled: false }) } },
    });

    renderApp('/admin/users/7');

    expect(await screen.findByText('Double authentification')).toBeInTheDocument();
    expect(screen.queryByRole('form', { name: 'Rôles administratifs' })).toBeNull();
  });
});

describe('Risk & Fraud (risk.manage)', () => {
  it('limite la mise d\'un joueur sans pouvoir suspendre son compte', async () => {
    mockApi({
      'GET /me': me(['risk_fraud'], RISK_FRAUD),
      'GET /admin/users/7': { status: 200, body: { data: account() } },
      'GET /admin/settings': { status: 200, body: { data: settings() } },
    });

    renderApp('/admin/users/7');

    expect(await screen.findByRole('heading', { name: 'Mise maximale par ticket' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Suspendre/ })).toBeNull();
    expect(screen.queryByRole('form', { name: 'Rôles administratifs' })).toBeNull();
  });

  it('ne modifie dans les paramètres que les plafonds de risque', async () => {
    let put: RequestInit | undefined;
    mockApi({
      'GET /me': me(['risk_fraud'], RISK_FRAUD),
      'GET /admin/settings': { status: 200, body: { data: settings() } },
      'GET /admin/settings/versions': { status: 200, body: page([]) },
      'PUT /admin/settings': (init) => {
        put = init;
        return { status: 200, body: { data: settings({ version: 3 }) } };
      },
    });

    renderApp('/admin/settings');

    const liability = await screen.findByLabelText('Perte maximale sur un match (HTG)');
    expect(screen.queryByLabelText('Mise maximale (HTG)')).toBeNull(); // hors du groupe Risque : en lecture
    expect(screen.getByText(/Autres paramètres en consultation seule/)).toBeInTheDocument();
    expect(screen.getByText('50 000,00 HTG')).toBeInTheDocument();

    await userEvent.clear(liability);
    await userEvent.type(liability, '2000000');
    await userEvent.type(screen.getByLabelText('Motif (obligatoire)'), 'Plafond des grands matchs');
    await userEvent.click(screen.getByRole('button', { name: 'Enregistrer la nouvelle version' }));

    expect(await screen.findByText(/Version 3 enregistrée/)).toBeInTheDocument();
    expect(JSON.parse(String(put?.body))).toEqual({
      values: { betting: { max_event_liability_minor: 2_000_000_00 } },
      base_version: 2,
      reason: 'Plafond des grands matchs',
    });
  });
});
