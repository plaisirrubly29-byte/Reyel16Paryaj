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
  it('envoie un visiteur vers la connexion administrateur', async () => {
    mockApi({ 'GET /me': guest });

    const router = renderApp('/admin/users');

    await waitFor(() => expect(router.state.location.pathname).toBe('/admin/login'));
    expect(router.state.location.search).toBe('?redirect=%2Fadmin%2Fusers');
    expect(await screen.findByRole('heading', { name: 'Connexion administrateur' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Créer un compte' })).not.toBeInTheDocument();
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
    expect(within(screen.getByLabelText('Compte connecté')).getByText('Support')).toBeInTheDocument();
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

describe('tableau de bord', () => {
  const series = (values: number[]) => values.map((value, i) => ({ date: `2026-10-${String(16 + i).padStart(2, '0')}`, value }));

  it('affiche les indicateurs reçus de l\'API et les points à traiter', async () => {
    mockApi({
      'GET /me': { status: 200, body: { data: admin({ roles: ['super_admin'], permissions: ['dashboard.view', 'tickets.view', 'users.view', 'ledger.view', 'events.view'] }) } },
      'GET /admin/dashboard': {
        status: 200,
        body: {
          data: {
            generated_at: '2026-10-29T14:00:00+00:00',
            currency: 'HTG',
            users: { total: 1250, active: 1200, suspended: 3, excluded: 1, new_7d: 42, registrations: series([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14]) },
            betting: {
              tickets_today: 12,
              stake_today_minor: 1_500_00,
              pending_count: 7,
              pending_exposure_minor: 9_800_00,
              status_breakdown: [{ status: 'pending', count: 7 }, { status: 'won', count: 3 }, { status: 'lost', count: 10 }, { status: 'void', count: 0 }],
              tickets: series(Array(14).fill(2)),
              stakes_minor: series(Array(14).fill(100_00)),
            },
            finance: { period_days: 14, settled_stakes_minor: 5_000_00, payouts_minor: 3_000_00, gross_gaming_revenue_minor: 2_000_00, pending_corrections: 2 },
            catalogue: { events_live: 1, events_next_24h: 8, markets_open: 40, markets_suspended: 0 },
          },
        },
      },
    });

    renderApp('/admin');

    const stakes = (await screen.findByText('Mises du jour')).closest('a')!;
    expect(stakes).toHaveAttribute('href', '/admin/tickets');
    expect(within(stakes).getByText(/1\s500,00/)).toBeInTheDocument();
    expect(screen.getByText('1 250')).toBeInTheDocument();
    expect(screen.getByText(/2 corrections financières en attente/)).toBeInTheDocument();
    expect(screen.queryByText(/marché.* suspendu/)).toBeNull();
    expect(screen.getByRole('heading', { name: 'Mises par jour' })).toBeInTheDocument();
  });
});
