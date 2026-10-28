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
      roles: ['support'],
      permissions: ['dashboard.view', ...permissions],
      mfa: { enabled: true, verified: true, required: true },
    },
  },
});

const account = (overrides: Record<string, unknown> = {}) => ({
  id: '7',
  name: 'Ana Joueuse',
  email: 'ana@example.com',
  status: 'active',
  status_changed_at: null,
  is_admin: false,
  created_at: '2026-10-01T12:00:00+00:00',
  last_login_at: '2026-10-14T09:30:00+00:00',
  ...overrides,
});

const page = (data: unknown[], meta: Record<string, number> = {}) => ({
  data,
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 20, total: data.length, last_page: 1, from: 1, to: data.length, ...meta },
});

describe('liste des utilisateurs', () => {
  it('recherche et filtre via les paramètres de requête de l\'API', async () => {
    const fetchMock = mockApi({
      'GET /me': me(['users.view']),
      'GET /admin/users': { status: 200, body: page([account()]) },
    });
    const router = renderApp('/admin/users');

    expect(await screen.findByRole('link', { name: 'Ana Joueuse' })).toHaveAttribute('href', '/admin/users/7');

    await userEvent.type(screen.getByLabelText('Rechercher'), 'ana@');
    await userEvent.click(screen.getByRole('button', { name: 'Rechercher' }));
    await userEvent.selectOptions(screen.getByLabelText('Statut'), 'suspended');

    await waitFor(() => expect(router.state.location.search).toBe('?search=ana%40&status=suspended'));
    const urls = fetchMock.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('/admin/users'));
    expect(urls[urls.length - 1]).toContain('search=ana%40');
    expect(urls[urls.length - 1]).toContain('status=suspended');
  });

  it('affiche un état vide', async () => {
    mockApi({ 'GET /me': me(['users.view']), 'GET /admin/users': { status: 200, body: page([]) } });

    renderApp('/admin/users?search=personne');

    expect(await screen.findByText('Aucun compte ne correspond à ces critères.')).toBeInTheDocument();
    expect(screen.getByLabelText('Rechercher')).toHaveValue('personne');
  });
});

describe('fiche utilisateur', () => {
  it("n'affiche que les données minimales envoyées par l'API (Support)", async () => {
    mockApi({ 'GET /me': me(['users.view']), 'GET /admin/users/7': { status: 200, body: { data: account() } } });

    renderApp('/admin/users/7');

    expect(await screen.findByRole('heading', { name: 'Ana Joueuse' })).toBeInTheDocument();
    expect(screen.queryByText('Date de naissance')).toBeNull();
    // Pas de permission users.suspend : pas de formulaire.
    expect(screen.queryByLabelText('Motif (obligatoire)')).toBeNull();
  });

  it('affiche les données personnelles quand l\'API les envoie (Conformité)', async () => {
    mockApi({
      'GET /me': me(['users.view', 'users.view_personal_data']),
      'GET /admin/users/7': {
        status: 200,
        body: { data: account({ roles: [], mfa_enabled: false, profile: { first_name: 'Ana', last_name: 'Pierre', birth_date: '1990-04-02', phone: '+50900000000', country: 'HT' } }) },
      },
    });

    renderApp('/admin/users/7');

    expect(await screen.findByText('+50900000000')).toBeInTheDocument();
    expect(screen.getByText('Ana Pierre')).toBeInTheDocument();
  });

  it('suspend un compte avec un motif et une clé d\'idempotence', async () => {
    let status = 'active';
    const fetchMock = mockApi({
      'GET /me': me(['users.view', 'users.suspend']),
      'GET /admin/users/7': () => ({ status: 200, body: { data: account({ status }) } }),
      'GET /admin/users': { status: 200, body: page([]) },
      'PATCH /admin/users/7': (init) => {
        status = JSON.parse(String(init?.body)).status;
        return { status: 200, body: { data: account({ status }) } };
      },
    });
    renderApp('/admin/users/7');

    const button = await screen.findByRole('button', { name: 'Suspendre' });
    expect(button).toBeDisabled(); // motif obligatoire

    await userEvent.type(screen.getByLabelText('Motif (obligatoire)'), 'Fraude suspectée');
    await userEvent.click(screen.getByRole('button', { name: 'Suspendre' }));

    expect(await screen.findByText('Statut mis à jour.')).toBeInTheDocument();
    expect(screen.getAllByText('Suspendu').length).toBeGreaterThan(0);
    expect(await screen.findByRole('button', { name: 'Réactiver' })).toBeInTheDocument();

    const call = fetchMock.mock.calls.find(([u, init]) => String(u).endsWith('/admin/users/7') && init?.method === 'PATCH');
    expect(JSON.parse(String(call?.[1]?.body))).toEqual({ status: 'suspended', reason: 'Fraude suspectée' });
    expect((call?.[1]?.headers as Record<string, string>)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it("affiche le refus de l'API (compte administrateur)", async () => {
    mockApi({
      'GET /me': me(['users.view', 'users.suspend']),
      'GET /admin/users/7': { status: 200, body: { data: account({ is_admin: true }) } },
      'PATCH /admin/users/7': { status: 403, body: { error: { code: 'forbidden', message: 'Action non autorisée.' } } },
    });
    renderApp('/admin/users/7');

    await userEvent.type(await screen.findByLabelText('Motif (obligatoire)'), 'Comportement suspect');
    await userEvent.click(screen.getByRole('button', { name: 'Suspendre' }));

    const alert = await screen.findByRole('alert');
    expect(within(alert).getByText('Action non autorisée.')).toBeInTheDocument();
  });

  it("n'offre aucune action sur son propre compte", async () => {
    mockApi({
      'GET /me': me(['users.view', 'users.suspend'], '7'),
      'GET /admin/users/7': { status: 200, body: { data: account() } },
    });

    renderApp('/admin/users/7');
    expect(await screen.findByText('Vous ne pouvez pas modifier le statut de votre propre compte.')).toBeInTheDocument();
  });

  it("explique qu'une auto-exclusion ne se lève pas depuis l'administration", async () => {
    mockApi({
      'GET /me': me(['users.view', 'users.suspend']),
      'GET /admin/users/8': { status: 200, body: { data: account({ id: '8', status: 'excluded' }) } },
    });

    renderApp('/admin/users/8');

    expect(await screen.findByText(/ne peut pas être levée/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Réactiver|Suspendre/ })).toBeNull();
  });

  it('refuse la fiche sans la permission users.view', async () => {
    mockApi({ 'GET /me': me(['tickets.view']) });

    renderApp('/admin/users/7');

    expect(await screen.findByText('Votre rôle ne donne pas accès à cette section.')).toBeInTheDocument();
  });
});
