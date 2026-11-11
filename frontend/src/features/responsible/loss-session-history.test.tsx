import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { guest, mockApi, player, renderApp } from '../../test/renderApp';

// Jeu responsable V2-E06 : limites de pertes, rappel de durée de session, historique des dépenses.

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

const NBSP = new RegExp(`[${String.fromCharCode(0xa0, 0x202f)}]`, 'g');
const text = (el: HTMLElement) => (el.textContent ?? '').replace(NBSP, ' ');
const htg = (minor: number) => ({ minor, currency: 'HTG' });

const noLimit = { amount: null, used: htg(0), remaining: null, pending: null };
const limits = { day: noLimit, week: noLimit, month: noLimit };

function state(overrides: { loss?: Record<string, unknown>; session?: { reminder_minutes: number | null; started_at: string | null } } = {}) {
  return {
    data: {
      limits,
      deposit_limits: limits,
      loss_limits: { ...limits, ...overrides.loss },
      session: overrides.session ?? { reminder_minutes: null, started_at: '2026-11-10T10:00:00+00:00' },
      pause: { active: false, ends_at: null },
      options: {
        pause_durations: ['24h', '7d', '30d'], exclusion_durations: ['6m', '1y', '5y'], limit_increase_delay_days: 7,
        session_reminder_minutes: [15, 30, 60],
      },
    },
  };
}

const base = {
  'GET /me': { status: 200, body: { data: player } },
  'GET /wallet': { status: 200, body: { data: { currency: 'HTG', balance: htg(1_000_00), status: 'active', simulated: false } } },
};

const card = (name: string) => screen.findByRole('region', { name });

describe('limites de pertes', () => {
  it('affiche la perte nette de la période et explique la règle', async () => {
    mockApi({
      ...base,
      'GET /responsible-gaming': { status: 200, body: state({ loss: { day: { amount: htg(1_000_00), used: htg(400_00), remaining: htg(600_00), pending: null } } }) },
    });

    renderApp('/responsible-gaming');

    const losses = await card('Limites de pertes');
    expect(text(losses)).toMatch(/Votre perte nette ne pourra pas dépasser ces montants.*7 jours de réflexion/);
    expect(text(losses)).toContain('Perte nette = vos mises moins vos gains et remboursements');
    const day = within(losses).getAllByRole('heading', { level: 3 })[0].closest('.rg-limit') as HTMLElement;
    expect(text(day)).toContain('Perdu : 400,00 HTG · Reste : 600,00 HTG');
    expect(within(day).getByRole('progressbar', { name: 'Pertes sur 24 heures' })).toBeInTheDocument();
  });

  it('fixe une limite de pertes sur sa propre route', async () => {
    let body: unknown;
    mockApi({
      ...base,
      'GET /responsible-gaming': { status: 200, body: state() },
      'PUT /responsible-gaming/loss-limits/month': (init) => {
        body = JSON.parse(String(init?.body));
        return { status: 200, body: state({ loss: { month: { amount: htg(10_000_00), used: htg(0), remaining: htg(10_000_00), pending: null } } }) };
      },
    });

    renderApp('/responsible-gaming');
    const losses = await card('Limites de pertes');
    await userEvent.click(within(losses).getAllByRole('button', { name: 'Fixer une limite' })[2]);
    await userEvent.type(within(losses).getByLabelText('Nouvelle limite de pertes sur 30 jours (HTG)'), '10000');
    await userEvent.click(within(losses).getByRole('button', { name: 'Enregistrer' }));

    expect(await within(losses).findByText('Limite appliquée.')).toBeInTheDocument();
    expect(body).toEqual({ amount_minor: 10_000_00 });
  });

  it('refuse un pari au-delà de la limite de pertes et donne la mise encore possible', async () => {
    const inHours = (h: number) => new Date(Date.now() + h * 3_600_000).toISOString();
    const event = {
      id: '1', sport: { slug: 'soccer', name: 'Football' }, competition: { id: '1', name: 'Premier League', country: 'Angleterre' },
      home_team: 'Everton', away_team: 'Chelsea', starts_at: inHours(5), status: 'scheduled',
      markets: [{
        id: 'm1', key: 'h2h', name: 'Résultat du match (1X2)', odds_version: 1, odds_updated_at: inHours(0), bettable: true,
        selections: [{ id: '11', key: 'home', label: '1', name: 'Everton', odds: '2.00' }],
      }],
    };
    mockApi({
      ...base,
      'GET /sports': { status: 200, body: { data: [] } },
      'GET /events': { status: 200, body: { data: [event], links: {}, meta: { current_page: 1, per_page: 30, total: 1, last_page: 1, from: 1, to: 1 } } },
      'POST /bets': {
        status: 422,
        body: { error: { code: 'loss_limit_exceeded', message: 'Si ce pari est perdu, vous dépasserez votre limite de pertes.', details: { period: 'day', limit_minor: 1_000_00, remaining_minor: 250_00 } } },
      },
    });

    renderApp('/');
    await userEvent.click((await screen.findAllByRole('button', { name: '1 Everton à 2.00' }))[0]);
    const slip = screen.getByRole('complementary', { name: 'Coupon' });
    await userEvent.type(within(slip).getByLabelText('Montant de la mise'), '500');
    await userEvent.click(within(slip).getByRole('button', { name: 'Placer mon pari' }));

    expect(text(await within(slip).findByText(/vous dépasserez votre limite de pertes/)))
      .toBe('Si ce pari est perdu, vous dépasserez votre limite de pertes. Mise maximale possible : 250,00 HTG.');
    expect(within(slip).getByLabelText('1 sélection')).toBeInTheDocument();
  });

  it('ne montre ni limites de pertes, ni rappel, ni historique quand le serveur ne les gère pas encore', async () => {
    // Réponse d'un serveur antérieur à V2-E06 : ni loss_limits, ni session, ni intervalles de rappel.
    const older: Record<string, unknown> = { ...state().data };
    delete older.loss_limits;
    delete older.session;
    older.options = { pause_durations: ['24h'], exclusion_durations: ['6m'], limit_increase_delay_days: 7 };
    mockApi({ ...base, 'GET /responsible-gaming': { status: 200, body: { data: older } } });

    renderApp('/responsible-gaming');

    expect(await card('Limites de mise')).toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Limites de pertes' })).toBeNull();
    expect(screen.queryByRole('region', { name: 'Rappel de durée de session' })).toBeNull();
    expect(screen.queryByRole('link', { name: /Historique de mes dépenses/ })).toBeNull();
  });
});

describe('rappel de durée de session', () => {
  it('choisit l\'intervalle, ou le désactive, avec une clé d\'idempotence', async () => {
    const sent: unknown[] = [];
    let minutes: number | null = null;
    mockApi({
      ...base,
      'GET /responsible-gaming': () => ({ status: 200, body: state({ session: { reminder_minutes: minutes, started_at: new Date().toISOString() } }) }),
      'PUT /responsible-gaming/session-reminder': (init) => {
        minutes = JSON.parse(String(init?.body)).minutes;
        sent.push({ body: JSON.parse(String(init?.body)), key: new Headers(init?.headers).get('Idempotency-Key') });
        return { status: 200, body: state({ session: { reminder_minutes: minutes, started_at: new Date().toISOString() } }) };
      },
    });

    renderApp('/responsible-gaming');
    const reminder = await card('Rappel de durée de session');
    expect(within(reminder).getByRole('radio', { name: 'Jamais' })).toBeChecked();

    await userEvent.click(within(reminder).getByRole('radio', { name: 'Toutes les 30 min' }));
    expect(await within(reminder).findByText('Rappel toutes les 30 minutes.')).toBeInTheDocument();
    expect(within(reminder).getByRole('radio', { name: 'Toutes les 30 min' })).toBeChecked();

    await userEvent.click(within(reminder).getByRole('radio', { name: 'Jamais' }));
    expect(await within(reminder).findByText('Rappel désactivé.')).toBeInTheDocument();
    expect(sent).toEqual([{ body: { minutes: 30 }, key: expect.any(String) }, { body: { minutes: null }, key: expect.any(String) }]);
  });

  it('affiche le rappel à l\'intervalle choisi depuis la connexion, jamais pour une échéance déjà passée', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const now = new Date('2026-11-10T12:25:00Z');
    vi.setSystemTime(now);
    mockApi({
      ...base,
      // Connecté depuis 25 min, rappel toutes les 15 min : l'échéance de 15 min est passée, la prochaine est à 30 min.
      'GET /responsible-gaming': { status: 200, body: state({ session: { reminder_minutes: 15, started_at: '2026-11-10T12:00:00Z' } }) },
    });

    renderApp('/wallet');
    await screen.findByRole('heading', { name: /Solde/i, level: 1 });
    await act(async () => { await vi.advanceTimersByTimeAsync(1_000); });
    expect(screen.queryByRole('alertdialog')).toBeNull();

    await act(async () => { await vi.advanceTimersByTimeAsync(5 * 60_000); });
    const dialog = await screen.findByRole('alertdialog', { name: 'Vous êtes connecté depuis 30 min' });
    expect(within(dialog).getByRole('link', { name: 'Voir mes dépenses' })).toHaveAttribute('href', '/responsible-gaming/history');

    await userEvent.click(within(dialog).getByRole('button', { name: 'Continuer' }));
    expect(screen.queryByRole('alertdialog')).toBeNull();

    await act(async () => { await vi.advanceTimersByTimeAsync(15 * 60_000); });
    expect(await screen.findByRole('alertdialog', { name: 'Vous êtes connecté depuis 45 min' })).toBeInTheDocument();
  });

  it('ne rappelle rien à un visiteur', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const fetchMock = mockApi({ 'GET /me': guest });

    renderApp('/login');
    await screen.findByRole('heading', { name: 'Connexion' });
    await act(async () => { await vi.advanceTimersByTimeAsync(2 * 3_600_000); });

    expect(screen.queryByRole('alertdialog')).toBeNull();
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('/responsible-gaming'))).toBe(false);
  });
});

describe('historique des dépenses', () => {
  const zero = { deposits: htg(0), withdrawals: htg(0), stakes: htg(0), payouts: htg(0), refunds: htg(0), net: htg(0) };
  const history = {
    status: 200,
    body: { data: {
      timezone: 'America/Port-au-Prince',
      days: [
        { date: '2026-11-10', ...zero, deposits: htg(1_000_00), stakes: htg(300_00), payouts: htg(500_00), net: htg(200_00) },
        { date: '2026-11-09', ...zero },
        { date: '2026-11-08', ...zero, stakes: htg(400_00), refunds: htg(100_00), net: htg(-300_00) },
      ],
      months: [
        { month: '2026-11', ...zero, deposits: htg(1_000_00), stakes: htg(700_00), payouts: htg(500_00), refunds: htg(100_00), net: htg(-100_00) },
        { month: '2026-10', ...zero },
      ],
    } },
  };

  it('liste les jours avec mouvement, les totaux et le résultat net signé', async () => {
    mockApi({ ...base, 'GET /responsible-gaming': { status: 200, body: state() }, 'GET /responsible-gaming/history': history });

    renderApp('/responsible-gaming');
    await userEvent.click(await screen.findByRole('link', { name: /Historique de mes dépenses/ }));

    expect(await screen.findByRole('heading', { name: 'Historique de mes dépenses', level: 1 })).toBeInTheDocument();
    const table = await screen.findByRole('table', { name: 'Dépenses par jour' });
    const rows = within(table).getAllByRole('row').slice(1);
    expect(rows).toHaveLength(2); // le 9 novembre, sans mouvement, est masqué
    expect(text(rows[0])).toMatch(/^mar\. 10 nov\./);
    expect(text(rows[0])).toContain('+200,00 HTG');
    expect(text(rows[1])).toContain('-300,00 HTG');

    const totals = screen.getByText('Déposé').closest('dl') as HTMLElement;
    expect(text(totals)).toContain('Déposé1 000,00 HTG');
    expect(text(totals)).toContain('Misé700,00 HTG');
    expect(text(totals)).toContain('Résultat net-100,00 HTG');
    expect(screen.getByText("Totaux sur les 3 derniers jours, à l'heure d'Haïti.")).toBeInTheDocument();

    await userEvent.click(screen.getByRole('checkbox', { name: 'Masquer les périodes sans mouvement' }));
    expect(within(table).getAllByRole('row')).toHaveLength(4);
  });

  it('passe à la vue par mois', async () => {
    mockApi({ ...base, 'GET /responsible-gaming/history': history });

    renderApp('/responsible-gaming/history');
    await userEvent.click(await screen.findByRole('button', { name: 'Par mois' }));

    const table = await screen.findByRole('table', { name: 'Dépenses par mois' });
    const rows = within(table).getAllByRole('row').slice(1);
    expect(rows).toHaveLength(1);
    expect(text(rows[0])).toMatch(/^novembre 2026/);
    expect(screen.getByRole('button', { name: 'Par mois' })).toHaveAttribute('aria-pressed', 'true');
  });

  it('le dit quand rien ne s\'est passé sur la période', async () => {
    mockApi({
      ...base,
      'GET /responsible-gaming/history': { status: 200, body: { data: { timezone: 'America/Port-au-Prince', days: [{ date: '2026-11-10', ...zero }], months: [] } } },
    });

    renderApp('/responsible-gaming/history');

    expect(await screen.findByText('Aucun dépôt, retrait ni pari sur les 1 derniers jours.')).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByRole('table')).toBeNull());
  });
});
