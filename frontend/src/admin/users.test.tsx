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
  phone: '34123456',
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
        body: { data: account({ roles: [], mfa_enabled: false, profile: { first_name: 'Ana', last_name: 'Pierre', birth_date: '1990-04-02', country: 'HT' } }) },
      },
    });

    renderApp('/admin/users/7');

    expect(await screen.findByText('Ana Pierre')).toBeInTheDocument();
    expect(screen.getByText('02/04/1990')).toBeInTheDocument();
    // Le téléphone est l'identifiant du compte (users.phone), plus une donnée du profil.
    expect(screen.getByText('34123456')).toBeInTheDocument();
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

  it('crédite le solde d\'un joueur (Super Admin) avec un motif', async () => {
    let balance = 0;
    const fetchMock = mockApi({
      'GET /me': me(['users.view', 'ledger.view', 'wallet.adjust']),
      'GET /admin/users/7': { status: 200, body: { data: account() } },
      'GET /admin/users/7/wallet': () => ({ status: 200, body: { data: { user_id: '7', currency: 'HTG', balance: { minor: balance, currency: 'HTG' }, status: 'active' } } }),
      'POST /admin/users/7/wallet/adjustments': (init) => {
        balance += JSON.parse(String(init?.body)).amount_minor;
        return { status: 201, body: { data: { id: '1', type: 'deposit', amount: { minor: balance, currency: 'HTG' }, balance_after: { minor: balance, currency: 'HTG' } } } };
      },
    });
    renderApp('/admin/users/7');

    const button = await screen.findByRole('button', { name: 'Créditer le solde' });
    expect(button).toBeDisabled();

    await userEvent.type(screen.getByLabelText('Montant'), '2500,50');
    await userEvent.type(screen.getByLabelText('Motif (obligatoire)'), 'Dépôt MonCash reçu');
    await userEvent.click(screen.getByRole('button', { name: 'Créditer le solde' }));

    expect(await screen.findByText(/Dépôt enregistré/)).toBeInTheDocument();
    const call = fetchMock.mock.calls.find(([u, init]) => String(u).endsWith('/admin/users/7/wallet/adjustments') && init?.method === 'POST');
    expect(JSON.parse(String(call?.[1]?.body))).toEqual({ type: 'deposit', amount_minor: 250050, reason: 'Dépôt MonCash reçu' });
    expect((call?.[1]?.headers as Record<string, string>)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('masque le formulaire de crédit sans la permission wallet.adjust', async () => {
    mockApi({
      'GET /me': me(['users.view', 'ledger.view']),
      'GET /admin/users/7': { status: 200, body: { data: account() } },
      'GET /admin/users/7/wallet': { status: 200, body: { data: { user_id: '7', currency: 'HTG', balance: { minor: 1_000_00, currency: 'HTG' }, status: 'active' } } },
    });
    renderApp('/admin/users/7');

    expect(await screen.findByRole('heading', { name: 'Solde' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Créditer le solde' })).toBeNull();
  });
});

describe('mise maximale propre à un joueur (V2-D05)', () => {
  const NBSP = new RegExp(`[${String.fromCharCode(0xa0, 0x202f)}]`, 'g');
  const text = (el: HTMLElement) => (el.textContent ?? '').replace(NBSP, ' ');
  const panel = async () => (await screen.findByRole('heading', { name: 'Mise maximale par ticket' })).closest('form') as HTMLElement;

  const setting = (key: string, minor: number) => ({ key, group: 'Paris', label: key, type: 'money', help: null, options: null, currency: 'HTG', value: minor, default: minor });
  const settings = {
    status: 200,
    body: { data: { version: 1, updated_at: null, updated_by: null, items: [setting('betting.min_stake_minor', 10_00), setting('betting.max_stake_minor', 50_000_00)] } },
  };

  it('fixe une limite en centimes, avec motif et clé d\'idempotence, puis affiche la nouvelle limite', async () => {
    let put: RequestInit | undefined;
    mockApi({
      'GET /me': me(['users.view', 'users.suspend']),
      'GET /admin/users/7': { status: 200, body: { data: account({ max_stake_minor: null }) } },
      'PUT /admin/users/7/stake-limit': (init) => {
        put = init;
        return { status: 200, body: { data: account({ max_stake_minor: 500_00 }) } };
      },
    });
    renderApp('/admin/users/7');

    const form = await panel();
    expect(text(form)).toContain('Limite générale. Aucune limite propre à ce joueur.');
    const submit = within(form).getByRole('button', { name: 'Fixer la limite' });
    expect(within(form).queryByRole('button', { name: 'Retirer la limite' })).toBeNull();

    // Montant puis motif : rien ne part sans les deux.
    await userEvent.type(within(form).getByLabelText('Limite pour ce joueur'), '500');
    expect(submit).toBeDisabled();
    await userEvent.type(within(form).getByLabelText('Motif de la limite (obligatoire)'), 'Mises inhabituelles sur des matchs mineurs');
    await userEvent.click(submit);

    expect(await within(form).findByText('Limite appliquée.')).toBeInTheDocument();
    expect(JSON.parse(String(put?.body))).toEqual({ max_stake_minor: 500_00, reason: 'Mises inhabituelles sur des matchs mineurs' });
    expect((put?.headers as Record<string, string>)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
    expect(text(form)).toContain('Limite propre à ce joueur : 500,00 HTG');
    expect(within(form).getByRole('button', { name: 'Modifier la limite' })).toBeInTheDocument();
  });

  it('retire la limite : null est envoyé et la limite générale s\'applique de nouveau', async () => {
    let put: RequestInit | undefined;
    mockApi({
      'GET /me': me(['users.view', 'users.suspend']),
      'GET /admin/users/7': { status: 200, body: { data: account({ max_stake_minor: 500_00 }) } },
      'PUT /admin/users/7/stake-limit': (init) => {
        put = init;
        return { status: 200, body: { data: account({ max_stake_minor: null }) } };
      },
    });
    renderApp('/admin/users/7');

    const form = await panel();
    expect(text(form)).toContain('Limite propre à ce joueur : 500,00 HTG');
    const remove = within(form).getByRole('button', { name: 'Retirer la limite' });
    expect(remove).toBeDisabled(); // motif obligatoire
    await userEvent.type(within(form).getByLabelText('Motif de la limite (obligatoire)'), 'Vérification terminée, rien à signaler');
    await userEvent.click(remove);

    expect(await within(form).findByText("Limite retirée : la limite générale s'applique de nouveau.")).toBeInTheDocument();
    expect(JSON.parse(String(put?.body))).toEqual({ max_stake_minor: null, reason: 'Vérification terminée, rien à signaler' });
    expect(text(form)).toContain('Aucune limite propre à ce joueur.');
  });

  it('rappelle les bornes quand les paramètres sont lisibles, et n\'envoie pas un montant hors bornes', async () => {
    const fetchMock = mockApi({
      'GET /me': me(['users.view', 'users.suspend', 'settings.view']),
      'GET /admin/users/7': { status: 200, body: { data: account({ max_stake_minor: null }) } },
      'GET /admin/settings': settings,
    });
    renderApp('/admin/users/7');

    const form = await panel();
    await waitFor(() => expect(text(form)).toContain('Montant entre 10,00 HTG et 50 000,00 HTG.'));
    expect(text(form)).toContain('Limite générale : 50 000,00 HTG.');

    await userEvent.type(within(form).getByLabelText('Limite pour ce joueur'), '90000');
    await userEvent.type(within(form).getByLabelText('Motif de la limite (obligatoire)'), 'Essai au-delà de la limite générale');
    await userEvent.click(within(form).getByRole('button', { name: 'Fixer la limite' }));

    expect(text(await within(form).findByRole('alert'))).toBe('La limite doit être comprise entre 10,00 HTG et 50 000,00 HTG.');
    expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'PUT')).toBe(false);
    // La saisie reste à l'écran ; l'erreur s'efface dès qu'on la corrige.
    expect(within(form).getByLabelText('Limite pour ce joueur')).toHaveValue('90000');
    await userEvent.clear(within(form).getByLabelText('Limite pour ce joueur'));
    expect(within(form).queryByRole('alert')).toBeNull();
  });

  it('traduit le refus du serveur sur le montant quand les bornes ne sont pas lisibles', async () => {
    mockApi({
      'GET /me': me(['users.view', 'users.suspend']),
      'GET /admin/users/7': { status: 200, body: { data: account({ max_stake_minor: null }) } },
      'PUT /admin/users/7/stake-limit': {
        status: 422,
        // Message de validation tel que le serveur l'envoie : technique, en unité mineure.
        body: { error: { code: 'validation_failed', message: 'Données invalides.', details: { max_stake_minor: ['The max stake minor field must not be greater than 5000000.'] } } },
      },
    });
    renderApp('/admin/users/7');

    const form = await panel();
    await userEvent.type(within(form).getByLabelText('Limite pour ce joueur'), '90000');
    await userEvent.type(within(form).getByLabelText('Motif de la limite (obligatoire)'), 'Essai au-delà de la limite générale');
    await userEvent.click(within(form).getByRole('button', { name: 'Fixer la limite' }));

    expect(await within(form).findByText('La limite doit rester entre la mise minimale et la mise maximale générales.')).toBeInTheDocument();
    expect(within(form).queryByText(/must not be greater/)).toBeNull();
    expect(within(form).queryByText('Limite appliquée.')).toBeNull();
  });

  it('ne lit pas les paramètres sans la permission settings.view', async () => {
    const fetchMock = mockApi({
      'GET /me': me(['users.view', 'users.suspend']),
      'GET /admin/users/7': { status: 200, body: { data: account({ max_stake_minor: null }) } },
    });
    renderApp('/admin/users/7');

    await panel();
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('/admin/settings'))).toBe(false);
  });

  it('ne propose pas la limite sans la permission users.suspend', async () => {
    mockApi({
      'GET /me': me(['users.view']),
      'GET /admin/users/7': { status: 200, body: { data: account({ max_stake_minor: null }) } },
    });
    renderApp('/admin/users/7');

    expect(await screen.findByRole('heading', { name: 'Informations du compte' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Mise maximale par ticket' })).toBeNull();
  });

  it('ne propose pas la limite sur un compte administrateur', async () => {
    mockApi({
      'GET /me': me(['users.view', 'users.suspend']),
      'GET /admin/users/8': { status: 200, body: { data: account({ id: '8', name: 'Sam Support', is_admin: true, max_stake_minor: null }) } },
    });
    renderApp('/admin/users/8');

    expect(await screen.findByRole('heading', { name: 'Suspendre le compte' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Mise maximale par ticket' })).toBeNull();
  });
});
