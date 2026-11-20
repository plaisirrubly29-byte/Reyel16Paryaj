import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, player, renderApp } from '../test/renderApp';
import { explainAlert } from './api/aml';
import { visibleSections } from './navigation';

afterEach(() => vi.unstubAllGlobals());

const me = (permissions: string[], id = '99') => ({
  status: 200,
  body: {
    data: {
      ...player,
      id,
      name: 'Carla Conformité',
      is_admin: true,
      roles: ['compliance'],
      permissions: ['dashboard.view', ...permissions],
      mfa: { enabled: true, verified: true, required: true },
    },
  },
});

const page = (data: unknown[]) => ({
  data,
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 20, total: data.length, last_page: 1, from: data.length ? 1 : null, to: data.length },
});

const alert = (overrides: Record<string, unknown> = {}) => ({
  id: '12',
  rule: 'withdrawal_without_play',
  status: 'open',
  user: { id: '41', name: 'Jean Pierre' },
  ledger_entry_id: '903',
  amount: { minor: 75_000_00, currency: 'HTG' },
  details: { window_hours: 72, min_stake_percent: 30, deposited_minor: 80_000_00, staked_minor: 4_000_00 },
  reviewed_by: null,
  review_reason: null,
  reviewed_at: null,
  created_at: '2026-11-19T22:10:00+00:00',
  ...overrides,
});

const headers = (init?: RequestInit) => init?.headers as Record<string, string>;
const space = (text: string) => text.replace(/\s/g, ' ');

describe('explication des règles AML', () => {
  it('dit en clair ce qui a déclenché chaque règle, avec les seuils enregistrés', () => {
    expect(space(explainAlert(alert() as never))).toContain('alors que les dépôts des 72 dernières heures (80 000,00 HTG) n\'ont été misés que pour 4 000,00 HTG, sous le minimum de 30 %');
    expect(space(explainAlert(alert({ rule: 'large_deposit', amount: { minor: 80_000_00, currency: 'HTG' }, details: { threshold_minor: 50_000_00 } }) as never)))
      .toBe('Dépôt de 80 000,00 HTG, au-dessus du seuil de 50 000,00 HTG pour un seul dépôt.');
    expect(space(explainAlert(alert({ rule: 'cumulative_deposits', amount: { minor: 40_000_00, currency: 'HTG' }, details: { threshold_minor: 150_000_00, window_days: 7, deposited_minor: 155_000_00 } }) as never)))
      .toBe('Avec ce dépôt de 40 000,00 HTG, les dépôts des 7 derniers jours atteignent 155 000,00 HTG (seuil : 150 000,00 HTG).');
  });
});

describe('file des alertes AML (V2-C04)', () => {
  it('est dans le menu Contrôle seulement avec aml.view', () => {
    expect(visibleSections(['aml.view']).map((s) => s.label)).toContain('Alertes AML');
    expect(visibleSections(['dashboard.view']).map((s) => s.label)).not.toContain('Alertes AML');
  });

  it('montre par défaut les alertes à examiner (adresse de la cloche), puis filtre par règle', async () => {
    const fetchMock = mockApi({
      'GET /me': me(['aml.view']),
      'GET /admin/aml/alerts': { status: 200, body: page([alert(), alert({ id: '10', rule: 'large_deposit' })]) },
    });

    const router = renderApp('/admin/aml?status=open');

    const table = await screen.findByRole('table', { name: 'Alertes AML' });
    expect(within(table).getByRole('link', { name: 'Retrait sans jeu' })).toHaveAttribute('href', '/admin/aml/12');
    expect(within(table).getAllByRole('link', { name: 'Joueur n° 41' })[0]).toHaveAttribute('href', '/admin/users/41');
    expect(screen.getByText(/2 alertes à examiner/)).toBeInTheDocument();
    const urls = () => fetchMock.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('/admin/aml/alerts'));
    expect(urls()[0]).toContain('status=open');

    await userEvent.selectOptions(screen.getByLabelText('Règle'), 'large_deposit');
    await waitFor(() => expect(router.state.location.search).toBe('?status=open&rule=large_deposit'));
    await waitFor(() => expect(urls()[urls().length - 1]).toContain('rule=large_deposit'));
  });

  it('dit simplement qu\'il n\'y a rien à examiner', async () => {
    mockApi({ 'GET /me': me(['aml.view']), 'GET /admin/aml/alerts': { status: 200, body: page([]) } });

    renderApp('/admin/aml');

    expect(await screen.findAllByText('Aucune alerte à examiner.')).toHaveLength(2); // sous-titre et tableau vide
  });
});

describe('fiche d\'une alerte AML', () => {
  it('explique l\'alerte et la signale avec un motif, envoyé avec une clé d\'idempotence', async () => {
    let post: RequestInit | undefined;
    mockApi({
      'GET /me': me(['aml.view', 'aml.review', 'ledger.view']),
      'GET /admin/aml/alerts/12': { status: 200, body: { data: alert() } },
      'POST /admin/aml/alerts/12/review': (init) => {
        post = init;
        return { status: 200, body: { data: alert({ status: 'reported', reviewed_by: { id: '99', name: 'Carla Conformité' }, review_reason: 'Fonds d\'origine inconnue', reviewed_at: '2026-11-20T09:00:00+00:00' }) } };
      },
    });

    renderApp('/admin/aml/12');

    expect(await screen.findByRole('heading', { name: 'Retrait sans jeu · alerte n° 12' })).toBeInTheDocument();
    expect(screen.getByText(/n'ont été misés que pour/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Grand livre du joueur' })).toHaveAttribute('href', '/admin/finance?user_id=41');

    const report = screen.getByRole('form', { name: 'Signaler l\'alerte' });
    const submit = within(report).getByRole('button', { name: 'Signaler' });
    expect(submit).toBeDisabled(); // motif obligatoire
    await userEvent.type(within(report).getByLabelText('Motif (obligatoire)'), 'Fonds d\'origine inconnue');
    await userEvent.click(submit);

    expect(await screen.findByRole('heading', { name: 'Alerte signalée' })).toBeInTheDocument();
    expect(screen.getByText('Fonds d\'origine inconnue')).toBeInTheDocument();
    expect(JSON.parse(String(post?.body))).toEqual({ decision: 'reported', reason: 'Fonds d\'origine inconnue' });
    expect(headers(post)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('classe une alerte avec un motif', async () => {
    let post: RequestInit | undefined;
    mockApi({
      'GET /me': me(['aml.view', 'aml.review']),
      'GET /admin/aml/alerts/12': { status: 200, body: { data: alert() } },
      'POST /admin/aml/alerts/12/review': (init) => {
        post = init;
        return { status: 200, body: { data: alert({ status: 'dismissed', reviewed_by: { id: '99', name: 'Carla Conformité' }, review_reason: 'Justificatif de vente fourni', reviewed_at: '2026-11-20T09:00:00+00:00' }) } };
      },
    });

    renderApp('/admin/aml/12');

    const dismiss = await screen.findByRole('form', { name: 'Classer l\'alerte' });
    await userEvent.type(within(dismiss).getByLabelText('Motif (obligatoire)'), 'Justificatif de vente fourni');
    await userEvent.click(within(dismiss).getByRole('button', { name: 'Classer' }));

    expect(await screen.findByRole('heading', { name: 'Alerte classée' })).toBeInTheDocument();
    expect(JSON.parse(String(post?.body))).toEqual({ decision: 'dismissed', reason: 'Justificatif de vente fourni' });
  });

  it('affiche le refus du serveur quand l\'alerte a déjà été traitée', async () => {
    mockApi({
      'GET /me': me(['aml.view', 'aml.review']),
      'GET /admin/aml/alerts/12': { status: 200, body: { data: alert() } },
      'POST /admin/aml/alerts/12/review': { status: 409, body: { error: { code: 'alert_not_open', message: 'Cette alerte a déjà été traitée.', details: { status: 'dismissed' } } } },
    });

    renderApp('/admin/aml/12');

    const dismiss = await screen.findByRole('form', { name: 'Classer l\'alerte' });
    await userEvent.type(within(dismiss).getByLabelText('Motif (obligatoire)'), 'Justificatif de vente fourni');
    await userEvent.click(within(dismiss).getByRole('button', { name: 'Classer' }));

    expect(await screen.findByText('Cette alerte a déjà été traitée.')).toBeInTheDocument();
  });

  it('consultation seule sans aml.review, et sans lien vers le grand livre sans ledger.view', async () => {
    mockApi({ 'GET /me': me(['aml.view']), 'GET /admin/aml/alerts/12': { status: 200, body: { data: alert() } } });

    renderApp('/admin/aml/12');

    expect(await screen.findByText(/réservée aux administrateurs habilités/)).toBeInTheDocument();
    expect(screen.queryByRole('form', { name: 'Classer l\'alerte' })).toBeNull();
    expect(screen.queryByRole('form', { name: 'Signaler l\'alerte' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Grand livre du joueur' })).toBeNull();
  });

  it('jamais de décision sur une alerte de son propre compte', async () => {
    mockApi({ 'GET /me': me(['aml.view', 'aml.review'], '41'), 'GET /admin/aml/alerts/12': { status: 200, body: { data: alert() } } });

    renderApp('/admin/aml/12');

    expect(await screen.findByText(/concerne votre propre compte/)).toBeInTheDocument();
    expect(screen.queryByRole('form', { name: 'Classer l\'alerte' })).toBeNull();
    expect(screen.queryByRole('form', { name: 'Signaler l\'alerte' })).toBeNull();
  });

  it('montre la décision d\'une alerte déjà traitée, sans boutons', async () => {
    mockApi({
      'GET /me': me(['aml.view', 'aml.review']),
      'GET /admin/aml/alerts/9': { status: 200, body: { data: alert({ id: '9', status: 'dismissed', reviewed_by: { id: '7', name: 'Luc Conformité' }, review_reason: 'Vente d\'un terrain', reviewed_at: '2026-11-17T14:00:00+00:00' }) } },
    });

    renderApp('/admin/aml/9');

    expect(await screen.findByRole('heading', { name: 'Alerte classée' })).toBeInTheDocument();
    expect(screen.getByText('Luc Conformité')).toBeInTheDocument();
    expect(screen.getByText('Vente d\'un terrain')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Classer' })).toBeNull();
  });
});
