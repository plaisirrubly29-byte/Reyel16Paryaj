import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { guest, mockApi, player, renderApp } from '../../test/renderApp';
import { LEGAL_VERSION } from '../legal/legal';
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

  it('connecte le joueur avec son numéro de téléphone, même collé avec l’indicatif', async () => {
    const fetchMock = mockApi({
      'GET /me': guest,
      'POST /auth/login': { status: 422, body: { error: { code: 'invalid_credentials', message: 'Identifiants invalides.' } } },
    });
    renderApp('/login');
    const phone = await screen.findByLabelText('Numéro de téléphone');
    const bodies = () => fetchMock.mock.calls
      .filter(([url]) => String(url).endsWith('/auth/login'))
      .map(([, init]) => JSON.parse(String(init?.body)));

    expect(screen.queryByLabelText('Téléphone ou email')).toBeNull();
    await userEvent.click(phone);
    await userEvent.paste('+509 34-12 34 56');
    expect(phone).toHaveValue('34123456');
    await userEvent.type(screen.getByLabelText('Mot de passe'), 'motdepasse123');
    await userEvent.click(screen.getByRole('button', { name: 'Se connecter' }));
    await waitFor(() => expect(bodies()).toHaveLength(1));
    expect(bodies()[0]).toEqual({ phone: '34123456', password: 'motdepasse123' });
  });

  it('affiche ou masque le mot de passe', async () => {
    mockApi({ 'GET /me': guest });
    renderApp('/login');
    const password = await screen.findByLabelText('Mot de passe');

    expect(password).toHaveAttribute('type', 'password');
    await userEvent.click(screen.getByRole('button', { name: 'Afficher le mot de passe' }));
    expect(password).toHaveAttribute('type', 'text');
    await userEvent.click(screen.getByRole('button', { name: 'Masquer le mot de passe' }));
    expect(password).toHaveAttribute('type', 'password');
  });

  it('garde la connexion par email pour l’équipe (/admin/login)', async () => {
    const fetchMock = mockApi({
      'GET /me': guest,
      'POST /auth/login': { status: 422, body: { error: { code: 'invalid_credentials', message: 'Identifiants invalides.' } } },
    });
    renderApp('/admin/login');
    const bodies = () => fetchMock.mock.calls
      .filter(([url]) => String(url).endsWith('/auth/login'))
      .map(([, init]) => JSON.parse(String(init?.body)));

    await userEvent.type(await screen.findByLabelText('Téléphone ou email'), ' Chef@Example.com ');
    await userEvent.type(screen.getByLabelText('Mot de passe'), 'motdepasse123');
    await userEvent.click(screen.getByRole('button', { name: 'Se connecter' }));
    await waitFor(() => expect(bodies()).toHaveLength(1));
    expect(bodies()[0]).toEqual({ email: 'Chef@Example.com', password: 'motdepasse123' });
  });

  it('ne suit jamais une redirection vers un autre site', () => {
    expect(safeRedirect('/bets')).toBe('/bets');
    expect(safeRedirect('//evil.example')).toBe('/');
    expect(safeRedirect('https://evil.example')).toBe('/');
    expect(safeRedirect('/\\evil.example')).toBe('/');
    expect(safeRedirect(null, '/admin')).toBe('/admin');
  });
});

// Formulaire complet saisi touche par touche : plus long que le délai par défaut quand toute la suite tourne.
describe('inscription', { timeout: 15_000 }, () => {
  it('bêta fermée : le champ du code d\'invitation apparaît quand le serveur l\'exige, puis le code est envoyé', async () => {
    const bodies: Record<string, unknown>[] = [];
    const fetchMock = mockApi({
      'GET /me': guest,
      'POST /auth/register': (init) => {
        const body = JSON.parse(String(init?.body));
        bodies.push(body);
        return body.invite_code === 'BETA2026'
          ? { status: 201, body: { data: player } }
          : { status: 422, body: { error: { code: 'validation_failed', message: 'Invalide.', details: { invite_code: ["Les inscriptions se font sur invitation pendant la bêta : saisissez votre code d'invitation."] } } } };
      },
    });
    renderApp('/register');

    expect(screen.queryByLabelText("Code d'invitation")).toBeNull();
    await userEvent.type(await screen.findByLabelText('Nom'), 'Ana Joueuse');
    await userEvent.type(screen.getByLabelText('Numéro de téléphone'), '34123456');
    await userEvent.type(screen.getByLabelText('Date de naissance'), '21051995');
    await userEvent.type(screen.getByLabelText('Mot de passe'), 'motdepasse123');
    await userEvent.type(screen.getByLabelText('Confirmer le mot de passe'), 'motdepasse123');
    await userEvent.click(screen.getByRole('checkbox', { name: /J'accepte les conditions/ }));
    await userEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }));

    const invite = await screen.findByLabelText("Code d'invitation");
    expect(invite).toHaveAccessibleDescription(/sur invitation pendant la bêta/);
    await userEvent.type(invite, 'beta2026');
    await userEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }));

    await waitFor(() => expect(bodies).toHaveLength(2));
    expect(bodies[0]).not.toHaveProperty('invite_code');
    expect(bodies[1].invite_code).toBe('BETA2026');
    expect(fetchMock).toHaveBeenCalled();
  });

  it('le lien ?invitation=CODE affiche et préremplit le code d\'invitation', async () => {
    mockApi({ 'GET /me': guest });
    renderApp('/register?invitation=beta-2026');
    expect(await screen.findByLabelText("Code d'invitation")).toHaveValue('BETA2026');
  });

  it('affiche les erreurs de validation sous chaque champ', async () => {
    mockApi({
      'GET /me': guest,
      'POST /auth/register': {
        status: 422,
        body: {
          error: {
            code: 'validation_failed',
            message: 'Les données envoyées sont invalides.',
            // Le serveur reste l'autorité sur l'âge minimum (IDENTITY_MINIMUM_AGE peut dépasser 18).
            details: { birth_date: ['Vous devez avoir au moins 21 ans pour ouvrir un compte.'], phone: ['Ce numéro est déjà utilisé.'] },
          },
        },
      },
    });
    renderApp('/register');

    await userEvent.type(await screen.findByLabelText('Date de naissance'), '21051995');
    await userEvent.click(screen.getByRole('checkbox', { name: /J'accepte les conditions/ }));
    await userEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }));

    expect(await screen.findByText('Vous devez avoir au moins 21 ans pour ouvrir un compte.')).toBeInTheDocument();
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
    await userEvent.type(screen.getByLabelText('Date de naissance'), '21051995');
    await userEvent.type(screen.getByLabelText('Mot de passe'), 'motdepasse123');
    await userEvent.type(screen.getByLabelText('Confirmer le mot de passe'), 'motdepasse123');
    await userEvent.click(screen.getByRole('checkbox', { name: /J'accepte les conditions/ }));
    await userEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }));

    const registerCall = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/auth/register'));
    const body = JSON.parse(String(registerCall?.[1]?.body));
    expect(body.phone).toBe('34123456');
    expect(body).not.toHaveProperty('email');
    expect(body).not.toHaveProperty('referral_code'); // aucun code parrain : rien n'est envoyé
  });

  it('date de naissance tapée en JJ/MM/AAAA : « / » ajoutés tout seuls, clavier numérique, envoi en AAAA-MM-JJ', async () => {
    const fetchMock = mockApi({
      'GET /me': guest,
      'POST /auth/register': { status: 201, body: { data: player } },
    });
    renderApp('/register');

    const birth = await screen.findByLabelText('Date de naissance');
    expect(birth).toHaveAttribute('type', 'text');
    expect(birth).toHaveAttribute('inputmode', 'numeric');
    expect(birth).toHaveAttribute('placeholder', 'JJ/MM/AAAA');
    await userEvent.type(birth, '2105');
    expect(birth).toHaveValue('21/05/');
    await userEvent.type(birth, '1998');
    expect(birth).toHaveValue('21/05/1998');

    await userEvent.type(screen.getByLabelText('Nom'), 'Ana Joueuse');
    await userEvent.type(screen.getByLabelText('Numéro de téléphone'), '34123456');
    await userEvent.type(screen.getByLabelText('Mot de passe'), 'motdepasse123');
    await userEvent.type(screen.getByLabelText('Confirmer le mot de passe'), 'motdepasse123');
    await userEvent.click(screen.getByRole('checkbox', { name: /J'accepte les conditions/ }));
    await userEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }));

    await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/auth/register'))).toBe(true));
    const registerCall = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/auth/register'));
    expect(JSON.parse(String(registerCall?.[1]?.body)).birth_date).toBe('1998-05-21');
  });

  it('refuse une date qui n\'existe pas ou un âge sous le minimum, sans rien envoyer', async () => {
    const fetchMock = mockApi({ 'GET /me': guest, 'POST /auth/register': { status: 201, body: { data: player } } });
    renderApp('/register');

    const birth = await screen.findByLabelText('Date de naissance');
    await userEvent.type(birth, '31022000');
    await userEvent.click(screen.getByLabelText('Mot de passe')); // quitter le champ suffit pour voir l'erreur
    expect(birth).toHaveAccessibleDescription("Le 31/02/2000 n'existe pas : vérifiez le jour et le mois.");

    // Corriger efface le message ; un joueur trop jeune est arrêté avant l'envoi.
    await userEvent.clear(birth);
    expect(birth).not.toHaveAccessibleDescription(/n'existe pas/);
    const year = new Date().getFullYear() - 10;
    await userEvent.type(birth, `0101${year}`);
    await userEvent.click(screen.getByRole('checkbox', { name: /J'accepte les conditions/ }));
    await userEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }));

    expect(birth).toHaveAccessibleDescription('Vous devez avoir au moins 18 ans pour ouvrir un compte.');
    expect(birth).toHaveFocus();
    expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/auth/register'))).toBe(false);
  });

  it('ne demande ni le pays (Haïti) ni « 18 ans ou plus » : l\'âge vient de la date de naissance', async () => {
    const fetchMock = mockApi({
      'GET /me': guest,
      'POST /auth/register': { status: 201, body: { data: player } },
    });
    renderApp('/register');

    await screen.findByLabelText('Nom');
    expect(screen.queryByRole('combobox', { name: 'Pays' })).not.toBeInTheDocument();
    expect(screen.queryByText(/18 ans ou plus/)).not.toBeInTheDocument();

    await userEvent.type(screen.getByLabelText('Nom'), 'Ana Joueuse');
    await userEvent.type(screen.getByLabelText('Numéro de téléphone'), '34123456');
    await userEvent.type(screen.getByLabelText('Date de naissance'), '21051995');
    await userEvent.type(screen.getByLabelText('Mot de passe'), 'motdepasse123');
    await userEvent.type(screen.getByLabelText('Confirmer le mot de passe'), 'motdepasse123');
    await userEvent.click(screen.getByRole('checkbox', { name: /J'accepte les conditions/ }));
    await userEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }));

    const registerCall = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/auth/register'));
    expect(JSON.parse(String(registerCall?.[1]?.body)).country).toBe('HT');
  });

  it('exige la case des conditions et envoie la version acceptée', async () => {
    const fetchMock = mockApi({
      'GET /me': guest,
      'POST /auth/register': { status: 201, body: { data: player } },
    });
    renderApp('/register');

    // Sans la case : message sous la case, rien n'est envoyé.
    await userEvent.click(await screen.findByRole('button', { name: 'Créer mon compte' }));
    const terms = screen.getByRole('checkbox', { name: /J'accepte les conditions/ });
    expect(terms).toHaveAccessibleDescription('Cochez la case pour accepter les conditions et créer votre compte.');
    expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/auth/register'))).toBe(false);

    // Les textes s'ouvrent dans un nouvel onglet : le formulaire reste rempli.
    expect(screen.getByRole('link', { name: "conditions d'utilisation" })).toHaveAttribute('target', '_blank');
    expect(screen.getByRole('link', { name: 'règlement des paris' })).toHaveAttribute('href', '/reglement');

    // La date vide est signalée en même temps que la case.
    const birth = screen.getByLabelText('Date de naissance');
    expect(birth).toHaveAccessibleDescription('Saisissez votre date de naissance (JJ/MM/AAAA).');
    await userEvent.type(birth, '21051995');

    await userEvent.click(terms);
    expect(screen.queryByText('Cochez la case pour accepter les conditions et créer votre compte.')).toBeNull();
    await userEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }));

    const registerCall = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/auth/register'));
    const body = JSON.parse(String(registerCall?.[1]?.body));
    expect(body.terms_accepted).toBe(true);
    expect(body.terms_version).toBe(LEGAL_VERSION);
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
