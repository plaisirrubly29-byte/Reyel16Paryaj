import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { guest, mockApi, renderApp } from '../../test/renderApp';
import { LEGAL_PAGES, LEGAL_VERSION } from './legal';

beforeEach(() => {
  localStorage.clear();
  mockApi({
    'GET /me': guest,
    'GET /sports': { status: 200, body: { data: [] } },
    'GET /events': { status: 200, body: { data: [], links: {}, meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } } },
  });
});
afterEach(() => vi.unstubAllGlobals());

describe("pages d'information", () => {
  it.each(LEGAL_PAGES.map((p) => [p.to, p.title]))('%s affiche « %s » avec sa version', async (path, title) => {
    renderApp(path);

    expect(await screen.findByRole('heading', { level: 1, name: title })).toBeInTheDocument();
    expect(screen.getByText(new RegExp(`version ${LEGAL_VERSION}`))).toBeInTheDocument();
    expect(screen.queryByText(/À implémenter/)).toBeNull();
    // Menu commun des quatre textes, page en cours repérée.
    const nav = screen.getByRole('navigation', { name: "Pages d'information" });
    expect(within(nav).getByRole('link', { name: title })).toHaveAttribute('aria-current', 'page');
  });

  it('le règlement reprend les mises et plafonds décidés (D-06)', async () => {
    renderApp('/reglement');

    expect(await screen.findByText('10,00 HTG')).toBeInTheDocument();
    expect(screen.getByText('50 000,00 HTG')).toBeInTheDocument();
    expect(screen.getByText('1 000 000,00 HTG')).toBeInTheDocument();
    expect(screen.getByText('48 heures')).toBeInTheDocument();
  });

  it('le pied de page mène aux quatre textes', async () => {
    renderApp('/');

    const footer = await screen.findByRole('contentinfo');
    const nav = within(footer).getByRole('navigation', { name: 'Informations' });
    for (const p of LEGAL_PAGES) {
      expect(within(nav).getByRole('link', { name: p.title })).toHaveAttribute('href', p.to);
    }
  });

  it('une adresse inconnue garde le site autour et propose de revenir aux matchs', async () => {
    renderApp('/page-qui-n-existe-pas');

    expect(await screen.findByRole('heading', { level: 1, name: "Cette page n'existe pas" })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Retour aux matchs' })).toHaveAttribute('href', '/');
    expect(screen.getByRole('link', { name: "S'inscrire" })).toBeInTheDocument();
  });
});

describe('bandeau cookies', () => {
  it("s'affiche jusqu'à « J'ai compris », puis ne revient plus pour cette version", async () => {
    renderApp('/');

    const banner = await screen.findByRole('region', { name: 'Cookies' });
    expect(within(banner).getByRole('link', { name: 'En savoir plus' })).toHaveAttribute('href', '/confidentialite');
    await userEvent.click(within(banner).getByRole('button', { name: "J'ai compris" }));

    expect(screen.queryByRole('region', { name: 'Cookies' })).toBeNull();
    expect(localStorage.getItem('r16p.cookies')).toBe(LEGAL_VERSION);
    cleanup();

    renderApp('/');
    await screen.findByRole('contentinfo');
    expect(screen.queryByRole('region', { name: 'Cookies' })).toBeNull();
  });

  it('revient quand la version des textes change', async () => {
    localStorage.setItem('r16p.cookies', '2000-01-01');
    renderApp('/');

    expect(await screen.findByRole('region', { name: 'Cookies' })).toBeInTheDocument();
  });
});
