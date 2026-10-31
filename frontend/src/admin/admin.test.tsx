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
    expect(within(screen.getByRole('button', { name: /Menu du compte/ })).getByText('Support')).toBeInTheDocument();
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
  const series = (values: number[]) => values.map((value, i) => ({ date: `2026-10-${String(23 + i).padStart(2, '0')}`, value }));
  const superAdmin = admin({ roles: ['super_admin'], permissions: ['dashboard.view', 'tickets.view', 'users.view', 'ledger.view', 'events.view', 'settings.view'] });

  const summary = {
    generated_at: '2026-10-29T14:00:00+00:00',
    currency: 'HTG',
    period: { preset: '7d', from: '2026-10-23', to: '2026-10-29', days: 7, timezone: 'America/Port-au-Prince' },
    users: { total: 1250, active: 1200, suspended: 3, excluded: 1, new_today: 4, new_in_period: 42, registrations: series([1, 2, 3, 4, 5, 6, 7]) },
    betting: {
      tickets_placed: 30,
      stakes_minor: 1_500_00,
      tickets_settled: 13,
      payouts_minor: 3_000_00,
      refunds_minor: 0,
      pending_count: 7,
      pending_exposure_minor: 9_800_00,
      status_breakdown: [{ status: 'pending', count: 7 }, { status: 'won', count: 3 }, { status: 'lost', count: 10 }, { status: 'void', count: 0 }],
      stakes_by_sport: [{ sport: 'soccer', name: 'Football', tickets: 25, stakes_minor: 1_200_00 }],
      tickets: series(Array(7).fill(2)),
      stakes_daily_minor: series(Array(7).fill(100_00)),
    },
    finance: { settled_stakes_minor: 5_000_00, payouts_minor: 3_000_00, gross_gaming_revenue_minor: 2_000_00, pending_corrections: 2, payments_available: false },
    catalogue: { events_live: 1, events_scheduled: 40, events_next_24h: 8, markets_open: 40, markets_suspended: 0 },
  };

  const alerts = [
    { key: 'provider_credits', severity: 'critical', category: 'Données sportives', title: 'Crédits du fournisseur épuisés', detail: 'Plus de synchronisation.', link: '/admin/system' },
    { key: 'finance_corrections', severity: 'warning', category: 'Finance', title: '2 correction(s) financière(s) à valider', detail: 'Second validateur requis.', link: '/admin/finance' },
  ];

  it('affiche les indicateurs de la période, leurs définitions et le centre d\'alertes', async () => {
    mockApi({
      'GET /me': { status: 200, body: { data: superAdmin } },
      'GET /admin/dashboard': { status: 200, body: { data: summary } },
      'GET /admin/alerts': { status: 200, body: { data: alerts } },
    });

    renderApp('/admin');

    const stakes = (await screen.findByText('Mises', { selector: '.kpi-label' })).closest('a')!;
    expect(stakes).toHaveAttribute('href', '/admin/tickets');
    expect(within(stakes).getByText(/1\s500,00/)).toBeInTheDocument();
    expect(within(stakes).getByText(/Définition : Montant des tickets placés/)).toBeInTheDocument();
    expect(screen.getByText('1 250')).toBeInTheDocument();
    expect(screen.getByText('Non disponibles')).toBeInTheDocument();
    expect(await screen.findByText('Crédits du fournisseur épuisés')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Mises par sport' })).toBeInTheDocument();
    // Une alerte critique sur un service : l'indicateur de la barre du haut le signale.
    expect(screen.getByRole('link', { name: /Incident en cours/ })).toHaveAttribute('href', '/admin/system');
  });

  it('transmet la période choisie à l\'API', async () => {
    const fetchMock = mockApi({
      'GET /me': { status: 200, body: { data: superAdmin } },
      'GET /admin/dashboard': { status: 200, body: { data: summary } },
      'GET /admin/alerts': { status: 200, body: { data: [] } },
    });

    renderApp('/admin');
    await userEvent.click(await screen.findByRole('radio', { name: '30 jours' }));

    await waitFor(() => {
      const url = new URL(String(fetchMock.mock.calls.filter(([u]) => String(u).includes('/admin/dashboard')).pop()?.[0]));
      expect(url.searchParams.get('period')).toBe('30d');
    });
    expect(await screen.findByText('Aucune alerte : tout est en ordre.')).toBeInTheDocument();
  });
});

describe('supervision et risques', () => {
  it('affiche l\'état des services sans présenter les sauvegardes comme réussies', async () => {
    mockApi({
      'GET /me': { status: 200, body: { data: admin({ roles: ['super_admin'], permissions: ['dashboard.view', 'settings.view'] }) } },
      'GET /admin/alerts': { status: 200, body: { data: [] } },
      'GET /admin/system': {
        status: 200,
        body: {
          data: {
            generated_at: '2026-10-29T14:00:00+00:00',
            scheduler: { running: false, last_heartbeat: null },
            sports_provider: {
              provider: 'theodds',
              budget_enabled: true,
              credits: { remaining: 24, used: 476, monthly: 500, reserve: 25, source: 'fournisseur', next_reset: '2026-11-01' },
              sync_cron: '0 */6 * * *',
              last_call_at: '2026-10-26T21:00:00+00:00',
              last_event_sync_at: '2026-10-26T21:00:04+00:00',
              competitions: [{ key: 'soccer_epl', name: 'Premier League', last_synced_at: '2026-10-26T21:00:00+00:00' }],
              recent_failures: [],
            },
            queue: { connection: 'redis', failed_24h: 0, recent_failures: [] },
            payments: { integrated: false, providers: [] },
            backups: { tracked: false },
          },
        },
      },
    });

    renderApp('/admin/system');

    expect(await screen.findByRole('heading', { name: 'Supervision technique' })).toBeInTheDocument();
    expect(await screen.findByText('Arrêté')).toBeInTheDocument();
    expect(screen.getByText('En panne')).toBeInTheDocument();
    expect(screen.getByText('Non suivies ici')).toBeInTheDocument();
    expect(screen.getByText('Premier League')).toBeInTheDocument();
  });

  it('montre le pire cas d\'un match et le détail par issue', async () => {
    mockApi({
      'GET /me': { status: 200, body: { data: admin() } },
      'GET /admin/alerts': { status: 200, body: { data: [] } },
      'GET /admin/risk/exposure': {
        status: 200,
        body: {
          data: {
            currency: 'HTG',
            total_events: 1,
            events: [{
              event_id: '5',
              name: 'Everton – Chelsea',
              starts_at: '2026-10-30T19:00:00+00:00',
              single_tickets: 2,
              single_stakes_minor: 150_00,
              worst_case_minor: 55_00,
              markets: [{
                market_key: 'h2h',
                line: null,
                stakes_minor: 150_00,
                worst_case_minor: 55_00,
                selections: [
                  { selection_id: '1', name: 'Everton', tickets: 1, stakes_minor: 100_00, potential_payout_minor: 205_00 },
                  { selection_id: '2', name: 'Chelsea', tickets: 1, stakes_minor: 50_00, potential_payout_minor: 170_00 },
                ],
              }],
              multiple_tickets: 1,
              multiple_potential_payout_minor: 900_00,
            }],
          },
        },
      },
    });

    renderApp('/admin/risk');

    const row = await screen.findByRole('button', { name: /Everton – Chelsea/ });
    expect(within(row).getByText(/-55,00/)).toBeInTheDocument();
    await userEvent.click(row);
    expect(screen.getByRole('table', { name: /Résultat du match/ })).toBeInTheDocument();
    expect(screen.getByText(/non comptés dans le pire cas/)).toBeInTheDocument();
  });
});
