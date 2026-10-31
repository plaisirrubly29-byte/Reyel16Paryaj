// Client HTTP unique vers l'API Laravel (/api/v1).
// Règle : le frontend affiche, le serveur décide (solde, cote acceptée, résultat).
//
// Authentification Sanctum en mode SPA : cookie de session + jeton CSRF.
// Avant la première requête à effet, on récupère le cookie XSRF-TOKEN (GET /sanctum/csrf-cookie),
// puis on renvoie sa valeur dans l'en-tête X-XSRF-TOKEN. Sur 419 (jeton expiré), on le renouvelle une fois.

export const API_URL: string = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api/v1';

/** Format d'erreur unique de l'API (docs/api/README.md). */
export type ApiErrorBody = {
  error: {
    code: string;
    message: string;
    details?: Record<string, unknown>;
  };
};

export class ApiError extends Error {
  readonly status: number;
  /** Identifiant stable (ex. "validation_failed", "odds_changed") : s'y fier plutôt qu'au message. */
  readonly code: string;
  readonly details: Record<string, unknown>;

  constructor(status: number, body: unknown) {
    const error = isApiErrorBody(body) ? body.error : null;
    super(error?.message ?? (status === 0 ? 'Serveur injoignable.' : `Erreur ${status}`));
    this.name = 'ApiError';
    this.status = status;
    this.code = error?.code ?? (status === 0 ? 'network_error' : 'http_error');
    this.details = error?.details ?? {};
  }

  /** Erreurs par champ pour un formulaire (code "validation_failed"). */
  get fieldErrors(): Record<string, string> {
    if (this.code !== 'validation_failed') return {};
    return Object.fromEntries(
      Object.entries(this.details).map(([field, messages]) => [field, Array.isArray(messages) ? String(messages[0]) : String(messages)]),
    );
  }
}

function isApiErrorBody(body: unknown): body is ApiErrorBody {
  return typeof body === 'object' && body !== null && 'error' in body
    && typeof (body as ApiErrorBody).error?.code === 'string';
}

type Method = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';

export type RequestOptions = {
  method?: Method;
  body?: unknown;
  /** Paramètres de requête (?page=2…) ; les valeurs vides sont ignorées. */
  query?: Record<string, string | number | undefined | null>;
  /** Obligatoire pour les opérations qui créent un effet (ex. POST /bets). Une clé par intention utilisateur. */
  idempotencyKey?: string;
  signal?: AbortSignal;
};

const SAFE_METHODS: Method[] = ['GET'];

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

let csrfRequest: Promise<void> | null = null;

/** Origine de l'API : absolue (http://localhost:8000/api/v1) ou relative (/api/v1, via le proxy de Vite). */
function apiBase(): URL {
  return new URL(API_URL, window.location.origin);
}

/** Récupère le cookie CSRF de Sanctum (servi hors du préfixe /api/v1). */
export function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readCookie('XSRF-TOKEN')) return Promise.resolve();
  csrfRequest ??= fetch(new URL('/sanctum/csrf-cookie', apiBase()), { credentials: 'include' })
    .then(() => undefined)
    .finally(() => { csrfRequest = null; });
  return csrfRequest;
}

function buildUrl(path: string, query?: RequestOptions['query']): string {
  const url = new URL(`${API_URL}${path}`, window.location.origin);
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value));
  }
  return url.toString();
}

export async function api<T>(path: string, options: RequestOptions = {}, retried = false): Promise<T> {
  const method = options.method ?? 'GET';
  const headers: Record<string, string> = { Accept: 'application/json' };

  if (options.body !== undefined) headers['Content-Type'] = 'application/json';
  if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey;

  if (!SAFE_METHODS.includes(method)) {
    await ensureCsrfCookie();
    const token = readCookie('XSRF-TOKEN');
    if (token) headers['X-XSRF-TOKEN'] = token;
  }

  let response: Response;
  try {
    response = await fetch(buildUrl(path, options.query), {
      method,
      headers,
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
      credentials: 'include',
      signal: options.signal,
    });
  } catch (e) {
    if (e instanceof DOMException && e.name === 'AbortError') throw e;
    throw new ApiError(0, null);
  }

  // Jeton CSRF expiré : on le renouvelle et on rejoue une seule fois (même clé d'idempotence).
  if (response.status === 419 && !retried) {
    await ensureCsrfCookie(true);
    return api<T>(path, options, true);
  }

  const data = response.status === 204 ? null : await response.json().catch(() => null);
  if (!response.ok) throw new ApiError(response.status, data);
  return data as T;
}

/**
 * POST qui renvoie un fichier (ex. export CSV) : déclenche son téléchargement dans le navigateur.
 * Mêmes règles que api() (cookie de session, jeton CSRF renouvelé une fois sur 419, ApiError en cas de refus).
 */
export async function downloadFile(path: string, body: unknown, retried = false): Promise<void> {
  await ensureCsrfCookie();
  const headers: Record<string, string> = { Accept: 'text/csv, application/json', 'Content-Type': 'application/json' };
  const token = readCookie('XSRF-TOKEN');
  if (token) headers['X-XSRF-TOKEN'] = token;

  let response: Response;
  try {
    response = await fetch(buildUrl(path), { method: 'POST', headers, body: JSON.stringify(body), credentials: 'include' });
  } catch {
    throw new ApiError(0, null);
  }

  if (response.status === 419 && !retried) {
    await ensureCsrfCookie(true);
    return downloadFile(path, body, true);
  }
  if (!response.ok) throw new ApiError(response.status, await response.json().catch(() => null));

  const filename = /filename="?([^";]+)"?/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? 'export.csv';
  const url = URL.createObjectURL(await response.blob());
  const link = Object.assign(document.createElement('a'), { href: url, download: filename });
  document.body.append(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

export const newIdempotencyKey = () => crypto.randomUUID();

/** Enveloppe { data } des ressources de l'API. */
export type Envelope<T> = { data: T };

/** Liste paginée (docs/api/README.md). */
export type Paginated<T> = {
  data: T[];
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
  meta: { current_page: number; per_page: number; total: number; last_page: number; from: number | null; to: number | null };
};

/** Montant en unité mineure — jamais de nombre décimal. */
export type Money = { minor: number; currency: string };
