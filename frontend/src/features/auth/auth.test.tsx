import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { guest, mockApi, player, renderApp } from '../../test/renderApp';
import { safeRedirect } from './api/auth';

afterEach(() => vi.unstubAllGlobals());

describe('routes protégées', () => {
  it('redirige un visiteur vers la connexion en gardant la page demandée', async () => {
    mockApi({ 'GET /me': guest });

    const router = renderApp('/wallet');

    await screen.findByRole('heading', { name: 'Connexion' });
    expect(router.state.location.pathname).toBe('/login');
    expect(router.state.location.search).toBe('?redirect=%2Fwallet');
  });

  it('affiche la page à un joueur connecté', async () => {
    mockApi({ 'GET /me': { status: 200, body: { data: player } } });

    renderApp('/account');

    expect(await screen.findByRole('heading', { name: 'Mon compte' })).toBeInTheDocument();
    expect(screen.getByText('+509 34 12 34 56')).toBeInTheDocument();
  });
});

describe('connexion', () => {
  it('affiche le message du serveur pour des identifiants invalides', async () => {
    mockApi({
      'GET /me': guest,
      'POST /auth/login': { status: 422, body: { error: { code: 'invalid_credentials', message: 'Identifiants invalides.' } } },
    });
    renderApp('/login');

    await userEvent.type(await screen.findByLabelText('Numéro de téléphone'), '34123456');
    await userEvent.type(screen.getByLabelText('Mot de passe'), 'mauvais');
    await userEvent.click(screen.getByRole('button', { name: 'Se connecter' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Identifiants invalides.');
  });

  it('ramène le joueur vers la page demandée après connexion', async () => {
    let loggedIn = false;
    const fetchMock = mockApi({
      'GET /me': () => (loggedIn ? { status: 200, body: { data: player } } : guest),
      'POST /auth/login': () => { loggedIn = true; return { status: 200, body: { data: player } }; },
    });
    const router = renderApp('/login?redirect=%2Fwallet');

    await userEvent.type(await screen.findByLabelText('Numéro de téléphone'), '34123456');
    await userEvent.type(screen.getByLabelText('Mot de passe'), 'motdepasse123');
    await userEvent.click(screen.getByRole('button', { name: 'Se connecter' }));

    await waitFor(() => expect(router.state.location.pathname).toBe('/wallet'));
    expect(screen.getByRole('link', { name: 'Mon compte (Ana Joueuse)' })).toBeInTheDocument();

    // Requête à effet : jeton CSRF envoyé.
    const loginCall = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/auth/login'));
    expect((loginCall?.[1]?.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('test');
  });

  it('affiche l\'indicatif +509 et ne garde que les chiffres saisis', async () => {
    mockApi({ 'GET /me': guest });
    renderApp('/login');

    const phone = await screen.findByLabelText('Numéro de téléphone');
    expect(screen.getByText('+509')).toBeInTheDocument();

    await userEvent.type(phone, '34-12 ab 34 56');
    expect(phone).toHaveValue('34123456');
  });

  it('ne suit jamais une redirection vers un autre site', () => {
    expect(safeRedirect('/bets')).toBe('/bets');
    expect(safeRedirect('//evil.example')).toBe('/');
    expect(safeRedirect('https://evil.example')).toBe('/');
    expect(safeRedirect('/\\evil.example')).toBe('/');
    expect(safeRedirect(null, '/admin')).toBe('/admin');
  });
});

describe('inscription', () => {
  it('affiche les erreurs de validation sous chaque champ', async () => {
    mockApi({
      'GET /me': guest,
      'POST /auth/register': {
        status: 422,
        body: {
          error: {
            code: 'validation_failed',
            message: 'Les données envoyées sont invalides.',
            details: { birth_date: ['Vous devez avoir au moins 18 ans pour ouvrir un compte.'], phone: ['Ce numéro est déjà utilisé.'] },
          },
        },
      },
    });
    renderApp('/register');

    await userEvent.click(await screen.findByRole('button', { name: 'Créer mon compte' }));

    expect(await screen.findByText('Vous devez avoir au moins 18 ans pour ouvrir un compte.')).toBeInTheDocument();
    expect(screen.getByLabelText('Numéro de téléphone')).toHaveAccessibleDescription('Ce numéro est déjà utilisé.');
  });

  it('n\'envoie pas d\'email vide : il reste facultatif', async () => {
    const fetchMock = mockApi({
      'GET /me': guest,
      'POST /auth/register': { status: 201, body: { data: player } },
    });
    renderApp('/register');

    await userEvent.type(await screen.findByLabelText('Nom'), 'Ana Joueuse');
    await userEvent.type(screen.getByLabelText('Numéro de téléphone'), '34123456');
    await userEvent.type(screen.getByLabelText('Date de naissance'), '1995-05-21');
    await userEvent.type(screen.getByLabelText('Mot de passe'), 'motdepasse123');
    await userEvent.type(screen.getByLabelText('Confirmer le mot de passe'), 'motdepasse123');
    await userEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }));

    const registerCall = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/auth/register'));
    const body = JSON.parse(String(registerCall?.[1]?.body));
    expect(body.phone).toBe('34123456');
    expect(body).not.toHaveProperty('email');
  });
});

describe('déconnexion', () => {
  it('termine la session et revient à l\'accueil', async () => {
    let loggedIn = true;
    mockApi({
      'GET /me': () => (loggedIn ? { status: 200, body: { data: player } } : guest),
      'POST /auth/logout': () => { loggedIn = false; return { status: 204 }; },
      'GET /health': { status: 200, body: { status: 'ok', version: 'v1', time: '' } },
    });
    const router = renderApp('/account');

    await userEvent.click(await screen.findByRole('button', { name: 'Se déconnecter' }));

    await waitFor(() => expect(router.state.location.pathname).toBe('/'));
    expect(await screen.findByRole('link', { name: 'Connexion' })).toBeInTheDocument();
  });
});

describe('mot de passe oublié', () => {
  it('affiche le message neutre du serveur', async () => {
    mockApi({
      'GET /me': guest,
      'POST /auth/forgot-password': { status: 202, body: { data: { message: 'Si un compte correspond à cette adresse, un lien vient d\'être envoyé.' } } },
    });
    renderApp('/forgot-password');

    await userEvent.type(await screen.findByLabelText('Email'), 'qui@example.com');
    await userEvent.click(screen.getByRole('button', { name: 'Envoyer le lien' }));

    expect(await screen.findByRole('status')).toHaveTextContent('Si un compte correspond');
  });

  it('signale un lien de réinitialisation incomplet', async () => {
    mockApi({ 'GET /me': guest });
    renderApp('/reset-password?token=abc');

    expect(await screen.findByRole('heading', { name: 'Lien invalide' })).toBeInTheDocument();
  });

  it('propose un nouveau lien si le jeton a expiré', async () => {
    mockApi({
      'GET /me': guest,
      'POST /auth/reset-password': { status: 422, body: { error: { code: 'invalid_reset_token', message: 'Ce lien de réinitialisation est invalide ou a expiré.' } } },
    });
    renderApp('/reset-password?token=abc&email=ana%40example.com');

    await userEvent.type(await screen.findByLabelText('Nouveau mot de passe'), 'nouveaumdp456');
    await userEvent.type(screen.getByLabelText('Confirmer le mot de passe'), 'nouveaumdp456');
    await userEvent.click(screen.getByRole('button', { name: 'Modifier le mot de passe' }));

    expect(await screen.findByText('Lien expiré ou déjà utilisé')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Demander un nouveau lien' })).toBeInTheDocument();
  });
});
