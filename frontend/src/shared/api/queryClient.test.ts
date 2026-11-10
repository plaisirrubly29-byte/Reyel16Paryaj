import { describe, expect, it } from 'vitest';
import { ApiError } from './client';
import { createQueryClient, SESSION_KEY } from './queryClient';

const error = (status: number, code: string) => new ApiError(status, { error: { code, message: code } });
const keys = (client: ReturnType<typeof createQueryClient>) => client.getQueryCache().findAll().map((q) => q.queryKey.join('/')).sort();

/** Session ouverte par `id`, avec des données de son compte en cache. */
function sessionOf(id: string) {
  const client = createQueryClient();
  client.setQueryData(SESSION_KEY, { id });
  client.setQueryData(['wallet'], { balance: 100 });
  client.setQueryData(['bets', { page: 1 }], []);
  client.setQueryData(['me', 'sessions'], []);
  return client;
}

describe('données du compte et session (P8-02, F11)', () => {
  it('oublie les données du compte quand la session se ferme', () => {
    const client = sessionOf('1');

    client.setQueryData(SESSION_KEY, null);

    expect(keys(client)).toEqual(['me']);
    expect(client.getQueryData(SESSION_KEY)).toBeNull();
  });

  it('oublie les données du compte précédent quand un autre compte se connecte', () => {
    const client = sessionOf('1');

    client.setQueryData(SESSION_KEY, { id: '2' });

    expect(keys(client)).toEqual(['me']);
    expect(client.getQueryData(SESSION_KEY)).toEqual({ id: '2' });
  });

  it('garde les données quand la session est relue pour le même compte, ou ouverte par un visiteur', () => {
    const client = sessionOf('1');
    client.setQueryData(SESSION_KEY, { id: '1', name: 'Ana' });
    expect(keys(client)).toHaveLength(4);

    // Catalogue chargé par un visiteur, qui se connecte ensuite.
    const visitor = createQueryClient();
    visitor.setQueryData(SESSION_KEY, null);
    visitor.setQueryData(['events'], []);
    visitor.setQueryData(SESSION_KEY, { id: '1' });
    expect(keys(visitor)).toEqual(['events', 'me']);
  });

  it('ferme la session quand le serveur répond 401 à une requête du compte', async () => {
    const client = sessionOf('1');

    await client.fetchQuery({ queryKey: ['transactions'], queryFn: () => Promise.reject(error(401, 'unauthenticated')) }).catch(() => undefined);

    expect(client.getQueryData(SESSION_KEY)).toBeNull();
    expect(client.getQueryData(['wallet'])).toBeUndefined();
  });

  it('ferme la session quand le serveur répond 401 à une action', async () => {
    const client = sessionOf('1');

    await client.getMutationCache().build(client, { mutationFn: () => Promise.reject(error(401, 'unauthenticated')) }).execute(undefined).catch(() => undefined);

    expect(client.getQueryData(SESSION_KEY)).toBeNull();
    expect(client.getQueryData(['wallet'])).toBeUndefined();
  });

  it('garde la session sur un refus qui n\'est pas une fin de session', async () => {
    const client = sessionOf('1');

    await client.fetchQuery({ queryKey: ['admin'], queryFn: () => Promise.reject(error(403, 'forbidden')) }).catch(() => undefined);
    await client.fetchQuery({ queryKey: ['x'], queryFn: () => Promise.reject(error(500, 'server_error')), retry: false }).catch(() => undefined);

    expect(client.getQueryData(SESSION_KEY)).toEqual({ id: '1' });
    expect(client.getQueryData(['wallet'])).toEqual({ balance: 100 });
  });
});
