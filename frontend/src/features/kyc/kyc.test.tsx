import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, player, renderApp } from '../../test/renderApp';

afterEach(() => vi.unstubAllGlobals());

const htg = (minor: number) => ({ minor, currency: 'HTG' });

const kycState = (status: string, latest: Record<string, unknown> | null = null) => ({
  status: 200,
  body: { data: { status, verified_at: status === 'approved' ? '2026-11-10T15:00:00+00:00' : null, deposit_threshold: htg(10_000_00), latest_submission: latest } },
});

const submission = (status: string, overrides: Record<string, unknown> = {}) => ({
  id: '3',
  document_type: 'cin',
  status,
  submitted_at: '2026-11-12T10:00:00+00:00',
  decided_at: status === 'pending' ? null : '2026-11-13T10:00:00+00:00',
  rejection_reason: null,
  ...overrides,
});

const base = {
  'GET /me': { status: 200, body: { data: { ...player, kyc_status: 'none' } } },
  'GET /wallet': { status: 200, body: { data: { currency: 'HTG', balance: htg(0), status: 'active', simulated: false } } },
};

const image = (name: string, bytes = 2048) => new File([new Uint8Array(bytes)], name, { type: 'image/jpeg' });

describe('Vérification d\'identité du joueur (V2-C03)', () => {
  it('envoie la pièce (recto, verso) et le selfie, puis affiche le dossier en cours d\'examen', async () => {
    let sent: FormData | null = null;
    mockApi({
      ...base,
      'GET /me/kyc': () => (sent ? kycState('pending', submission('pending')) : kycState('none')),
      'POST /me/kyc': (init) => {
        sent = init?.body as FormData;
        return kycState('pending', submission('pending'));
      },
    });
    renderApp('/verification');

    expect(await screen.findByText(/déposer plus de/)).toHaveTextContent('10 000,00');
    await userEvent.click(screen.getByRole('button', { name: 'Envoyer mon dossier' }));
    expect(await screen.findByText('Ajoutez la photo de votre pièce.')).toBeInTheDocument();
    expect(screen.getByText('Ajoutez le verso de votre pièce.')).toBeInTheDocument();
    expect(sent).toBeNull();

    await userEvent.upload(screen.getByLabelText('Recto de la pièce'), image('recto.jpg'));
    await userEvent.upload(screen.getByLabelText('Verso de la pièce'), image('verso.jpg'));
    await userEvent.upload(screen.getByLabelText('Selfie'), image('selfie.jpg'));
    expect(screen.getByText('recto.jpg')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Envoyer mon dossier' }));

    expect(await screen.findByText("Dossier en cours d'examen")).toBeInTheDocument();
    const form = sent as unknown as FormData;
    expect(form.get('document_type')).toBe('cin');
    expect((form.get('front') as File).name).toBe('recto.jpg');
    expect((form.get('back') as File).name).toBe('verso.jpg');
    expect((form.get('selfie') as File).name).toBe('selfie.jpg');
  });

  it('passeport : pas de verso demandé ni envoyé', async () => {
    let sent: FormData | null = null;
    mockApi({
      ...base,
      'GET /me/kyc': kycState('none'),
      'POST /me/kyc': (init) => { sent = init?.body as FormData; return kycState('pending', submission('pending', { document_type: 'passport' })); },
    });
    renderApp('/verification');

    await userEvent.click(await screen.findByLabelText(/Passeport/));
    expect(screen.queryByLabelText('Verso de la pièce')).not.toBeInTheDocument();
    await userEvent.upload(screen.getByLabelText('Photo de la pièce'), image('passeport.jpg'));
    await userEvent.upload(screen.getByLabelText('Selfie'), image('selfie.jpg'));
    await userEvent.click(screen.getByRole('button', { name: 'Envoyer mon dossier' }));

    await waitFor(() => expect(sent).not.toBeNull());
    const form = sent as unknown as FormData;
    expect(form.get('document_type')).toBe('passport');
    expect(form.has('back')).toBe(false);
  });

  it('refuse un fichier de plus de 5 Mo avant l\'envoi', async () => {
    const fetchMock = mockApi({ ...base, 'GET /me/kyc': kycState('none') });
    renderApp('/verification');

    await userEvent.click(await screen.findByLabelText(/Passeport/));
    await userEvent.upload(screen.getByLabelText('Photo de la pièce'), image('lourd.jpg', 6 * 1024 * 1024));
    await userEvent.upload(screen.getByLabelText('Selfie'), image('selfie.jpg'));
    expect(screen.getByText('Ce fichier dépasse 5 Mo.')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Envoyer mon dossier' }));

    expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false);
  });

  it('dossier refusé : montre le motif et permet d\'en envoyer un nouveau', async () => {
    mockApi({ ...base, 'GET /me/kyc': kycState('rejected', submission('rejected', { rejection_reason: 'Photo floue, renvoyez une photo nette.' })) });
    renderApp('/verification');

    expect(await screen.findByText('Dossier refusé')).toBeInTheDocument();
    expect(screen.getByText(/Photo floue, renvoyez une photo nette/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Envoyer mon dossier' })).toBeInTheDocument();
  });

  it('identité vérifiée : aucun formulaire', async () => {
    mockApi({ ...base, 'GET /me/kyc': kycState('approved', submission('approved')) });
    renderApp('/verification');

    expect(await screen.findByText('Identité vérifiée')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Envoyer mon dossier' })).not.toBeInTheDocument();
  });

  it('la rubrique figure dans le menu de l\'espace joueur', async () => {
    mockApi({ ...base, 'GET /me/kyc': kycState('none') });
    renderApp('/verification');

    expect(await screen.findByRole('link', { name: "Vérification d'identité" })).toHaveAttribute('href', '/verification');
  });
});
