import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, player, renderApp } from '../test/renderApp';

const me = (permissions: string[]) => ({
  status: 200,
  body: {
    data: {
      ...player,
      id: '99',
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
  meta: { current_page: 1, per_page: 20, total: data.length, last_page: 1, from: 1, to: data.length },
});

const submission = (overrides: Record<string, unknown> = {}) => ({
  id: '8',
  user: { id: '7', name: 'Ana Joueuse', first_name: 'Ana', last_name: 'Pierre', birth_date: '1995-05-21', country: 'HT', kyc_status: 'pending' },
  document_type: 'cin',
  status: 'pending',
  files: [
    { id: '21', kind: 'front', mime_type: 'image/jpeg', size_bytes: 204_800 },
    { id: '22', kind: 'back', mime_type: 'image/jpeg', size_bytes: 198_000 },
    { id: '23', kind: 'selfie', mime_type: 'image/png', size_bytes: 350_000 },
  ],
  decided_by: null,
  decided_at: null,
  decision_reason: null,
  created_at: '2026-11-12T10:00:00+00:00',
  ...overrides,
});

beforeEach(() => {
  URL.createObjectURL = vi.fn(() => 'blob:kyc-test');
  URL.revokeObjectURL = vi.fn();
});
afterEach(() => vi.unstubAllGlobals());

describe('Revue des dossiers KYC (V2-C03)', () => {
  it('liste les dossiers à vérifier par défaut, avec le nom déclaré', async () => {
    const fetchMock = mockApi({
      'GET /me': me(['kyc.view', 'kyc.approve', 'kyc.reject']),
      'GET /admin/kyc': { status: 200, body: page([submission()]) },
    });
    renderApp('/admin/kyc');

    expect(await screen.findByRole('link', { name: 'Dossier n° 8' })).toHaveAttribute('href', '/admin/kyc/8');
    expect(screen.getByText('Ana Pierre')).toBeInTheDocument();
    expect(screen.getByText("Carte d'identification nationale (CIN)")).toBeInTheDocument();
    expect(screen.getByText('1 dossier à vérifier, du plus ancien au plus récent.')).toBeInTheDocument();
    const listCall = fetchMock.mock.calls.map(([url]) => new URL(String(url))).find((u) => u.pathname.endsWith('/admin/kyc'));
    expect(listCall?.searchParams.get('status')).toBe('pending');
    expect(screen.getByRole('link', { name: "Vérification d'identité" })).toBeInTheDocument();
  });

  it('affiche une pièce seulement sur demande, puis valide le dossier avec un motif', async () => {
    let approved: unknown = null;
    const fetchMock = mockApi({
      'GET /me': me(['kyc.view', 'kyc.approve', 'kyc.reject']),
      'GET /admin/kyc/8': { status: 200, body: { data: submission() } },
      'GET /admin/kyc/8/files/21': { status: 200, body: 'image' },
      'POST /admin/kyc/8/approve': (init) => {
        approved = JSON.parse(String(init?.body));
        return { status: 200, body: { data: submission({ status: 'approved', decided_by: { id: '99', name: 'Carla Conformité' }, decided_at: '2026-11-14T09:00:00+00:00', decision_reason: 'Pièce conforme au selfie' }) } };
      },
    });
    renderApp('/admin/kyc/8');

    expect(await screen.findByText('Dossier n° 8')).toBeInTheDocument();
    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/files/'))).toBe(false);

    await userEvent.click(screen.getByRole('button', { name: 'Afficher le recto' }));
    expect(await screen.findByRole('img', { name: 'Recto' })).toHaveAttribute('src', 'blob:kyc-test');
    const fileCalls = fetchMock.mock.calls.filter(([url]) => String(url).includes('/files/'));
    expect(fileCalls).toHaveLength(1);
    expect(fileCalls[0]![1]).toMatchObject({ credentials: 'include', cache: 'no-store' });

    const approveForm = screen.getByRole('form', { name: "Valider l'identité" });
    await userEvent.type(within(approveForm).getByLabelText('Motif (obligatoire)'), 'Pièce conforme au selfie');
    await userEvent.click(within(approveForm).getByRole('button', { name: 'Valider' }));

    expect(await screen.findByText('Identité validée')).toBeInTheDocument();
    expect(approved).toEqual({ reason: 'Pièce conforme au selfie' });
    const summary = screen.getByText('Identité validée').closest('aside')!;
    expect(within(summary as HTMLElement).getByText('Carla Conformité')).toBeInTheDocument();
    expect(within(summary as HTMLElement).getByText('Pièce conforme au selfie')).toBeInTheDocument();
  });

  it('sans kyc.reject ni kyc.approve : consultation seule', async () => {
    mockApi({
      'GET /me': me(['kyc.view']),
      'GET /admin/kyc/8': { status: 200, body: { data: submission() } },
    });
    renderApp('/admin/kyc/8');

    expect(await screen.findByText(/réservée aux administrateurs habilités/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Valider' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Refuser' })).not.toBeInTheDocument();
  });

  it('la section n\'apparaît pas sans la permission kyc.view', async () => {
    mockApi({ 'GET /me': me([]), 'GET /admin/dashboard': { status: 200, body: { data: {} } } });
    renderApp('/admin');

    await waitFor(() => expect(screen.queryByText('Carla Conformité')).not.toBeNull());
    expect(screen.queryByRole('link', { name: "Vérification d'identité" })).not.toBeInTheDocument();
  });
});
