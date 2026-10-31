import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api, ApiError } from './client';

/** Attend un échec et renvoie l'ApiError. */
const failure = (p: Promise<unknown>) => p.then(
  () => { throw new Error('La requête aurait dû échouer.'); },
  (e: unknown) => e as ApiError,
);

const json = (status: number, body: unknown) =>
  new Response(body === null ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

describe('api()', () => {
  const fetchMock = vi.fn<typeof fetch>();

  beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock);
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  });

  afterEach(() => {
    fetchMock.mockReset();
    vi.unstubAllGlobals();
  });

  it('renvoie le JSON et envoie les cookies', async () => {
    fetchMock.mockResolvedValueOnce(json(200, { data: { ok: true } }));

    await expect(api('/health')).resolves.toEqual({ data: { ok: true } });
    expect(fetchMock.mock.calls[0][1]?.credentials).toBe('include');
  });

  it('ajoute les paramètres de requête non vides', async () => {
    fetchMock.mockResolvedValueOnce(json(200, {}));

    await api('/wallet/transactions', { query: { page: 2, type: '', per_page: undefined } });

    expect(String(fetchMock.mock.calls[0][0])).toMatch(/\/wallet\/transactions\?page=2$/);
  });

  it("transforme le format d'erreur de l'API en ApiError", async () => {
    fetchMock.mockResolvedValueOnce(json(422, {
      error: { code: 'validation_failed', message: 'Les données envoyées sont invalides.', details: { email: ['Email obligatoire.'] } },
    }));

    const error = await failure(api('/auth/register'));

    expect(error).toBeInstanceOf(ApiError);
    expect(error.status).toBe(422);
    expect(error.code).toBe('validation_failed');
    expect(error.fieldErrors).toEqual({ email: 'Email obligatoire.' });
  });

  it('signale une panne réseau avec le code network_error', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));

    const error = await failure(api('/health'));

    expect(error.code).toBe('network_error');
    expect(error.status).toBe(0);
  });

  it("récupère le cookie CSRF avant une requête à effet et renvoie l'en-tête X-XSRF-TOKEN", async () => {
    fetchMock.mockImplementationOnce(async () => {
      document.cookie = 'XSRF-TOKEN=jeton%3D%3D';
      return new Response(null, { status: 204 });
    });
    fetchMock.mockResolvedValueOnce(json(200, { data: {} }));

    await api('/auth/login', { method: 'POST', body: { email: 'a@example.com' }, idempotencyKey: 'k-1' });

    expect(String(fetchMock.mock.calls[0][0])).toMatch(/\/sanctum\/csrf-cookie$/);
    const headers = fetchMock.mock.calls[1][1]?.headers as Record<string, string>;
    expect(headers['X-XSRF-TOKEN']).toBe('jeton==');
    expect(headers['Idempotency-Key']).toBe('k-1');
  });

  it('renouvelle le jeton CSRF et rejoue une seule fois sur 419', async () => {
    document.cookie = 'XSRF-TOKEN=ancien';
    fetchMock
      .mockResolvedValueOnce(json(419, { error: { code: 'csrf_token_mismatch', message: 'CSRF token mismatch.' } }))
      .mockImplementationOnce(async () => {
        document.cookie = 'XSRF-TOKEN=nouveau';
        return new Response(null, { status: 204 });
      })
      .mockResolvedValueOnce(json(200, { data: { ok: true } }));

    await expect(api('/auth/logout', { method: 'POST' })).resolves.toEqual({ data: { ok: true } });

    expect(fetchMock).toHaveBeenCalledTimes(3);
    expect((fetchMock.mock.calls[2][1]?.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('nouveau');
  });

  it('accepte une réponse 204 sans contenu', async () => {
    document.cookie = 'XSRF-TOKEN=x';
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 }));

    await expect(api('/auth/logout', { method: 'POST' })).resolves.toBeNull();
  });
});
