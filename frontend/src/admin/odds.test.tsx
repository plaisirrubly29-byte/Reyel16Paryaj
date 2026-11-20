import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, player, renderApp } from '../test/renderApp';
import { deviationHundredths, deviationLabel, percentLabel } from './api/odds';

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
  meta: { current_page: 1, per_page: 20, total: data.length, last_page: 1, from: data.length ? 1 : null, to: data.length },
});

const tomorrow = new Date(Date.now() + 24 * 3_600_000).toISOString();

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
    { id: '51', key: 'home', label: '1', name: 'Arsenal', odds: '2.14', computed_odds: '2.14', provider_odds: '2.10', best_odds: '2.20', adjusted: false },
    { id: '52', key: 'draw', label: 'X', name: 'Match nul', odds: '3.29', computed_odds: '3.29', provider_odds: '3.25', best_odds: '3.40', adjusted: false },
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
  starts_at: tomorrow,
  status: 'scheduled',
  score: null,
  origin: { provider: 'theodds', external_id: 'abc123' },
  last_synced_at: '2026-10-29T09:55:00+00:00',
  result_received_at: null,
  markets_count: { open: 1, suspended: 0, closed: 0, settled: 0 },
  markets: [market()],
  ...overrides,
});

const override = (overrides: Record<string, unknown> = {}) => ({
  id: '30',
  market_id: '5',
  selection: { id: '51', key: 'home', name: 'Arsenal' },
  event: { id: '12', home_team: 'Arsenal', away_team: 'Chelsea', starts_at: tomorrow },
  status: 'pending',
  odds: '2.80',
  computed_odds: '2.14',
  deviation_percent: '30.84',
  requires_approval: true,
  reason: 'Information sur la composition',
  expires_at: tomorrow,
  requested_by: { id: '7', name: 'Rose Opérations' },
  requested_at: '2026-10-29T10:00:00+00:00',
  decided_by: null,
  decision_reason: null,
  decided_at: null,
  ended_by: null,
  ended_at: null,
  ...overrides,
});

const noResult = { status: 200, body: { data: { event_id: '12', results: [], corrections: [] } } };
const headers = (init?: RequestInit) => init?.headers as Record<string, string>;

describe('écart avec la cote calculée', () => {
  it('se calcule sans nombre à virgule, tronqué comme le serveur', () => {
    expect(deviationHundredths('2.00', '2.14')).toBe(-654);
    expect(deviationHundredths('2.80', '2.14')).toBe(3084);
    expect(deviationHundredths('abc', '2.14')).toBeNull();
    expect(percentLabel(-654)).toBe('-6,54 %');
    expect(deviationLabel('30.84')).toBe('+30,84 %');
    expect(deviationLabel('-5.5')).toBe('-5,50 %');
  });
});

describe('fiche du match : cotes fixées à la main', () => {
  it('montre les repères et la mention « Ajustée », sans action sans la permission odds.override', async () => {
    const fetchMock = mockApi({
      'GET /me': me([]),
      'GET /admin/events/12': { status: 200, body: { data: event({ markets: [market({ selections: [
        { id: '51', key: 'home', label: '1', name: 'Arsenal', odds: '2.00', computed_odds: '2.14', provider_odds: '2.10', best_odds: '2.20', adjusted: true },
      ] })] }) } },
      'GET /admin/events/12/results': noResult,
      'GET /admin/odds-overrides': { status: 200, body: page([override({ status: 'active', odds: '2.00', deviation_percent: '-6.54', requires_approval: false })]) },
    });

    renderApp('/admin/events/12');

    const odds = await screen.findByRole('list', { name: 'Cotes : Résultat du match (1X2)' });
    expect(within(odds).getByText('Ajustée')).toBeInTheDocument();
    expect(within(odds).getByText('Repères : calculée 2.14 · bookmaker 2.10 · meilleure 2.20')).toBeInTheDocument();
    expect(await within(odds).findByText(/Fixée à la main par Rose Opérations/)).toBeInTheDocument();
    expect(screen.queryByText('Fixer une cote')).toBeNull();
    expect(screen.queryByText('Revenir à la cote calculée')).toBeNull();
    const url = fetchMock.mock.calls.map(([u]) => String(u)).find((u) => u.includes('/admin/odds-overrides'));
    expect(url).toContain('event_id=12');
  });

  it("calcule l'écart en direct, prévient de la double validation et de l'arbitrage, puis envoie la demande", async () => {
    let post: RequestInit | undefined;
    mockApi({
      'GET /me': me(['odds.override']),
      'GET /admin/events/12': { status: 200, body: { data: event() } },
      'GET /admin/events/12/results': noResult,
      'GET /admin/odds-overrides': { status: 200, body: page([]) },
      'POST /admin/markets/5/odds-overrides': (init) => {
        post = init;
        return { status: 201, body: { data: override() } };
      },
    });

    renderApp('/admin/events/12');

    await userEvent.click(await screen.findByText('Fixer une cote'));
    const submit = screen.getByRole('button', { name: 'Fixer la cote' });
    expect(submit).toBeDisabled();

    await userEvent.type(screen.getByLabelText('Nouvelle cote'), '2,80');
    expect(screen.getByText('+30,84 %')).toBeInTheDocument();
    expect(screen.getByText(/double validation/)).toBeInTheDocument();
    expect(screen.getByText(/risque d'arbitrage/)).toBeInTheDocument();

    await userEvent.type(screen.getByLabelText('Motif (obligatoire)'), 'Information sur la composition');
    await userEvent.click(submit);

    expect(await screen.findByText(/attend la validation d'un second administrateur/)).toBeInTheDocument();
    expect(JSON.parse(String(post?.body))).toEqual({ selection_id: '51', odds: '2.80', reason: 'Information sur la composition' });
    expect(headers(post)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it("ne prévient de rien pour un petit écart sous la meilleure cote, et affiche l'erreur de champ du serveur", async () => {
    mockApi({
      'GET /me': me(['odds.override']),
      'GET /admin/events/12': { status: 200, body: { data: event() } },
      'GET /admin/events/12/results': noResult,
      'GET /admin/odds-overrides': { status: 200, body: page([]) },
      'POST /admin/markets/5/odds-overrides': {
        status: 422,
        body: { error: { code: 'validation_failed', message: 'Données invalides.', details: { expires_at: ['La date limite ne peut pas dépasser le début du match.'] } } },
      },
    });

    renderApp('/admin/events/12');

    await userEvent.click(await screen.findByText('Fixer une cote'));
    await userEvent.type(screen.getByLabelText('Nouvelle cote'), '2.05');
    expect(screen.getByText('-4,20 %')).toBeInTheDocument();
    expect(screen.queryByText(/double validation/)).toBeNull();
    expect(screen.queryByText(/risque d'arbitrage/)).toBeNull();

    await userEvent.type(screen.getByLabelText('Motif (obligatoire)'), 'Ajustement commercial');
    await userEvent.click(screen.getByRole('button', { name: 'Fixer la cote' }));

    expect(await screen.findByText('La date limite ne peut pas dépasser le début du match.')).toBeInTheDocument();
  });

  it("remplace le formulaire par un lien quand une demande attend, et ne propose rien après le coup d'envoi", async () => {
    mockApi({
      'GET /me': me(['odds.override']),
      'GET /admin/events/12': { status: 200, body: { data: event({ markets: [market({ selections: [market().selections[0]] })] }) } },
      'GET /admin/events/12/results': noResult,
      'GET /admin/odds-overrides': { status: 200, body: page([override()]) },
    });

    renderApp('/admin/events/12');

    expect(await screen.findByRole('link', { name: 'voir la demande' })).toHaveAttribute('href', '/admin/odds/30');
    expect(screen.getByText(/Cote 2.80 \(\+30,84 %\) en attente de validation/)).toBeInTheDocument();
    expect(screen.queryByText('Fixer une cote')).toBeNull(); // seule sélection du marché : déjà une demande
  });

  it("ne propose pas de fixer une cote une fois le match commencé", async () => {
    mockApi({
      'GET /me': me(['odds.override']),
      'GET /admin/events/12': { status: 200, body: { data: event({ starts_at: '2026-01-01T15:00:00+00:00', status: 'live' }) } },
      'GET /admin/events/12/results': noResult,
      'GET /admin/odds-overrides': { status: 200, body: page([]) },
    });

    renderApp('/admin/events/12');

    expect(await screen.findByRole('list', { name: 'Cotes : Résultat du match (1X2)' })).toBeInTheDocument();
    expect(screen.queryByText('Fixer une cote')).toBeNull();
  });

  it('revient à la cote calculée avec un motif', async () => {
    let post: RequestInit | undefined;
    mockApi({
      'GET /me': me(['odds.override']),
      'GET /admin/events/12': { status: 200, body: { data: event() } },
      'GET /admin/events/12/results': noResult,
      'GET /admin/odds-overrides': { status: 200, body: page([override({ status: 'active', odds: '2.00', deviation_percent: '-6.54', requires_approval: false })]) },
      'POST /admin/odds-overrides/30/end': (init) => {
        post = init;
        return { status: 200, body: { data: override({ status: 'ended', ended_by: { id: '99', name: 'Admin' }, ended_at: '2026-10-29T11:00:00+00:00' }) } };
      },
    });

    renderApp('/admin/events/12');

    await userEvent.click(await screen.findByText('Revenir à la cote calculée', { selector: 'summary' }));
    const form = screen.getByRole('form', { name: 'Revenir à la cote calculée' });
    await userEvent.type(within(form).getByLabelText('Motif (obligatoire)'), 'La composition est confirmée');
    await userEvent.click(within(form).getByRole('button', { name: 'Revenir à la cote calculée' }));

    expect(await screen.findByText('La cote calculée est de retour.')).toBeInTheDocument();
    expect(JSON.parse(String(post?.body))).toEqual({ reason: 'La composition est confirmée' });
  });
});

describe('écran Cotes ajustées', () => {
  it('liste les cotes fixées à la main et filtre par statut', async () => {
    const fetchMock = mockApi({
      'GET /me': me([]),
      'GET /admin/odds-overrides': { status: 200, body: page([override()]) },
    });

    const router = renderApp('/admin/odds');

    const table = await screen.findByRole('table', { name: 'Cotes fixées à la main' });
    expect(within(table).getByRole('link', { name: 'Arsenal – Chelsea' })).toHaveAttribute('href', '/admin/odds/30');
    expect(within(table).getByText('Rose Opérations')).toBeInTheDocument();
    expect(within(table).getByText('(+30,84 %)')).toBeInTheDocument();
    expect(screen.getByRole('navigation', { name: 'Administration' })).toHaveTextContent('Cotes ajustées');

    await userEvent.selectOptions(screen.getByLabelText('Statut'), 'pending');
    await waitFor(() => expect(router.state.location.search).toBe('?status=pending'));
    const urls = fetchMock.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('/admin/odds-overrides'));
    expect(urls[urls.length - 1]).toContain('status=pending');
  });

  it('fait valider la cote par un second administrateur', async () => {
    let post: RequestInit | undefined;
    mockApi({
      'GET /me': me(['odds.override']),
      'GET /admin/odds-overrides/30': { status: 200, body: { data: override() } },
      'POST /admin/odds-overrides/30/approve': (init) => {
        post = init;
        return { status: 200, body: { data: override({ status: 'active', decided_by: { id: '99', name: 'Admin' }, decision_reason: 'Vérifié avec le trader', decided_at: '2026-10-29T10:30:00+00:00' }) } };
      },
    });

    renderApp('/admin/odds/30');

    expect(await screen.findByText(/Double validation : écart de plus de 20 %/)).toBeInTheDocument();
    const approve = screen.getByRole('button', { name: 'Valider' });
    expect(approve).toBeDisabled();
    await userEvent.type(screen.getAllByLabelText('Motif (obligatoire)')[0], 'Vérifié avec le trader');
    await userEvent.click(approve);

    expect(await screen.findByRole('heading', { name: 'En vigueur' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Revenir à la cote calculée' })).toBeInTheDocument();
    expect(JSON.parse(String(post?.body))).toEqual({ reason: 'Vérifié avec le trader' });
    expect(headers(post)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('le demandeur ne peut que retirer sa propre demande', async () => {
    mockApi({
      'GET /me': me(['odds.override'], '7'),
      'GET /admin/odds-overrides/30': { status: 200, body: { data: override() } },
    });

    renderApp('/admin/odds/30');

    expect(await screen.findByRole('button', { name: 'Retirer' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Valider' })).toBeNull();
    expect(screen.getByText(/un autre administrateur doit la valider/)).toBeInTheDocument();
  });

  it('affiche le refus du serveur (rôle trop récent)', async () => {
    mockApi({
      'GET /me': me(['odds.override']),
      'GET /admin/odds-overrides/30': { status: 200, body: { data: override() } },
      'POST /admin/odds-overrides/30/approve': { status: 403, body: { error: { code: 'approver_role_too_recent', message: 'Votre rôle est trop récent pour valider une demande.' } } },
    });

    renderApp('/admin/odds/30');

    await userEvent.type((await screen.findAllByLabelText('Motif (obligatoire)'))[0], 'Vérifié avec le trader');
    await userEvent.click(screen.getByRole('button', { name: 'Valider' }));

    expect(await screen.findByText('Votre rôle est trop récent pour valider une demande.')).toBeInTheDocument();
  });

  it('consultation seule sans la permission odds.override', async () => {
    mockApi({
      'GET /me': me([]),
      'GET /admin/odds-overrides/30': { status: 200, body: { data: override() } },
    });

    renderApp('/admin/odds/30');

    expect(await screen.findByText(/réservée aux administrateurs habilités/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Valider' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Refuser' })).toBeNull();
  });
});
