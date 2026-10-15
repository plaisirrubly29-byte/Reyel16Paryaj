import { QueryClientProvider } from '@tanstack/react-query';
import { render } from '@testing-library/react';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { vi } from 'vitest';
import { routes } from '../app/router';
import { createQueryClient } from '../shared/api/queryClient';

type Handler = (init: RequestInit | undefined, url: URL) => { status: number; body?: unknown };

/**
 * Faux backend : associe "MÉTHODE /chemin" (sans /api/v1) à une réponse.
 * Le cookie CSRF est simulé ; toute route non prévue répond 404.
 */
export function mockApi(handlers: Record<string, Handler | { status: number; body?: unknown }>) {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(String(input));
    if (url.pathname === '/sanctum/csrf-cookie') {
      document.cookie = 'XSRF-TOKEN=test';
      return new Response(null, { status: 204 });
    }

    const key = `${init?.method ?? 'GET'} ${url.pathname.replace('/api/v1', '')}`;
    const handler = handlers[key];
    const result = typeof handler === 'function' ? handler(init, url) : handler ?? { status: 404, body: { error: { code: 'not_found', message: 'Introuvable.' } } };

    return new Response(result.body === undefined ? null : JSON.stringify(result.body), {
      status: result.status,
      headers: { 'Content-Type': 'application/json' },
    });
  });

  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

/** Rend l'application complète (routes réelles) à l'URL donnée. */
export function renderApp(path: string) {
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  render(
    <QueryClientProvider client={createQueryClient()}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
  return router;
}

export const guest = { status: 401, body: { error: { code: 'unauthenticated', message: 'Authentification requise.' } } };

export const player = {
  id: '1',
  name: 'Ana Joueuse',
  email: 'ana@example.com',
  status: 'active',
  roles: [],
  permissions: [] as string[],
  is_admin: false,
  mfa: { enabled: false, verified: false, required: false },
  profile: { first_name: null, last_name: null, birth_date: '1995-05-21', phone: null, country: 'HT' },
  created_at: '2026-10-01T12:00:00+00:00',
};
