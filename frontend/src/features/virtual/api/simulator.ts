/**
 * Simulateur du prestataire de jeux virtuels, côté navigateur.
 *
 * Le prestataire (D-11) et l'API du backend (#208) n'existent pas encore. Ce module joue leur rôle pour que les écrans
 * fonctionnent de bout en bout : programme des épreuves, cotes, direct, résultats, enregistrement et règlement des
 * paris. Il n'est importé que par api/virtualGames.ts : le jour où l'API existe, on remplace les appels à ce module
 * par des appels api(...) et on le supprime, sans toucher aux écrans.
 *
 * Les épreuves sont calculées à partir de l'heure (créneaux réguliers) et d'un tirage pseudo-aléatoire par créneau :
 * la même épreuve donne toujours les mêmes équipes, cotes et résultat. Les paris sont gardés dans ce navigateur
 * (localStorage, par joueur) et ne touchent pas au solde : le débit et le gain passeront par le ledger du serveur.
 */
import { ApiError, type Money } from '../../../shared/api/client';
import type { VirtualBet, VirtualEvent, VirtualGame, VirtualGameKey, VirtualMarket, VirtualRunner } from '../types';

export const GAMES: VirtualGame[] = [
  { key: 'football', name: 'Football virtuel', description: 'Matchs de la Ligue virtuelle, toutes les 3 minutes.' },
  { key: 'courses', name: 'Courses virtuelles', description: 'Courses de chevaux, toutes les 2 minutes.' },
];

/** Rythme et durée des épreuves (en attendant ceux du prestataire). */
const SCHEDULE: Record<VirtualGameKey, { periodMs: number; durationMs: number; closeBeforeMs: number }> = {
  football: { periodMs: 180_000, durationMs: 90_000, closeBeforeMs: 10_000 },
  courses: { periodMs: 120_000, durationMs: 60_000, closeBeforeMs: 10_000 },
};

const TEAMS = ['Kapwa FC', 'Lavil United', 'Mòn Wouj', 'Lakòt SC', 'Plato Stars', 'Rivyè Blè', 'Zetwal Nò', 'Solèy FC'];
const HORSES = ['Tonnè', 'Van Lib', 'Bèl Zetwal', 'Flèch Wouj', 'Kreyòl Gold', 'Lanmè Blè', 'Ti Loray', 'Siklòn', 'Mango Fran', 'Kalfou', 'Lalin Klè', 'Bourik Vit'];
const MATCHES_PER_ROUND = 4;
const RUNNERS_PER_RACE = 8;
const CURRENCY = 'HTG';
/** Mise minimale : 1 gourde. */
const MIN_STAKE_MINOR = 100;

/** Générateur pseudo-aléatoire reproductible (mulberry32). */
function seeded(seed: number): () => number {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4_294_967_296;
  };
}

function shuffle<T>(items: T[], random: () => number): T[] {
  const list = [...items];
  for (let i = list.length - 1; i > 0; i--) {
    const j = Math.floor(random() * (i + 1));
    [list[i], list[j]] = [list[j], list[i]];
  }
  return list;
}

/** Probabilité → cote décimale avec une marge, en chaîne à deux décimales comme celles de l'API. */
function odds(probability: number, margin = 1.08): string {
  return Math.max(1.05, 1 / (probability * margin)).toFixed(2);
}

function iso(ms: number): string {
  return new Date(ms).toISOString();
}

function timing(game: VirtualGameKey, slot: number, now: number) {
  const { periodMs, durationMs, closeBeforeMs } = SCHEDULE[game];
  const start = slot * periodMs;
  const end = start + durationMs;
  const status = now < start ? 'scheduled' as const : now < end ? 'running' as const : 'finished' as const;
  return { start, end, closes: start - closeBeforeMs, status, elapsed: Math.min(1, Math.max(0, (now - start) / durationMs)) };
}

function footballMatch(slot: number, index: number, now: number): VirtualEvent {
  const teams = shuffle(TEAMS, seeded(slot * 31 + 7));
  const home = teams[index * 2];
  const away = teams[index * 2 + 1];
  const random = seeded(slot * 97 + index * 13 + 1);
  const strengthHome = 0.7 + random() * 0.9;
  const strengthAway = 0.6 + random() * 0.9;
  const draw = 0.22 + random() * 0.08;
  const homeShare = (1 - draw) * (strengthHome / (strengthHome + strengthAway));
  const over = 0.42 + random() * 0.18;
  const { start, end, closes, status, elapsed } = timing('football', slot, now);
  const id = `football-${slot}-${index}`;

  const markets: VirtualMarket[] = [
    {
      id: `${id}-1x2`, key: 'h2h', name: 'Résultat du match',
      selections: [
        { id: `${id}-1`, label: '1', name: home, odds: odds(homeShare) },
        { id: `${id}-x`, label: 'X', name: 'Match nul', odds: odds(draw) },
        { id: `${id}-2`, label: '2', name: away, odds: odds(1 - draw - homeShare) },
      ],
    },
    {
      id: `${id}-ou`, key: 'totals', name: 'Plus / moins de 2,5 buts',
      selections: [
        { id: `${id}-over`, label: '+ 2,5', name: 'Plus de 2,5 buts', odds: odds(over) },
        { id: `${id}-under`, label: '- 2,5', name: 'Moins de 2,5 buts', odds: odds(1 - over) },
      ],
    },
  ];

  // Buts du match, chacun avec sa minute : le direct n'affiche que ceux déjà marqués.
  const goalMinutes = (strength: number) => Array.from({ length: Math.floor(random() * random() * 4.5 * strength) }, () => 1 + Math.floor(random() * 90));
  const homeGoals = goalMinutes(strengthHome);
  const awayGoals = goalMinutes(strengthAway);
  const minute = Math.max(1, Math.ceil(elapsed * 90));

  return {
    id,
    game: 'football',
    name: `Ligue virtuelle · Journée ${(slot % 38) + 1}`,
    home_team: home,
    away_team: away,
    runners: [],
    starts_at: iso(start),
    betting_closes_at: iso(closes),
    ends_at: iso(end),
    status,
    markets,
    live: status === 'running'
      ? { kind: 'football', minute, home: homeGoals.filter((m) => m <= minute).length, away: awayGoals.filter((m) => m <= minute).length }
      : null,
    result: status === 'finished' ? { kind: 'football', home: homeGoals.length, away: awayGoals.length } : null,
  };
}

function race(slot: number, now: number): VirtualEvent {
  const random = seeded(slot * 53 + 3);
  const runners: VirtualRunner[] = shuffle(HORSES, random).slice(0, RUNNERS_PER_RACE).map((name, i) => ({ number: i + 1, name }));
  const weights = runners.map(() => 1 + random() * 5);
  const total = weights.reduce((sum, w) => sum + w, 0);
  const { start, end, closes, status, elapsed } = timing('courses', slot, now);
  const id = `courses-${slot}`;

  const markets: VirtualMarket[] = [
    {
      id: `${id}-win`, key: 'win', name: 'Gagnant',
      selections: runners.map((r, i) => ({ id: `${id}-win-${r.number}`, label: String(r.number), name: r.name, odds: odds(weights[i] / total, 1.15) })),
    },
    {
      id: `${id}-place`, key: 'place', name: 'Placé (dans les 3 premiers)',
      selections: runners.map((r, i) => ({
        id: `${id}-place-${r.number}`, label: String(r.number), name: r.name, odds: odds(Math.min(0.9, (3 * weights[i]) / total), 1.15),
      })),
    },
  ];

  // Arrivée : tirage pondéré sans remise parmi les partants.
  const podium: VirtualRunner[] = [];
  const left = runners.map((r, i) => ({ r, w: weights[i] }));
  for (let place = 0; place < 3; place++) {
    let pick = random() * left.reduce((sum, x) => sum + x.w, 0);
    const index = left.findIndex((x) => (pick -= x.w) <= 0);
    podium.push(left.splice(index === -1 ? left.length - 1 : index, 1)[0].r);
  }

  return {
    id,
    game: 'courses',
    name: `Course ${(slot % 1000) + 1}`,
    home_team: null,
    away_team: null,
    runners,
    starts_at: iso(start),
    betting_closes_at: iso(closes),
    ends_at: iso(end),
    status,
    markets,
    live: status === 'running' ? { kind: 'courses', progress: Math.round(elapsed * 100) } : null,
    result: status === 'finished' ? { kind: 'courses', podium } : null,
  };
}

function eventsOfSlot(game: VirtualGameKey, slot: number, now: number): VirtualEvent[] {
  return game === 'football'
    ? Array.from({ length: MATCHES_PER_ROUND }, (_, i) => footballMatch(slot, i, now))
    : [race(slot, now)];
}

/** Épreuves en cours puis à venir (créneaux courant et suivants). */
export function upcomingEvents(game: VirtualGameKey, now: Date, slots = game === 'football' ? 3 : 5): VirtualEvent[] {
  const { periodMs, durationMs } = SCHEDULE[game];
  const first = Math.floor((now.getTime() - durationMs) / periodMs) + 1;
  return Array.from({ length: slots }, (_, i) => eventsOfSlot(game, first + i, now.getTime()))
    .flat()
    .filter((e) => e.status !== 'finished');
}

/** Dernières épreuves terminées, de la plus récente à la plus ancienne. */
export function latestResults(game: VirtualGameKey, now: Date, slots = game === 'football' ? 2 : 6): VirtualEvent[] {
  const { periodMs, durationMs } = SCHEDULE[game];
  const last = Math.floor((now.getTime() - durationMs) / periodMs);
  return Array.from({ length: slots }, (_, i) => eventsOfSlot(game, last - i, now.getTime())).flat();
}

/** Une épreuve par son identifiant (« football-<créneau>-<n> », « courses-<créneau> ») ; null si inconnu. */
export function findEvent(id: string, now: Date): VirtualEvent | null {
  const football = /^football-(\d+)-([0-3])$/.exec(id);
  if (football) return footballMatch(Number(football[1]), Number(football[2]), now.getTime());
  const courses = /^courses-(\d+)$/.exec(id);
  if (courses) return race(Number(courses[1]), now.getTime());
  return null;
}

/** Sélections gagnantes d'une épreuve terminée. */
export function winningSelections(event: VirtualEvent): Set<string> {
  const result = event.result;
  if (!result) return new Set();
  if (result.kind === 'football') {
    const outcome = result.home > result.away ? '1' : result.home === result.away ? 'x' : '2';
    return new Set([`${event.id}-${outcome}`, `${event.id}-${result.home + result.away > 2 ? 'over' : 'under'}`]);
  }
  return new Set([
    `${event.id}-win-${result.podium[0].number}`,
    ...result.podium.map((r) => `${event.id}-place-${r.number}`),
  ]);
}

// ——— Paris ———

type StoredBet = VirtualBet & { idempotency_key: string };

function storageKey(userId: string): string {
  return `reyel16.virtual-bets.${userId}`;
}

function load(userId: string): StoredBet[] {
  try {
    const parsed: unknown = JSON.parse(localStorage.getItem(storageKey(userId)) ?? '[]');
    return Array.isArray(parsed) ? parsed as StoredBet[] : [];
  } catch {
    return [];
  }
}

function save(userId: string, bets: StoredBet[]): void {
  try {
    localStorage.setItem(storageKey(userId), JSON.stringify(bets.slice(0, 200)));
  } catch {
    // Stockage plein ou bloqué : le pari reste affiché jusqu'au rechargement.
  }
}

function money(minor: number): Money {
  return { minor, currency: CURRENCY };
}

function reject(status: number, code: string, message: string, details?: Record<string, unknown>): never {
  throw new ApiError(status, { error: { code, message, details } });
}

export type PlaceBetInput = { event_id: string; selection_id: string; stake_minor: number };

/**
 * Enregistre un pari simple, avec les mêmes contrôles qu'un serveur : épreuve connue, mises ouvertes, sélection
 * proposée, mise minimale. Une même clé d'idempotence renvoie le pari déjà enregistré au lieu d'en créer un second.
 */
export function placeBet(userId: string, input: PlaceBetInput, idempotencyKey: string, now: Date): VirtualBet {
  const bets = load(userId);
  const existing = bets.find((b) => b.idempotency_key === idempotencyKey);
  if (existing) return withoutKey(existing);

  const event = findEvent(input.event_id, now);
  if (!event) reject(404, 'not_found', 'Cette épreuve n\'existe pas.');
  if (event.status !== 'scheduled' || now.getTime() >= Date.parse(event.betting_closes_at)) {
    reject(422, 'betting_closed', 'Les mises sont fermées pour cette épreuve.');
  }
  const market = event.markets.find((m) => m.selections.some((s) => s.id === input.selection_id));
  const selection = market?.selections.find((s) => s.id === input.selection_id);
  if (!market || !selection) reject(422, 'selection_unavailable', 'Cette cote n\'est plus proposée.');
  if (!Number.isInteger(input.stake_minor) || input.stake_minor < MIN_STAKE_MINOR) {
    reject(422, 'validation_failed', 'La mise minimale est de 1 gourde.', { stake: ['La mise minimale est de 1 gourde.'] });
  }

  const id = `${now.getTime().toString(36)}${Math.floor(Math.random() * 1e6).toString(36)}`;
  const bet: StoredBet = {
    id,
    reference: `JV-${id.slice(-6).toUpperCase()}`,
    event: {
      id: event.id,
      game: event.game,
      name: event.name,
      title: event.home_team && event.away_team ? `${event.home_team} – ${event.away_team}` : event.name,
      starts_at: event.starts_at,
    },
    market: { key: market.key, name: market.name },
    selection: { id: selection.id, label: selection.label, name: selection.name },
    odds: selection.odds,
    stake: money(input.stake_minor),
    potential_payout: money(Math.round(input.stake_minor * Number(selection.odds))),
    status: 'pending',
    payout: null,
    placed_at: now.toISOString(),
    settled_at: null,
    idempotency_key: idempotencyKey,
  };
  save(userId, [bet, ...bets]);
  return withoutKey(bet);
}

/** Paris du joueur, du plus récent au plus ancien ; ceux dont l'épreuve est terminée sont réglés au passage. */
export function listBets(userId: string, now: Date): VirtualBet[] {
  let changed = false;
  const bets = load(userId).map((bet) => {
    if (bet.status !== 'pending') return bet;
    const event = findEvent(bet.event.id, now);
    if (!event || event.status !== 'finished') return bet;
    changed = true;
    const won = winningSelections(event).has(bet.selection.id);
    return {
      ...bet,
      status: won ? 'won' as const : 'lost' as const,
      payout: won ? bet.potential_payout : money(0),
      settled_at: event.ends_at,
    };
  });
  if (changed) save(userId, bets);
  return bets.map(withoutKey);
}

/** Le pari tel que l'API le renverrait, sans la clé d'idempotence (donnée interne du serveur). */
function withoutKey(stored: StoredBet): VirtualBet {
  const bet: Partial<StoredBet> = { ...stored };
  delete bet.idempotency_key;
  return bet as VirtualBet;
}
