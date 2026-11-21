import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { currentQueryClient, mockApi, player, renderApp } from '../../test/renderApp';

afterEach(() => vi.unstubAllGlobals());

const NBSP = new RegExp(`[${String.fromCharCode(0xa0, 0x202f)}]`, 'g');
const text = (el: HTMLElement) => (el.textContent ?? '').replace(NBSP, ' ');
const htg = (minor: number) => ({ minor, currency: 'HTG' });

const noLimit = { amount: null, used: htg(0), remaining: null, pending: null };
const options = { pause_durations: ['24h', '7d', '30d'], exclusion_durations: ['6m', '1y', '5y'], limit_increase_delay_days: 7 };

function state(overrides: Partial<{ limits: Record<string, unknown>; pause: { active: boolean; ends_at: string | null } }> = {}) {
  return {
    data: {
      limits: { day: noLimit, week: noLimit, month: noLimit, ...overrides.limits },
      pause: overrides.pause ?? { active: false, ends_at: null },
      options,
    },
  };
}

const base = {
  'GET /me': { status: 200, body: { data: player } },
  'GET /wallet': { status: 200, body: { data: { currency: 'HTG', balance: htg(1_000_00), status: 'active', simulated: false } } },
};

const card = async (name: string) => screen.findByRole('region', { name });

describe('jeu responsable du joueur', () => {
  it('affiche les limites, les mises et le reste tels que fournis par le serveur', async () => {
    mockApi({
      ...base,
      'GET /responsible-gaming': {
        status: 200,
        body: state({
          limits: {
            // Reste volontairement incohérent avec limite − misé : l'écran ne recalcule rien.
            day: { amount: htg(5_000_00), used: htg(1_250_00), remaining: htg(3_000_00), pending: null },
            week: { amount: htg(10_000_00), used: htg(0), remaining: htg(10_000_00), pending: { amount: htg(20_000_00), effective_at: '2026-11-09T18:00:00+00:00' } },
          },
        }),
      },
    });

    renderApp('/responsible-gaming');

    const limits = await card('Limites de mise');
    const [day, week, month] = within(limits).getAllByRole('heading', { level: 3 }).map((h) => h.closest('.rg-limit') as HTMLElement);
    expect(text(day)).toContain('5 000,00 HTG');
    expect(text(day)).toContain('Misé : 1 250,00 HTG · Reste : 3 000,00 HTG');
    expect(text(week)).toMatch(/Passera à 20 000,00 HTG le 9 novembre 2026/);
    expect(text(month)).toContain('Aucune limite');
    expect(within(month).getByRole('button', { name: 'Fixer une limite' })).toBeInTheDocument();
    // Rubrique présente dans le menu de l'espace joueur.
    expect(within(screen.getByRole('navigation', { name: 'Rubriques du compte' })).getByRole('link', { name: /Jeu responsable/ })).toHaveAttribute('aria-current', 'page');
  });

  it('fixe une limite en centimes avec une clé d\'idempotence, puis affiche le nouvel état', async () => {
    let request: { body: unknown; key: string | null } | null = null;
    mockApi({
      ...base,
      'GET /responsible-gaming': { status: 200, body: state() },
      'PUT /responsible-gaming/limits/day': (init) => {
        request = { body: JSON.parse(String(init?.body)), key: new Headers(init?.headers).get('Idempotency-Key') };
        return { status: 200, body: state({ limits: { day: { amount: htg(2_500_50), used: htg(0), remaining: htg(2_500_50), pending: null } } }) };
      },
    });

    renderApp('/responsible-gaming');
    const limits = await card('Limites de mise');
    await userEvent.click(within(limits).getAllByRole('button', { name: 'Fixer une limite' })[0]);

    // Montant vide : rien n'est envoyé.
    await userEvent.click(within(limits).getByRole('button', { name: 'Enregistrer' }));
    expect(within(limits).getByText('Saisissez un montant supérieur à 0.')).toBeInTheDocument();
    expect(request).toBeNull();

    await userEvent.type(within(limits).getByLabelText(/Nouvelle limite sur 24 heures/), '2500,5');
    await userEvent.click(within(limits).getByRole('button', { name: 'Enregistrer' }));

    expect(await within(limits).findByText('Limite appliquée.')).toBeInTheDocument();
    expect(request).toEqual({ body: { amount_minor: 2_500_50 }, key: expect.any(String) });
    expect(text(limits)).toContain('2 500,50 HTG');
  });

  it('annonce la date d\'effet d\'une hausse ou d\'une suppression', async () => {
    const day = { amount: htg(1_000_00), used: htg(0), remaining: htg(1_000_00), pending: null };
    let sent: unknown;
    mockApi({
      ...base,
      'GET /responsible-gaming': { status: 200, body: state({ limits: { day } }) },
      'PUT /responsible-gaming/limits/day': (init) => {
        sent = JSON.parse(String(init?.body));
        return { status: 200, body: state({ limits: { day: { ...day, pending: { amount: null, effective_at: '2026-11-09T18:00:00+00:00' } } } }) };
      },
    });

    renderApp('/responsible-gaming');
    const limits = await card('Limites de mise');
    await userEvent.click(within(limits).getByRole('button', { name: 'Modifier' }));
    await userEvent.click(within(limits).getByRole('button', { name: 'Supprimer la limite' }));

    expect(await within(limits).findByText('Suppression enregistrée, après le délai de réflexion.')).toBeInTheDocument();
    expect(sent).toEqual({ amount_minor: null });
    expect(within(limits).getByText(/Limite supprimée le 9 novembre 2026/)).toBeInTheDocument();
  });

  it('demande une confirmation avant la pause, puis affiche sa fin', async () => {
    const pause = vi.fn(() => ({ status: 200, body: state({ pause: { active: true, ends_at: '2026-11-09T18:00:00+00:00' } }) }));
    mockApi({ ...base, 'GET /responsible-gaming': { status: 200, body: state() }, 'POST /responsible-gaming/pause': pause });

    renderApp('/responsible-gaming');
    const section = await card('Faire une pause');
    await userEvent.click(within(section).getByRole('radio', { name: '7 jours' }));
    await userEvent.click(within(section).getByRole('button', { name: 'Faire une pause de 7 jours' }));
    expect(pause).not.toHaveBeenCalled();
    expect(within(section).getByText(/ne peut être ni annulée ni raccourcie/)).toBeInTheDocument();

    await userEvent.click(within(section).getByRole('button', { name: 'Confirmer la pause' }));

    expect(await within(section).findByText(/Pause enregistrée jusqu'au 9 novembre 2026/)).toBeInTheDocument();
    expect(JSON.parse(String((pause.mock.calls[0] as unknown as [RequestInit])[0].body))).toEqual({ duration: '7d' });
    // Bandeau en haut de page, d'après le nouvel état renvoyé par le serveur.
    expect(screen.getByText('Paris en pause')).toBeInTheDocument();
  });

  it('refuse l\'auto-exclusion sans mot de passe ni confirmation, et montre un mot de passe incorrect', async () => {
    const exclude = vi.fn(() => ({ status: 422, body: { error: { code: 'invalid_password', message: 'Mot de passe incorrect.' } } }));
    mockApi({ ...base, 'GET /responsible-gaming': { status: 200, body: state() }, 'POST /responsible-gaming/self-exclusion': exclude });

    renderApp('/responsible-gaming');
    const section = await card("S'exclure du jeu");
    await userEvent.click(within(section).getByRole('button', { name: "Je veux m'exclure" }));
    await userEvent.click(within(section).getByRole('button', { name: "M'exclure pour 6 mois" }));

    expect(within(section).getByText('Saisissez votre mot de passe.')).toBeInTheDocument();
    expect(within(section).getByText('Cochez la case pour confirmer.')).toBeInTheDocument();
    expect(exclude).not.toHaveBeenCalled();

    await userEvent.type(within(section).getByLabelText('Mot de passe'), 'mauvais');
    await userEvent.click(within(section).getByRole('checkbox', { name: /irrévocable/ }));
    await userEvent.click(within(section).getByRole('button', { name: "M'exclure pour 6 mois" }));

    expect(await within(section).findByText('Mot de passe incorrect.')).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 1, name: 'Jeu responsable' })).toBeInTheDocument();
  });

  it('après l\'auto-exclusion, déconnecte et indique la date de fin', async () => {
    let sent: unknown;
    mockApi({
      ...base,
      'GET /responsible-gaming': { status: 200, body: state() },
      'POST /responsible-gaming/self-exclusion': (init) => {
        sent = JSON.parse(String(init?.body));
        return { status: 200, body: { data: { excluded_until: '2027-11-02T18:00:00+00:00', reopening: 'on_request' } } };
      },
    });

    const router = renderApp('/responsible-gaming');
    const section = await card("S'exclure du jeu");
    await userEvent.click(within(section).getByRole('button', { name: "Je veux m'exclure" }));
    await userEvent.click(within(section).getByRole('radio', { name: '1 an' }));
    await userEvent.type(within(section).getByLabelText('Mot de passe'), 'secret123');
    await userEvent.click(within(section).getByRole('checkbox', { name: /irrévocable/ }));
    await userEvent.click(within(section).getByRole('button', { name: "M'exclure pour 1 an" }));

    expect(await screen.findByRole('heading', { level: 1, name: 'Votre auto-exclusion est enregistrée' })).toBeInTheDocument();
    expect(screen.getByText(/2 novembre 2027/)).toBeInTheDocument();
    expect(sent).toEqual({ duration: '1y', password: 'secret123' });
    expect(router.state.location.pathname).toBe('/auto-exclusion');
    await waitFor(() => expect(currentQueryClient().getQueryData(['me'])).toBeNull());
    expect(currentQueryClient().getQueryData(['wallet'])).toBeUndefined();
  });
});

describe('limites de dépôt (V2-B06)', () => {
  const withDeposits = (deposit: Record<string, unknown> = {}) => ({
    data: { ...state().data, deposit_limits: { day: noLimit, week: noLimit, month: noLimit, ...deposit } },
  });

  it('affiche les limites de dépôt et les dépôts de la période, à côté des limites de mise', async () => {
    mockApi({
      ...base,
      'GET /responsible-gaming': {
        status: 200,
        body: withDeposits({
          day: { amount: htg(2_000_00), used: htg(500_00), remaining: htg(1_500_00), pending: null },
          month: { amount: htg(20_000_00), used: htg(0), remaining: htg(20_000_00), pending: { amount: null, effective_at: '2026-11-17T18:00:00+00:00' } },
        }),
      },
    });

    renderApp('/responsible-gaming');

    const deposits = await card('Limites de dépôt');
    expect(text(deposits)).toMatch(/Le total de vos dépôts ne pourra pas dépasser ces montants.*7 jours de réflexion/);
    expect(text(deposits)).toContain('refusé, en ligne comme en agence');
    const [day, week, month] = within(deposits).getAllByRole('heading', { level: 3 }).map((h) => h.closest('.rg-limit') as HTMLElement);
    expect(text(day)).toContain('2 000,00 HTG');
    expect(text(day)).toContain('Déposé : 500,00 HTG · Reste : 1 500,00 HTG');
    expect(within(day).getByRole('progressbar', { name: 'Dépôts sur 24 heures' })).toBeInTheDocument();
    expect(text(week)).toContain('Aucune limite');
    expect(text(month)).toMatch(/Limite supprimée le 17 novembre 2026/);
    // Les limites de mise restent distinctes.
    expect(text(await card('Limites de mise'))).toContain('Misé : 0,00 HTG');
  });

  it('fixe une limite de dépôt sur sa propre route, avec une clé d\'idempotence', async () => {
    let request: { body: unknown; key: string | null } | null = null;
    mockApi({
      ...base,
      'GET /responsible-gaming': { status: 200, body: withDeposits() },
      'PUT /responsible-gaming/deposit-limits/week': (init) => {
        request = { body: JSON.parse(String(init?.body)), key: new Headers(init?.headers).get('Idempotency-Key') };
        return { status: 200, body: withDeposits({ week: { amount: htg(5_000_00), used: htg(0), remaining: htg(5_000_00), pending: null } }) };
      },
    });

    renderApp('/responsible-gaming');
    const deposits = await card('Limites de dépôt');
    await userEvent.click(within(deposits).getAllByRole('button', { name: 'Fixer une limite' })[1]);
    await userEvent.type(within(deposits).getByLabelText('Nouvelle limite de dépôt sur 7 jours (HTG)'), '5000');
    await userEvent.click(within(deposits).getByRole('button', { name: 'Enregistrer' }));

    expect(await within(deposits).findByText('Limite appliquée.')).toBeInTheDocument();
    expect(request).toEqual({ body: { amount_minor: 5_000_00 }, key: expect.any(String) });
    expect(text(deposits)).toContain('5 000,00 HTG');
    // La limite de mise de la même période n'a pas bougé.
    expect(text(await card('Limites de mise'))).not.toContain('5 000,00 HTG');
  });

  it('annonce la hausse d\'une limite de dépôt après le délai de réflexion', async () => {
    const day = { amount: htg(1_000_00), used: htg(0), remaining: htg(1_000_00), pending: null };
    mockApi({
      ...base,
      'GET /responsible-gaming': { status: 200, body: withDeposits({ day }) },
      'PUT /responsible-gaming/deposit-limits/day': {
        status: 200,
        body: withDeposits({ day: { ...day, pending: { amount: htg(3_000_00), effective_at: '2026-11-17T18:00:00+00:00' } } }),
      },
    });

    renderApp('/responsible-gaming');
    const deposits = await card('Limites de dépôt');
    await userEvent.click(within(deposits).getByRole('button', { name: 'Modifier' }));
    await userEvent.type(within(deposits).getByLabelText('Nouvelle limite de dépôt sur 24 heures (HTG)'), '3000');
    await userEvent.click(within(deposits).getByRole('button', { name: 'Enregistrer' }));

    expect(await within(deposits).findByText('Hausse enregistrée, après le délai de réflexion.')).toBeInTheDocument();
    expect(text(deposits)).toMatch(/Passera à 3 000,00 HTG le 17 novembre 2026/);
    expect(text(deposits)).toContain('1 000,00 HTG');
  });

  it('ne montre pas la carte quand le serveur ne gère pas encore les limites de dépôt', async () => {
    mockApi({ ...base, 'GET /responsible-gaming': { status: 200, body: state() } });

    renderApp('/responsible-gaming');

    expect(await card('Limites de mise')).toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Limites de dépôt' })).toBeNull();
  });
});
