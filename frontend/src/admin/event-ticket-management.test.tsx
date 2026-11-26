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
      roles: ['operations'],
      permissions: ['dashboard.view', 'events.view', 'tickets.view', ...permissions],
      mfa: { enabled: true, verified: true, required: true },
    },
  },
});

const page = (data: unknown[]) => ({
  data,
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 20, total: data.length, last_page: 1, from: 1, to: data.length },
});

/** Champ motif d'un formulaire précis : la page en contient plusieurs (sections repliées). */
const reasonIn = (title: string) => within(screen.getByRole('form', { name: title })).getByLabelText('Motif (obligatoire)');

const headers = (init?: RequestInit) => (init?.headers ?? {}) as Record<string, string>;

const future = new Date(Date.now() + 3 * 24 * 3600_000).toISOString();
const past = new Date(Date.now() - 30 * 60_000).toISOString();

const event = (overrides: Record<string, unknown> = {}) => ({
  id: '12',
  sport: { slug: 'soccer', name: 'Football' },
  competition: { id: '3', name: 'Premier League', country: 'Angleterre' },
  home_team: 'Arsenal',
  away_team: 'Chelsea',
  starts_at: future,
  status: 'scheduled',
  score: null,
  origin: { provider: 'theodds', external_id: 'abc123' },
  last_synced_at: new Date().toISOString(),
  result_received_at: null,
  hidden: null,
  admin_locked_at: null,
  markets_count: { open: 3, suspended: 0, closed: 0, settled: 0 },
  markets: [],
  ...overrides,
});

const history = { status: 200, body: { data: { event_id: '12', results: [], corrections: [] } } };

describe('gestion d\'un match', () => {
  it('reporte un match avec un motif, envoyé avec une clé d\'idempotence', async () => {
    let patch: RequestInit | undefined;
    mockApi({
      'GET /me': me(['events.update']),
      'GET /admin/events/12': { status: 200, body: { data: event() } },
      'GET /admin/events/12/results': history,
      'PATCH /admin/events/12': (init) => {
        patch = init;
        return { status: 200, body: { data: event({ status: 'postponed' }), meta: { result_correction_id: null } } };
      },
    });

    renderApp('/admin/events/12');

    expect(await screen.findByRole('link', { name: 'Tickets sur ce match' })).toHaveAttribute('href', '/admin/tickets?event_id=12');
    await userEvent.click(screen.getByText('Reporter le match', { selector: 'summary' }));
    const submit = screen.getByRole('button', { name: 'Reporter' });
    expect(submit).toBeDisabled(); // motif obligatoire
    await userEvent.type(reasonIn('Reporter le match'), 'Terrain gelé');
    await userEvent.click(submit);

    expect(await screen.findByText('Statut du match mis à jour.')).toBeInTheDocument();
    expect(JSON.parse(String(patch?.body))).toEqual({ status: 'postponed', reason: 'Terrain gelé' });
    expect(headers(patch)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('annule un match commencé par une demande à faire valider', async () => {
    mockApi({
      'GET /me': me(['events.update']),
      'GET /admin/events/12': { status: 200, body: { data: event({ starts_at: past }) } },
      'GET /admin/events/12/results': history,
      'PATCH /admin/events/12': { status: 200, body: { data: event({ starts_at: past }), meta: { result_correction_id: '8' } } },
    });

    renderApp('/admin/events/12');

    await userEvent.click(await screen.findByText('Annuler le match', { selector: 'summary' }));
    expect(screen.getByText(/validée par un second administrateur/)).toBeInTheDocument();
    await userEvent.type(reasonIn('Annuler le match'), 'Match arrêté');
    await userEvent.click(screen.getByRole('button', { name: "Demander l'annulation" }));

    expect(await screen.findByRole('link', { name: 'Voir la demande' })).toHaveAttribute('href', '/admin/settlements/8');
  });

  it('suspend tous les marchés du match', async () => {
    let post: RequestInit | undefined;
    mockApi({
      'GET /me': me(['markets.suspend']),
      'GET /admin/events/12': { status: 200, body: { data: event() } },
      'GET /admin/events/12/results': history,
      'POST /admin/events/12/suspend': (init) => {
        post = init;
        return { status: 200, body: { data: event(), meta: { markets_changed: 3 } } };
      },
    });

    renderApp('/admin/events/12');

    await userEvent.click(await screen.findByText('Suspendre tous les marchés', { selector: 'summary' }));
    await userEvent.type(reasonIn('Suspendre tous les marchés du match'), 'Incident stade');
    await userEvent.click(screen.getByRole('button', { name: 'Suspendre tous les marchés' }));

    expect(await screen.findByText('Marchés du match suspendus.')).toBeInTheDocument();
    expect(JSON.parse(String(post?.body))).toEqual({ reason: 'Incident stade' });
    // events.update absent : ni heure, ni report, ni annulation, ni masquage.
    expect(screen.queryByText('Annuler le match')).toBeNull();
    expect(screen.queryByText('Masquer le match')).toBeNull();
  });

  it('signale un match masqué et propose de le réafficher', async () => {
    mockApi({
      'GET /me': me(['events.update']),
      'GET /admin/events/12': { status: 200, body: { data: event({ hidden: { at: past, reason: 'Doute sur l\'intégrité du match' } }) } },
      'GET /admin/events/12/results': history,
    });

    renderApp('/admin/events/12');

    expect(await screen.findByText(/Motif : Doute sur l'intégrité du match/)).toBeInTheDocument();
    expect(screen.getByText('Réafficher le match', { selector: 'summary' })).toBeInTheDocument();
  });
});

const htg = (minor: number) => ({ minor, currency: 'HTG' });

const ticket = (overrides: Record<string, unknown> = {}) => ({
  id: '41',
  reference: 'R16-7KQ2M9XAPB',
  type: 'single',
  status: 'won',
  user: { id: '7', name: 'Ana Joueuse' },
  wallet_id: '3',
  stake: htg(100_00),
  total_odds: '2.10',
  potential_payout: htg(210_00),
  payout: htg(210_00),
  rules_version: '2026-10-01',
  placed_at: '2026-10-20T15:00:00+00:00',
  settled_at: '2026-10-21T17:30:00+00:00',
  rules_snapshot: {},
  selections: [],
  voids: [],
  ...overrides,
});

const voidRequest = (overrides: Record<string, unknown> = {}) => ({
  id: '3',
  ticket: { id: '41', reference: 'R16-7KQ2M9XAPB', status: 'won', stake: htg(100_00) },
  status: 'pending',
  ticket_status: 'won',
  reason: 'Cote manifestement erronée',
  requested_by: { id: '5', name: 'Rose Opérations' },
  decided_by: null,
  decision_reason: null,
  decided_at: null,
  impact: null,
  created_at: '2026-10-21T18:00:00+00:00',
  ...overrides,
});

describe('annulation d\'un ticket', () => {
  it('demande l\'annulation d\'un ticket avec un motif', async () => {
    let post: RequestInit | undefined;
    mockApi({
      'GET /me': me(['tickets.void']),
      'GET /admin/tickets/41': { status: 200, body: { data: ticket() } },
      'POST /admin/tickets/41/void': (init) => {
        post = init;
        return { status: 201, body: { data: voidRequest() } };
      },
    });

    renderApp('/admin/tickets/41');

    await userEvent.click(await screen.findByText("Demander l'annulation du ticket", { selector: 'summary' }));
    expect(screen.getByText(/le gain versé est repris/)).toBeInTheDocument();
    await userEvent.type(reasonIn('Annuler le ticket'), 'Cote manifestement erronée');
    await userEvent.click(screen.getByRole('button', { name: 'Envoyer pour validation' }));

    expect(await screen.findByText(/attend la validation d'un second administrateur/)).toBeInTheDocument();
    expect(JSON.parse(String(post?.body))).toEqual({ reason: 'Cote manifestement erronée' });
    expect(headers(post)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('montre l\'effet prévu et laisse un autre administrateur valider', async () => {
    let approve: RequestInit | undefined;
    mockApi({
      'GET /me': me(['tickets.void_approve']),
      'GET /admin/tickets/41': { status: 200, body: { data: ticket({ voids: [voidRequest()] }) } },
      'GET /admin/ticket-voids/3': {
        status: 200,
        body: { data: voidRequest({ preview: { from: { status: 'won', payout_minor: 210_00 }, to: { status: 'void', payout_minor: 100_00 }, ledger_delta_minor: -110_00 } }) },
      },
      'POST /admin/ticket-voids/3/approve': (init) => {
        approve = init;
        return { status: 200, body: { data: voidRequest({ status: 'approved' }) } };
      },
    });

    renderApp('/admin/tickets/41');

    expect(await screen.findByText(/Effet de la validation/)).toBeInTheDocument();
    await userEvent.click(screen.getByText("Valider l'annulation", { selector: 'summary' }));
    await userEvent.type(reasonIn("Valider l'annulation"), 'Vérifié ok');
    await userEvent.click(screen.getByRole('button', { name: "Valider l'annulation" }));

    expect(await screen.findByText('Ticket annulé.')).toBeInTheDocument();
    expect(JSON.parse(String(approve?.body))).toEqual({ reason: 'Vérifié ok' });
  });

  it('ne laisse pas le demandeur valider sa propre demande, seulement la retirer', async () => {
    mockApi({
      'GET /me': me(['tickets.void', 'tickets.void_approve'], '5'),
      'GET /admin/tickets/41': { status: 200, body: { data: ticket({ voids: [voidRequest()] }) } },
      'GET /admin/ticket-voids/3': { status: 200, body: { data: voidRequest() } },
    });

    renderApp('/admin/tickets/41');

    expect(await screen.findByText('Retirer ma demande')).toBeInTheDocument();
    expect(screen.queryByText("Valider l'annulation")).toBeNull();
    expect(screen.queryByText("Demander l'annulation du ticket")).toBeNull();
  });

  it('filtre la liste des tickets par match', async () => {
    const fetchMock = mockApi({
      'GET /me': me([]),
      'GET /admin/tickets': { status: 200, body: page([ticket()]) },
    });

    renderApp('/admin/tickets?event_id=12');

    expect(await screen.findByRole('button', { name: /Match n° 12/ })).toBeInTheDocument();
    await waitFor(() => {
      const url = new URL(String(fetchMock.mock.calls.filter(([u]) => String(u).includes('/admin/tickets')).pop()?.[0]));
      expect(url.searchParams.get('event_id')).toBe('12');
    });
  });
});
