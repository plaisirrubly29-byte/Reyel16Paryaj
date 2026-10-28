import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { guest, mockApi, player, renderApp } from '../test/renderApp';
import { visibleSections } from './navigation';

afterEach(() => vi.unstubAllGlobals());

type AdminOverrides = {
  roles?: string[];
  permissions?: string[];
  mfa?: Partial<typeof player.mfa>;
};

const admin = ({ mfa, ...overrides }: AdminOverrides = {}) => ({
  ...player,
  name: 'Sam Support',
  roles: ['support'],
  is_admin: true,
  permissions: ['dashboard.view', 'events.view', 'users.view', 'users.suspend', 'tickets.view'],
  ...overrides,
  mfa: { enabled: true, verified: true, required: true, ...mfa },
});

const emptyPage = { data: [], links: { first: null, last: null, prev: null, next: null }, meta: { current_page: 1, per_page: 5, total: 0, last_page: 1, from: null, to: null } };

describe('accès à l\'administration', () => {
  it('envoie un visiteur vers la connexion', async () => {
    mockApi({ 'GET /me': guest });

    const router = renderApp('/admin/users');

    await waitFor(() => expect(router.state.location.pathname).toBe('/login'));
    expect(router.state.location.search).toBe('?redirect=%2Fadmin%2Fusers');
  });

  it('refuse un joueur', async () => {
    mockApi({ 'GET /me': { status: 200, body: { data: player } } });

    renderApp('/admin');

    expect(await screen.findByText('Accès refusé')).toBeInTheDocument();
    expect(screen.queryByRole('navigation', { name: 'Administration' })).toBeNull();
  });

  it("impose l'activation du MFA, affiche le QR code puis les codes de secours", async () => {
    let enabled = false;
    mockApi({
      'GET /me': () => ({ status: 200, body: { data: admin({ mfa: { enabled, verified: enabled } }) } }),
      'POST /auth/mfa/setup': { status: 200, body: { data: { secret: 'JBSWY3DPEHPK3PXP', otpauth_uri: 'otpauth://totp/R16P:sam?secret=JBSWY3DPEHPK3PXP' } } },
      'POST /auth/mfa/confirm': () => { enabled = true; return { status: 200, body: { data: { recovery_codes: ['aaaaa-bbbbb', 'ccccc-ddddd'] } } }; },
      'GET /admin/audit-logs': { status: 403, body: { error: { code: 'forbidden', message: 'Action non autorisée.' } } },
    });
    renderApp('/admin');

    await userEvent.click(await screen.findByRole('button', { name: 'Commencer' }));
    expect(await screen.findByText('JBSWY3DPEHPK3PXP')).toBeInTheDocument();
    expect(await screen.findByRole('img', { name: /QR code/ })).toBeInTheDocument();

    await userEvent.type(screen.getByLabelText('Code de vérification'), '123 456');
    await userEvent.click(screen.getByRole('button', { name: 'Activer' }));

    const codes = await screen.findByRole('list', { name: 'Codes de secours' });
    expect(within(codes).getByText('aaaaa-bbbbb')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: "J'ai conservé mes codes" }));
    expect(await screen.findByRole('heading', { name: "Vue d'ensemble" })).toBeInTheDocument();
  });

  it('demande le code MFA à chaque connexion et affiche une erreur si le code est faux', async () => {
    let verified = false;
    const fetchMock = mockApi({
      'GET /me': () => ({ status: 200, body: { data: admin({ mfa: { verified } }) } }),
      'POST /auth/mfa/verify': (init) => {
        const body = JSON.parse(String(init?.body));
        if (body.code !== '654321') return { status: 422, body: { error: { code: 'invalid_mfa_code', message: 'Code de vérification invalide.' } } };
        verified = true;
        return { status: 204 };
      },
    });
    renderApp('/admin');

    await userEvent.type(await screen.findByLabelText('Code de vérification'), '000000');
    await userEvent.click(screen.getByRole('button', { name: 'Vérifier' }));
    expect(await screen.findByText('Code de vérification invalide.')).toBeInTheDocument();

    await userEvent.clear(screen.getByLabelText('Code de vérification'));
    await userEvent.type(screen.getByLabelText('Code de vérification'), '654 321');
    await userEvent.click(screen.getByRole('button', { name: 'Vérifier' }));

    expect(await screen.findByRole('heading', { name: "Vue d'ensemble" })).toBeInTheDocument();
    const verifyCall = fetchMock.mock.calls.filter(([u]) => String(u).endsWith('/auth/mfa/verify')).pop();
    expect(JSON.parse(String(verifyCall?.[1]?.body))).toEqual({ code: '654321' });
  });

  it('permet de basculer sur un code de secours', async () => {
    const fetchMock = mockApi({
      'GET /me': { status: 200, body: { data: admin({ mfa: { verified: false } }) } },
      'POST /auth/mfa/verify': { status: 422, body: { error: { code: 'invalid_mfa_code', message: 'Code de vérification invalide.' } } },
    });
    renderApp('/admin');

    await userEvent.click(await screen.findByRole('button', { name: 'Utiliser un code de secours' }));
    await userEvent.type(screen.getByLabelText('Code de secours'), 'aaaaa-bbbbb');
    await userEvent.click(screen.getByRole('button', { name: 'Vérifier' }));

    await screen.findByText('Code de vérification invalide.');
    const call = fetchMock.mock.calls.filter(([u]) => String(u).endsWith('/auth/mfa/verify')).pop();
    expect(JSON.parse(String(call?.[1]?.body))).toEqual({ recovery_code: 'aaaaa-bbbbb' });
  });
});

describe('menu selon les permissions', () => {
  it("n'affiche que les sections autorisées par l'API", async () => {
    mockApi({ 'GET /me': { status: 200, body: { data: admin() } } });

    renderApp('/admin');

    const nav = await screen.findByRole('navigation', { name: 'Administration' });
    expect(within(nav).getByRole('link', { name: 'Utilisateurs' })).toBeInTheDocument();
    expect(within(nav).getByRole('link', { name: 'Tickets' })).toBeInTheDocument();
    expect(within(nav).queryByRole('link', { name: 'Finance' })).toBeNull();
    expect(within(nav).queryByRole('link', { name: 'Audit & rapports' })).toBeNull();
    expect(within(nav).getByText(/Support/)).toBeInTheDocument();
  });

  it('bloque une section saisie directement dans l\'URL sans la permission', async () => {
    mockApi({ 'GET /me': { status: 200, body: { data: admin() } } });

    renderApp('/admin/finance');

    expect(await screen.findByText('Votre rôle ne donne pas accès à cette section.')).toBeInTheDocument();
  });

  it("affiche les actions récentes du journal d'audit à qui peut le lire", async () => {
    mockApi({
      'GET /me': { status: 200, body: { data: admin({ roles: ['compliance'], permissions: ['dashboard.view', 'audit.view'] }) } },
      'GET /admin/audit-logs': {
        status: 200,
        body: { ...emptyPage, data: [{ id: '1', action: 'user.suspended', actor: { id: '2', email: 'c@example.com' }, subject: null, reason: 'Fraude suspectée', created_at: '2026-10-15T18:00:00+00:00' }] },
      },
    });

    renderApp('/admin');

    expect(await screen.findByText('Fraude suspectée')).toBeInTheDocument();
    expect(screen.getByText('c@example.com')).toBeInTheDocument();
  });

  it('calcule les sections visibles (une permission suffit)', () => {
    expect(visibleSections(['settlement.run']).map((s) => s.label)).toEqual(['Résultats & règlement']);
    expect(visibleSections([])).toEqual([]);
  });
});
