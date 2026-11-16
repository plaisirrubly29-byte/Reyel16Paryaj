import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { guest, mockApi, player, renderApp } from '../../test/renderApp';

afterEach(() => vi.unstubAllGlobals());

const NBSP = new RegExp(`[${String.fromCharCode(0xa0, 0x202f)}]`, 'g');
const clean = (el: Element | null) => (el?.textContent ?? '').replace(NBSP, ' ');
const htg = (minor: number) => ({ minor, currency: 'HTG' });
const page = (data: unknown[]) => ({
  data,
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, per_page: 10, total: data.length, last_page: 1, from: 1, to: data.length },
});

const TIERS = [
  { key: 'bronze', label: 'Bronze', min_points: 0, rate: htg(10_00) },
  { key: 'silver', label: 'Argent', min_points: 1_000, rate: htg(11_00) },
  { key: 'gold', label: 'Or', min_points: 5_000, rate: htg(12_00) },
  { key: 'diamond', label: 'Diamant', min_points: 20_000, rate: htg(15_00) },
];
const TERMS = { wagering_multiplier: 3, min_odds: '1.50', days: 30 };

const loyalty = (extra: Record<string, unknown> = {}) => ({
  points: 750, points_12_months: 750, tier: TIERS[0], next_tier: TIERS[1], tiers: TIERS,
  earn_rule: { stake_step: htg(100_00), points_per_step: 1 }, exchange: { min_points: 500, step: 100 },
  points_expiring: null, bonus_balance: htg(0), wagering: [], bonus_terms: TERMS, ...extra,
});

const referral = (extra: Record<string, unknown> = {}) => ({
  code: 'RY7K2M9P', link: 'https://reyel16paryaj.com/register?parrain=RY7K2M9P', reward: htg(250_00), eligible: true,
  rules: { qualify_days: 30, min_deposit: htg(500_00), min_stake: htg(500_00), monthly_limit: 20 },
  rewarded_this_month: 1, total_rewards: htg(250_00), bonus_terms: TERMS,
  referrals: [
    { id: 'r1', name: 'Jean D.', joined_at: '2026-11-02T10:00:00Z', status: 'rewarded', steps: { kyc: true, deposit: true, stake: true }, qualify_before: '2026-12-02T10:00:00Z' },
    { id: 'r2', name: 'Marie P.', joined_at: '2026-11-10T10:00:00Z', status: 'pending', steps: { kyc: true, deposit: false, stake: false }, qualify_before: '2026-12-10T10:00:00Z' },
  ],
  ...extra,
});

const base = {
  'GET /me': { status: 200, body: { data: player } },
  'GET /wallet': { status: 200, body: { data: { currency: 'HTG', balance: htg(1_000_00), status: 'active', simulated: false } } },
  'GET /me/loyalty/history': { status: 200, body: page([{ id: 'e1', type: 'earned', points: 12, label: 'Pari réglé — ticket R16-8F2K', bonus: null, created_at: '2026-11-14T10:00:00Z' }]) },
};

describe('points de fidélité (V2-E07)', () => {
  it('affiche le palier, la progression, les règles et l\'historique', async () => {
    mockApi({ ...base, 'GET /me/loyalty': { status: 200, body: { data: loyalty() } } });
    renderApp('/loyalty');

    expect(await screen.findByText('750')).toBeInTheDocument();
    expect(screen.getAllByText('Bronze').length).toBeGreaterThan(0);
    expect(screen.getByRole('progressbar', { name: 'Progression vers le palier Argent' })).toHaveAttribute('aria-valuenow', '750');
    expect(clean(screen.getByText(/sur 12 mois pour passer Argent/).closest('p'))).toContain('Encore 250 points');
    expect(within(screen.getByRole('list', { name: 'Paliers de fidélité' })).getAllByRole('listitem')).toHaveLength(4);
    expect(await screen.findByText('Pari réglé — ticket R16-8F2K')).toBeInTheDocument();
    // Conditions de mise affichées avant l'échange.
    expect(clean(screen.getByText(/Bonus à miser 3 fois/))).toContain("cote d'au moins 1,50, dans les 30 jours");
    // Plus de « Bientôt » sur la rubrique ouverte.
    const nav = screen.getByRole('navigation', { name: 'Rubriques du compte' });
    expect(within(nav).getByRole('link', { name: 'Points de fidélité' })).not.toHaveTextContent('Bientôt');
  });

  it('échange des points contre un bonus, par multiples de 100 et avec une clé d\'idempotence', async () => {
    let state = loyalty();
    const fetchMock = mockApi({
      ...base,
      'GET /me/loyalty': () => ({ status: 200, body: { data: state } }),
      'POST /me/loyalty/redeem': () => {
        state = loyalty({ points: 250, bonus_balance: htg(50_00), wagering: [{ amount: htg(50_00), required: htg(150_00), wagered: htg(0), min_odds: '1.50', expires_at: '2026-12-15T10:00:00Z' }] });
        return { status: 200, body: { data: state } };
      },
    });
    renderApp('/loyalty');

    const field = await screen.findByLabelText('Points à échanger');
    await userEvent.type(field, '450');
    expect(screen.getByText('Échangez au moins 500 points.')).toBeInTheDocument();
    await userEvent.clear(field);
    await userEvent.type(field, '550');
    expect(screen.getByText('Par multiples de 100 points.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Échanger' })).toBeDisabled();

    await userEvent.click(screen.getByRole('button', { name: '500' }));
    const submit = screen.getByRole('button', { name: /^Échanger contre/ });
    expect(clean(submit)).toBe('Échanger contre 50,00 HTG');
    await userEvent.click(submit);

    expect(await screen.findByText('Points échangés')).toBeInTheDocument();
    const post = fetchMock.mock.calls.find(([u, init]) => String(u).endsWith('/me/loyalty/redeem') && init?.method === 'POST')!;
    expect(JSON.parse(String(post[1]!.body))).toEqual({ points: 500 });
    expect(new Headers(post[1]!.headers).get('Idempotency-Key')).toMatch(/^[0-9a-f-]{36}$/);
    // Le bonus et ses conditions de mise apparaissent.
    expect(clean(await screen.findByText(/Misez encore/))).toContain('Misez encore 150,00 HTG');
  });

  it('programme pas encore ouvert (404) : la rubrique reste « Bientôt »', async () => {
    mockApi(base);
    renderApp('/loyalty');

    expect(await screen.findByText('Bientôt disponible')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Points de fidélité', level: 1 })).toBeInTheDocument();
  });
});

describe('parrainage (V2-E07)', () => {
  it('affiche le code, le partage, les règles et le suivi des filleuls', async () => {
    mockApi({ ...base, 'GET /me/referral': { status: 200, body: { data: referral() } } });
    renderApp('/referral');

    expect(await screen.findByText('RY7K2M9P')).toBeInTheDocument();
    expect(clean(screen.getByText(/pour chaque ami qualifié/))).toContain('Gagnez 250,00 HTG de bonus');
    expect(screen.getByRole('link', { name: 'Partager sur WhatsApp' }).getAttribute('href')).toContain(encodeURIComponent('register?parrain=RY7K2M9P'));
    expect(clean(screen.getByText('Récompensés ce mois').nextElementSibling)).toBe('1 / 20');
    expect(clean(screen.getByText(/Les comptes liés au vôtre/))).toContain('Bonus à miser 3 fois');

    const marie = screen.getByText('Marie P.').closest('li')!;
    expect(within(marie).getByText('En cours')).toBeInTheDocument();
    expect(within(marie).getByRole('list', { name: 'Étapes de Marie P.' })).toHaveTextContent('Identité vérifiée : fait');
    expect(within(marie).getByRole('list', { name: 'Étapes de Marie P.' })).toHaveTextContent('Dépôt : en attente');
    expect(screen.queryByText('Vos récompenses sont en attente')).toBeNull();
  });

  it('parrain sans identité vérifiée : récompenses en attente, lien vers la vérification', async () => {
    mockApi({ ...base, 'GET /me/referral': { status: 200, body: { data: referral({ eligible: false, referrals: [] }) } } });
    renderApp('/referral');

    expect(await screen.findByText('Vos récompenses sont en attente')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Vérifier mon identité' })).toHaveAttribute('href', '/verification');
    expect(screen.getByText(/Aucun filleul pour le moment/)).toBeInTheDocument();
  });

  it("le lien de parrainage préremplit le code à l'inscription, envoyé avec le compte", async () => {
    const fetchMock = mockApi({ 'GET /me': guest, 'POST /auth/register': { status: 201, body: { data: player } } });
    renderApp('/register?parrain=ry7k-2m9p');

    const code = await screen.findByLabelText('Code parrain (facultatif)');
    expect(code).toHaveValue('RY7K2M9P');
    await userEvent.type(screen.getByLabelText('Nom'), 'Ana Joueuse');
    await userEvent.type(screen.getByLabelText('Numéro de téléphone'), '34123456');
    await userEvent.type(screen.getByLabelText('Date de naissance'), '1995-05-21');
    await userEvent.type(screen.getByLabelText('Mot de passe'), 'motdepasse123');
    await userEvent.type(screen.getByLabelText('Confirmer le mot de passe'), 'motdepasse123');
    await userEvent.click(screen.getByRole('checkbox', { name: /J'accepte les conditions/ }));
    await userEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }));

    await waitFor(() => expect(fetchMock.mock.calls.some(([u]) => String(u).endsWith('/auth/register'))).toBe(true));
    const call = fetchMock.mock.calls.find(([u]) => String(u).endsWith('/auth/register'))!;
    expect(JSON.parse(String(call[1]!.body)).referral_code).toBe('RY7K2M9P');
  });
});
