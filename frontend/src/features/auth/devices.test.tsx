import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { guest, mockApi, player, renderApp } from '../../test/renderApp';
import type { DeviceSession } from './api/sessions';

afterEach(() => vi.unstubAllGlobals());

const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-A155F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36';
const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';

const current = { id: 'aaa', ip_address: '190.115.1.10', user_agent: CHROME_WINDOWS, last_active_at: '2026-11-10T15:00:00+00:00', current: true };
const android = { id: 'bbb', ip_address: '190.115.1.20', user_agent: CHROME_ANDROID, last_active_at: '2026-11-09T18:00:00+00:00', current: false };
const iphone = { id: 'ccc', ip_address: null, user_agent: SAFARI_IPHONE, last_active_at: '2026-11-08T18:00:00+00:00', current: false };

const base = {
  'GET /me': { status: 200, body: { data: player } },
  'GET /wallet': { status: 200, body: { data: { currency: 'HTG', balance: { minor: 1_000_00, currency: 'HTG' }, status: 'active', simulated: false } } },
};

/** Faux serveur : la liste suit les déconnexions, comme la table des sessions. */
function sessionsApi(initial: DeviceSession[]) {
  let sessions = [...initial];
  const calls: string[] = [];

  mockApi({
    ...base,
    'GET /me/sessions': () => ({ status: 200, body: { data: sessions } }),
    'DELETE /me/sessions': () => {
      calls.push('DELETE /me/sessions');
      sessions = sessions.filter((s) => s.current);
      return { status: 204 };
    },
    ...Object.fromEntries(initial.map((s) => [`DELETE /me/sessions/${s.id}`, () => {
      calls.push(`DELETE /me/sessions/${s.id}`);
      if (!sessions.some((x) => x.id === s.id)) return { status: 404, body: { error: { code: 'not_found', message: 'Appareil introuvable.' } } };
      sessions = sessions.filter((x) => x.id !== s.id);
      return { status: 204 };
    }])),
  });

  return { calls, drop: (id: string) => { sessions = sessions.filter((s) => s.id !== id); } };
}

const rows = () => screen.getAllByRole('listitem').filter((li) => li.classList.contains('device'));

describe('appareils connectés', () => {
  it('liste les appareils, celui en cours d\'abord et sans bouton de déconnexion', async () => {
    sessionsApi([current, android, iphone]);
    renderApp('/devices');

    await screen.findByRole('heading', { name: 'Appareils connectés', level: 1 });
    await waitFor(() => expect(rows()).toHaveLength(3));
    const [first, second, third] = rows();

    expect(first).toHaveTextContent('Chrome sur Windows');
    expect(first).toHaveTextContent('Cet appareil');
    expect(first).toHaveTextContent('Actif maintenant · Adresse IP 190.115.1.10');
    expect(within(first).queryByRole('button')).not.toBeInTheDocument();

    expect(second).toHaveTextContent('Chrome sur Android');
    expect(second).toHaveTextContent(/Dernière activité : 9 novembre 2026/);
    expect(within(second).getByRole('button', { name: 'Déconnecter Chrome sur Android' })).toBeInTheDocument();

    // Adresse IP inconnue : la ligne ne l'invente pas.
    expect(third).toHaveTextContent('Safari sur iPhone');
    expect(third).not.toHaveTextContent('Adresse IP');

    expect(within(screen.getByRole('navigation', { name: 'Rubriques du compte' })).getByRole('link', { name: /Appareils connectés/ })).toHaveAttribute('aria-current', 'page');
  });

  it('déconnecte un appareil puis relit la liste du serveur', async () => {
    const { calls } = sessionsApi([current, android, iphone]);
    renderApp('/devices');

    await userEvent.click(await screen.findByRole('button', { name: 'Déconnecter Chrome sur Android' }));

    expect(await screen.findByText('Appareil déconnecté.')).toBeInTheDocument();
    await waitFor(() => expect(rows()).toHaveLength(2));
    expect(screen.queryByText('Chrome sur Android')).not.toBeInTheDocument();
    expect(calls).toEqual(['DELETE /me/sessions/bbb']);
  });

  it('traite un appareil déjà déconnecté ailleurs comme une réussite', async () => {
    const { drop } = sessionsApi([current, android]);
    renderApp('/devices');

    const button = await screen.findByRole('button', { name: 'Déconnecter Chrome sur Android' });
    drop('bbb');
    await userEvent.click(button);

    expect(await screen.findByText('Appareil déconnecté.')).toBeInTheDocument();
    await waitFor(() => expect(rows()).toHaveLength(1));
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(screen.getByText("Aucun autre appareil n'est connecté à votre compte.")).toBeInTheDocument();
  });

  it('déconnecte tous les autres appareils après confirmation', async () => {
    const { calls } = sessionsApi([current, android, iphone]);
    renderApp('/devices');

    await userEvent.click(await screen.findByRole('button', { name: 'Déconnecter tous les autres appareils' }));
    expect(screen.getByText(/Les 2 autres appareils devront se reconnecter/)).toBeInTheDocument();
    expect(calls).toEqual([]);

    await userEvent.click(screen.getByRole('button', { name: 'Confirmer' }));

    expect(await screen.findByText('Tous les autres appareils sont déconnectés.')).toBeInTheDocument();
    await waitFor(() => expect(rows()).toHaveLength(1));
    expect(calls).toEqual(['DELETE /me/sessions']);
    expect(screen.queryByRole('button', { name: 'Déconnecter tous les autres appareils' })).not.toBeInTheDocument();
  });

  it('annule sans rien envoyer', async () => {
    const { calls } = sessionsApi([current, android]);
    renderApp('/devices');

    await userEvent.click(await screen.findByRole('button', { name: 'Déconnecter tous les autres appareils' }));
    expect(screen.getByText(/L'autre appareil devra se reconnecter/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Annuler' }));

    expect(screen.getByRole('button', { name: 'Déconnecter tous les autres appareils' })).toBeInTheDocument();
    expect(calls).toEqual([]);
  });

  it('affiche le refus du serveur et garde l\'appareil dans la liste', async () => {
    mockApi({
      ...base,
      'GET /me/sessions': { status: 200, body: { data: [current, android] } },
      'DELETE /me/sessions/bbb': { status: 500, body: { error: { code: 'server_error', message: 'Erreur interne.' } } },
    });
    renderApp('/devices');

    await userEvent.click(await screen.findByRole('button', { name: 'Déconnecter Chrome sur Android' }));

    expect(await screen.findByText('Erreur interne.')).toBeInTheDocument();
    expect(rows()).toHaveLength(2);
    expect(screen.queryByText('Appareil déconnecté.')).not.toBeInTheDocument();
  });

  it('est liée depuis « Mes informations » et réservée au joueur connecté', async () => {
    sessionsApi([current]);
    const router = renderApp('/account');
    await userEvent.click(await screen.findByRole('link', { name: 'Gérer' }));
    expect(await screen.findByRole('heading', { name: 'Appareils connectés', level: 1 })).toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/devices');
  });

  it('renvoie un visiteur vers la connexion', async () => {
    mockApi({ 'GET /me': guest });
    const router = renderApp('/devices');
    await waitFor(() => expect(router.state.location.pathname).toBe('/login'));
  });
});
