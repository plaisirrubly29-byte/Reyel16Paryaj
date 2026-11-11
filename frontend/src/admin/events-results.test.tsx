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
      permissions: ['dashboard.view', 'events.view', ...permissions],
      mfa: { enabled: true, verified: true, required: true },
    },
  },
});

const page = (data: unknown[]) => ({
  data,
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 20, total: data.length, last_page: 1, from: 1, to: data.length },
});

const market = (overrides: Record<string, unknown> = {}) => ({
  id: '5',
  event_id: '12',
  key: 'h2h',
  name: 'Résultat du match (1X2)',
  line: null,
  status: 'open',
  suspension: null,
  odds_version: 3,
  origin: { provider: 'theodds', external_id: 'abc123', bookmaker: 'pinnacle' },
  freshness: { odds_updated_at: '2026-10-29T09:50:00+00:00', age_seconds: 600, max_age_minutes: 300, stale: false },
  bettable: true,
  selections: [
    { id: '51', key: 'home', label: '1', name: 'Arsenal', odds: '2.10' },
    { id: '52', key: 'draw', label: 'X', name: 'Match nul', odds: '3.25' },
  ],
  updated_at: '2026-10-29T09:50:00+00:00',
  ...overrides,
});

const event = (overrides: Record<string, unknown> = {}) => ({
  id: '12',
  sport: { slug: 'soccer', name: 'Football' },
  competition: { id: '3', name: 'Premier League', country: 'Angleterre' },
  home_team: 'Arsenal',
  away_team: 'Chelsea',
  starts_at: '2026-10-29T15:00:00+00:00',
  status: 'finished',
  score: { home: '2', away: '1' },
  origin: { provider: 'theodds', external_id: 'abc123' },
  last_synced_at: '2026-10-29T09:55:00+00:00',
  result_received_at: '2026-10-29T17:10:00+00:00',
  markets_count: { open: 3, suspended: 1, closed: 0, settled: 0 },
  ...overrides,
});

const correction = (overrides: Record<string, unknown> = {}) => ({
  id: '8',
  event: { id: '12', name: 'Arsenal – Chelsea' },
  status: 'pending',
  current: { version: 1, status: 'final', score: '2-1' },
  proposed: { status: 'final', score: '1-2' },
  source: 'manual',
  reason: 'Score officiel publié par la ligue',
  requested_by: { id: '7', name: 'Rose Opérations' },
  decided_by: null,
  decision_reason: null,
  decided_at: null,
  applied_result_id: null,
  impact: null,
  preview: {
    tickets_count: 1,
    ledger_delta_minor: -210_00,
    tickets: [{ ticket_id: '41', reference: 'R16-7KQ2M9XAPB', user_id: '3', from: { status: 'won', payout_minor: 210_00 }, to: { status: 'lost', payout_minor: 0 }, ledger_delta_minor: -210_00 }],
  },
  created_at: '2026-10-29T18:00:00+00:00',
  ...overrides,
});

const history = (corrections: unknown[] = []) => ({
  status: 200,
  body: {
    data: {
      event_id: '12',
      results: [{ id: '20', version: 1, status: 'final', score: '2-1', source: 'theodds', external_id: 'abc123', entered_by: null, correction_reason: null, created_at: '2026-10-29T17:10:00+00:00' }],
      corrections,
    },
  },
});

const headers = (init?: RequestInit) => init?.headers as Record<string, string>;

describe('écran Événements & marchés', () => {
  it("liste les matchs avec leur origine et transmet les filtres à l'API", async () => {
    const fetchMock = mockApi({
      'GET /me': me([]),
      'GET /admin/events': { status: 200, body: page([event()]) },
    });

    const router = renderApp('/admin/events');

    const table = await screen.findByRole('table', { name: 'Événements' });
    expect(within(table).getByRole('link', { name: 'Arsenal – Chelsea' })).toHaveAttribute('href', '/admin/events/12');
    expect(within(table).getByText('abc123')).toBeInTheDocument();
    expect(within(table).getByText(/1 suspendu/)).toBeInTheDocument();

    await userEvent.selectOptions(screen.getByLabelText('Marchés'), 'suspended');
    await waitFor(() => expect(router.state.location.search).toBe('?market_status=suspended'));
    const urls = fetchMock.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('/admin/events'));
    expect(urls[urls.length - 1]).toContain('market_status=suspended');
  });

  it('montre fraîcheur et origine des cotes, sans action sans la permission markets.suspend', async () => {
    mockApi({
      'GET /me': me([]),
      'GET /admin/events/12': { status: 200, body: { data: event({ markets: [market({ status: 'suspended', suspension: { automatic: true, reason: 'auto: cotes périmées' }, bettable: false })] }) } },
      'GET /admin/events/12/results': history(),
    });

    renderApp('/admin/events/12');

    expect(await screen.findByText('Résultat du match (1X2)')).toBeInTheDocument();
    expect(screen.getByText('Suspension automatique')).toBeInTheDocument();
    expect(screen.getByText(/mise à jour il y a 10 min, seuil 300 min/)).toBeInTheDocument();
    expect(screen.getByText('pinnacle')).toBeInTheDocument();
    expect(screen.queryByText('Rouvrir ce marché')).toBeNull();
  });

  it('suspend un marché seulement avec un motif, envoyé avec une clé d\'idempotence', async () => {
    let patch: RequestInit | undefined;
    mockApi({
      'GET /me': me(['markets.suspend']),
      'GET /admin/events/12': { status: 200, body: { data: event({ markets: [market()] }) } },
      'GET /admin/events/12/results': history(),
      'PATCH /admin/markets/5': (init) => {
        patch = init;
        return { status: 200, body: { data: market({ status: 'suspended' }) } };
      },
    });

    renderApp('/admin/events/12');

    await userEvent.click(await screen.findByText('Suspendre ce marché'));
    const submit = screen.getByRole('button', { name: 'Suspendre' });
    expect(submit).toBeDisabled(); // motif obligatoire
    await userEvent.type(screen.getByLabelText('Motif (obligatoire)'), 'Blessure du gardien titulaire');
    await userEvent.click(submit);

    expect(await screen.findByText('Statut du marché mis à jour.')).toBeInTheDocument();
    expect(JSON.parse(String(patch?.body))).toEqual({ status: 'suspended', reason: 'Blessure du gardien titulaire' });
    expect(headers(patch)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('demande une correction de résultat avec score et motif', async () => {
    let post: RequestInit | undefined;
    mockApi({
      'GET /me': me(['results.enter']),
      'GET /admin/events/12': { status: 200, body: { data: event({ markets: [] }) } },
      'GET /admin/events/12/results': history(),
      'POST /admin/events/12/result-corrections': (init) => {
        post = init;
        return { status: 201, body: { data: correction() } };
      },
    });

    renderApp('/admin/events/12');

    expect(await screen.findByText(/Version 1 : 2-1/)).toBeInTheDocument();
    await userEvent.click(screen.getByText('Demander une correction du résultat'));
    await userEvent.type(screen.getByLabelText('Domicile'), '1');
    await userEvent.type(screen.getByLabelText('Extérieur'), '2');
    await userEvent.type(screen.getByLabelText('Motif (obligatoire)'), 'Score officiel publié par la ligue');

    // Football (D-03, V2-D04) : envoi bloqué tant que le score du temps réglementaire n'est pas confirmé.
    expect(screen.getByRole('button', { name: 'Envoyer la demande' })).toBeDisabled();
    await userEvent.click(screen.getByLabelText(/Score à la fin du temps réglementaire/));
    await userEvent.click(screen.getByRole('button', { name: 'Envoyer la demande' }));

    expect(await screen.findByText(/attend la validation d'un second administrateur/)).toBeInTheDocument();
    expect(JSON.parse(String(post?.body))).toEqual({ status: 'final', home_score: '1', away_score: '2', regulation_time: true, reason: 'Score officiel publié par la ligue' });
    expect(headers(post)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it("n'offre pas de nouvelle demande quand une correction est déjà en attente", async () => {
    mockApi({
      'GET /me': me(['results.enter']),
      'GET /admin/events/12': { status: 200, body: { data: event({ markets: [] }) } },
      'GET /admin/events/12/results': history([correction()]),
    });

    renderApp('/admin/events/12');

    expect(await screen.findByRole('link', { name: 'voir la demande' })).toHaveAttribute('href', '/admin/settlements/8');
    expect(screen.queryByText('Demander une correction du résultat')).toBeNull();
  });
});

describe('écran Résultats & règlement', () => {
  it('liste par défaut les corrections à valider', async () => {
    const fetchMock = mockApi({
      'GET /me': me(['results.enter', 'results.approve']),
      'GET /admin/result-corrections': { status: 200, body: page([correction()]) },
    });

    renderApp('/admin/settlements');

    const table = await screen.findByRole('table', { name: 'Demandes de résultat' });
    expect(within(table).getByRole('link', { name: 'Arsenal – Chelsea' })).toHaveAttribute('href', '/admin/settlements/8');
    expect(within(table).getByText('Rose Opérations')).toBeInTheDocument();
    const url = fetchMock.mock.calls.map(([u]) => String(u)).find((u) => u.includes('/admin/result-corrections'));
    expect(url).toContain('status=pending');
  });

  it('montre l\'impact et valide la demande avec un motif (second administrateur)', async () => {
    let post: RequestInit | undefined;
    mockApi({
      'GET /me': me(['results.enter', 'results.approve']),
      'GET /admin/result-corrections/8': { status: 200, body: { data: correction() } },
      'POST /admin/result-corrections/8/approve': (init) => {
        post = init;
        return { status: 200, body: { data: correction({ status: 'approved', decided_by: { id: '99', name: 'Admin' }, decision_reason: 'Vérifié sur le site de la ligue', decided_at: '2026-10-29T18:30:00+00:00', impact: correction().preview }) } };
      },
    });

    renderApp('/admin/settlements/8');

    const impact = await screen.findByRole('table', { name: 'Tickets réévalués' });
    expect(within(impact).getByRole('link', { name: 'R16-7KQ2M9XAPB' })).toHaveAttribute('href', '/admin/tickets/41');

    const approve = screen.getByRole('button', { name: 'Valider' });
    expect(approve).toBeDisabled();
    await userEvent.type(screen.getAllByLabelText('Motif (obligatoire)')[0], 'Vérifié sur le site de la ligue');
    await userEvent.click(approve);

    expect(await screen.findByRole('heading', { name: 'Validée' })).toBeInTheDocument();
    expect(JSON.parse(String(post?.body))).toEqual({ reason: 'Vérifié sur le site de la ligue' });
    expect(headers(post)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('le demandeur ne peut que retirer sa propre demande', async () => {
    mockApi({
      'GET /me': me(['results.enter', 'results.approve'], '7'),
      'GET /admin/result-corrections/8': { status: 200, body: { data: correction() } },
    });

    renderApp('/admin/settlements/8');

    expect(await screen.findByRole('button', { name: 'Retirer' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Valider' })).toBeNull();
    expect(screen.getByText(/un autre administrateur doit la valider/)).toBeInTheDocument();
  });
});

describe('saisie à la main du premier résultat (V2-D04)', () => {
  const noResult = { status: 200, body: { data: { event_id: '12', results: [], corrections: [] as unknown[] } } };
  /** Match commencé depuis longtemps, sans résultat du fournisseur. */
  const unplayed = (overrides: Record<string, unknown> = {}) => event({ status: 'live', score: null, result_received_at: null, markets: [], ...overrides });
  const firstResult = (overrides: Record<string, unknown> = {}) => correction({
    kind: 'initial',
    current: null,
    proposed: { status: 'final', score: '2-1' },
    reason: 'Fournisseur en panne, score officiel de la ligue',
    preview: { tickets_count: 0, ledger_delta_minor: 0, tickets: [] },
    ...overrides,
  });

  function entryPage(eventData: ReturnType<typeof event>, post?: Parameters<typeof mockApi>[0][string], permissions = ['results.enter']) {
    return mockApi({
      'GET /me': me(permissions),
      'GET /admin/events/12': { status: 200, body: { data: eventData } },
      'GET /admin/events/12/results': noResult,
      ...(post ? { 'POST /admin/events/12/result-corrections': post } : {}),
    });
  }

  it("saisit le premier résultat d'un match de football, temps réglementaire confirmé", async () => {
    let post: RequestInit | undefined;
    entryPage(unplayed(), (init) => { post = init; return { status: 201, body: { data: firstResult() } }; });

    renderApp('/admin/events/12');

    expect(await screen.findByText("Le fournisseur n'a pas encore publié de résultat pour ce match.")).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Premier résultat du match' })).toBeInTheDocument();
    const submit = screen.getByRole('button', { name: 'Envoyer pour validation' });

    // Le score ne prend que des chiffres ; chaque champ rappelle l'équipe.
    await userEvent.type(screen.getByLabelText('Domicile'), '2a');
    await userEvent.type(screen.getByLabelText('Extérieur'), '1');
    expect(screen.getByLabelText('Domicile')).toHaveValue('2');
    expect(screen.getByLabelText('Domicile')).toHaveAccessibleDescription('Arsenal');
    expect(screen.getByLabelText('Extérieur')).toHaveAccessibleDescription('Chelsea');

    await userEvent.type(screen.getByLabelText('Motif (obligatoire)'), 'Fournisseur en panne, score officiel de la ligue');
    // D-03 : sans la confirmation du temps réglementaire, rien ne part.
    expect(submit).toBeDisabled();
    await userEvent.click(screen.getByLabelText(/Score à la fin du temps réglementaire/));
    await userEvent.click(submit);

    expect(await screen.findByText("Résultat envoyé : il attend la validation d'un second administrateur.")).toBeInTheDocument();
    expect(JSON.parse(String(post?.body))).toEqual({
      status: 'final', home_score: '2', away_score: '1', regulation_time: true, reason: 'Fournisseur en panne, score officiel de la ligue',
    });
    expect(headers(post)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('ne demande pas le temps réglementaire hors football', async () => {
    let post: RequestInit | undefined;
    entryPage(
      unplayed({ sport: { slug: 'basketball', name: 'Basketball' }, home_team: 'Celtics', away_team: 'Heat' }),
      (init) => { post = init; return { status: 201, body: { data: firstResult({ proposed: { status: 'final', score: '101-99' } }) } }; },
    );

    renderApp('/admin/events/12');

    await userEvent.type(await screen.findByLabelText('Domicile'), '101');
    await userEvent.type(screen.getByLabelText('Extérieur'), '99');
    await userEvent.type(screen.getByLabelText('Motif (obligatoire)'), 'Score officiel de la ligue');
    expect(screen.queryByLabelText(/temps réglementaire/)).toBeNull();
    await userEvent.click(screen.getByRole('button', { name: 'Envoyer pour validation' }));

    await waitFor(() => expect(post).toBeDefined());
    expect(JSON.parse(String(post?.body))).toMatchObject({ status: 'final', home_score: '101', away_score: '99', regulation_time: false });
  });

  it('déclare le match annulé sans score ni temps réglementaire', async () => {
    let post: RequestInit | undefined;
    entryPage(unplayed(), (init) => { post = init; return { status: 201, body: { data: firstResult({ proposed: { status: 'cancelled', score: null } }) } }; });

    renderApp('/admin/events/12');

    await userEvent.selectOptions(await screen.findByLabelText('Issue'), 'cancelled');
    expect(screen.queryByLabelText('Domicile')).toBeNull();
    expect(screen.queryByLabelText(/temps réglementaire/)).toBeNull();
    await userEvent.type(screen.getByLabelText('Motif (obligatoire)'), 'Match arrêté, terrain impraticable');
    await userEvent.click(screen.getByRole('button', { name: 'Envoyer pour validation' }));

    await waitFor(() => expect(post).toBeDefined());
    expect(JSON.parse(String(post?.body))).toEqual({
      status: 'cancelled', home_score: null, away_score: null, regulation_time: false, reason: 'Match arrêté, terrain impraticable',
    });
  });

  it("donne l'heure à partir de laquelle la saisie est acceptée quand le serveur la juge trop tôt", async () => {
    entryPage(unplayed(), {
      status: 409,
      body: { error: { code: 'result_too_early', message: 'Trop tôt pour saisir le résultat de ce match à la main.', details: { available_at: '2026-10-29T12:00:00+00:00' } } },
    });

    renderApp('/admin/events/12');

    await userEvent.type(await screen.findByLabelText('Domicile'), '2');
    await userEvent.type(screen.getByLabelText('Extérieur'), '1');
    await userEvent.click(screen.getByLabelText(/Score à la fin du temps réglementaire/));
    await userEvent.type(screen.getByLabelText('Motif (obligatoire)'), 'Score officiel de la ligue');
    await userEvent.click(screen.getByRole('button', { name: 'Envoyer pour validation' }));

    expect(await screen.findByText('Trop tôt pour saisir le résultat de ce match à la main.')).toBeInTheDocument();
    expect(screen.getByText(/Saisie à la main possible à partir du 29\/10\/2026/)).toBeInTheDocument();
    // La saisie reste à l'écran : elle pourra être renvoyée à l'heure dite.
    expect(screen.getByLabelText('Domicile')).toHaveValue('2');
  });

  it("ne propose pas la saisie avant le coup d'envoi", async () => {
    entryPage(unplayed({ status: 'scheduled', starts_at: new Date(Date.now() + 3_600_000).toISOString() }));

    renderApp('/admin/events/12');

    expect(await screen.findByText("Le match n'a pas encore commencé : pas de résultat.")).toBeInTheDocument();
    expect(screen.queryByText('Saisir le résultat à la main')).toBeNull();
  });

  it('ne propose pas la saisie sans la permission results.enter', async () => {
    entryPage(unplayed(), undefined, []);

    renderApp('/admin/events/12');

    expect(await screen.findByText("Le fournisseur n'a pas encore publié de résultat pour ce match.")).toBeInTheDocument();
    expect(screen.queryByText('Saisir le résultat à la main')).toBeNull();
  });

  it('remplace la saisie par un lien vers la demande quand un premier résultat attend sa validation', async () => {
    mockApi({
      'GET /me': me(['results.enter']),
      'GET /admin/events/12': { status: 200, body: { data: unplayed() } },
      'GET /admin/events/12/results': { status: 200, body: { data: { event_id: '12', results: [], corrections: [firstResult()] } } },
    });

    renderApp('/admin/events/12');

    expect(await screen.findByText(/Le résultat saisi à la main attend la validation d'un second administrateur/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'voir la demande' })).toHaveAttribute('href', '/admin/settlements/8');
    expect(screen.queryByText('Saisir le résultat à la main')).toBeNull();
  });

  it('signale dans la liste les matchs commencés depuis plus de 3 h sans résultat', async () => {
    const hoursAgo = (h: number) => new Date(Date.now() - h * 3_600_000).toISOString();
    mockApi({
      'GET /me': me([]),
      'GET /admin/events': {
        status: 200,
        body: page([
          event(), // résultat reçu
          unplayed({ id: '13', home_team: 'Lyon', away_team: 'Nice', starts_at: hoursAgo(4) }),
          unplayed({ id: '14', home_team: 'Lille', away_team: 'Lens', starts_at: hoursAgo(1) }), // encore en cours
          unplayed({ id: '15', home_team: 'Metz', away_team: 'Brest', starts_at: hoursAgo(30), status: 'postponed' }),
        ]),
      },
    });

    renderApp('/admin/events');

    const table = await screen.findByRole('table', { name: 'Événements' });
    const missing = within(table).getAllByRole('link', { name: 'Résultat manquant' });
    expect(missing).toHaveLength(1);
    expect(missing[0]).toHaveAttribute('href', '/admin/events/13#resultat');
  });

  it("liste un premier résultat à valider et l'annonce comme tel", async () => {
    mockApi({
      'GET /me': me(['results.enter', 'results.approve']),
      'GET /admin/result-corrections': { status: 200, body: page([firstResult(), correction({ id: '9', kind: 'correction' })]) },
    });

    renderApp('/admin/settlements');

    const table = await screen.findByRole('table', { name: 'Demandes de résultat' });
    expect(within(table).getByText(/Premier résultat :/)).toHaveTextContent('Premier résultat : 2-1');
    expect(within(table).getByText(/2-1 →/)).toHaveTextContent('2-1 → 1-2');
    expect(screen.getByText('2 demandes de résultat en attente de validation.')).toBeInTheDocument();
  });

  it('fait valider le premier résultat par un second administrateur, puis annonce le règlement', async () => {
    let post: RequestInit | undefined;
    mockApi({
      'GET /me': me(['results.enter', 'results.approve']),
      'GET /admin/result-corrections/8': { status: 200, body: { data: firstResult() } },
      'POST /admin/result-corrections/8/approve': (init) => {
        post = init;
        return {
          status: 200,
          body: { data: firstResult({
            status: 'approved', decided_by: { id: '99', name: 'Admin' }, decision_reason: 'Vérifié sur le site de la ligue', decided_at: '2026-10-29T18:30:00+00:00',
            applied_result_id: '21', preview: undefined, impact: { tickets_count: 0, ledger_delta_minor: 0, tickets: [], pending_tickets_count: 4 },
          }) },
        };
      },
    });

    renderApp('/admin/settlements/8');

    expect(await screen.findByRole('heading', { name: 'Résultat saisi à la main n° 8', level: 1 })).toBeInTheDocument();
    expect(screen.getByText('Résultat avant la saisie')).toBeInTheDocument();
    expect(screen.getByText('Aucun : rien de publié par le fournisseur')).toBeInTheDocument();
    expect(screen.getByText(/les tickets en attente sur ce match sont réglés/)).toBeInTheDocument();
    expect(screen.queryByRole('table', { name: 'Tickets réévalués' })).toBeNull();
    expect(screen.getByRole('heading', { name: 'Valider le résultat' })).toBeInTheDocument();
    expect(screen.getByText(/score à la fin du temps réglementaire, sans prolongation ni tirs au but/)).toBeInTheDocument();

    await userEvent.type(screen.getAllByLabelText('Motif (obligatoire)')[0], 'Vérifié sur le site de la ligue');
    await userEvent.click(screen.getByRole('button', { name: 'Valider' }));

    expect(await screen.findByText('Résultat enregistré ; 4 tickets en attente envoyés au règlement.')).toBeInTheDocument();
    expect(JSON.parse(String(post?.body))).toEqual({ reason: 'Vérifié sur le site de la ligue' });
  });

  it('le saisisseur ne peut pas valider son propre résultat', async () => {
    mockApi({
      'GET /me': me(['results.enter', 'results.approve'], '7'),
      'GET /admin/result-corrections/8': { status: 200, body: { data: firstResult() } },
    });

    renderApp('/admin/settlements/8');

    expect(await screen.findByRole('button', { name: 'Retirer' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Valider' })).toBeNull();
  });
});
