/**
 * DONNÉES DE DÉMONSTRATION — jeux virtuels.
 *
 * Aucun prestataire n'est encore retenu (D-11) : ce module fabrique des épreuves fictives pour construire les écrans.
 * Équipes, chevaux, cotes, durées et résultats sont inventés et n'ont aucune valeur de règle de jeu. Les écrans ne
 * l'importent jamais directement : ils passent par api/virtualGames.ts, seul fichier à changer pour brancher l'API.
 *
 * Les épreuves sont calculées à partir de l'heure (créneaux réguliers) et d'un tirage pseudo-aléatoire graine par
 * créneau : la même épreuve donne toujours les mêmes équipes, cotes et résultat, sans rien stocker.
 */
import type { VirtualEvent, VirtualGame, VirtualGameKey, VirtualMarket, VirtualRunner } from '../types';

export const DEMO_GAMES: VirtualGame[] = [
  { key: 'football', name: 'Football virtuel', description: 'Matchs simulés de la Ligue virtuelle, toutes les 3 minutes.' },
  { key: 'courses', name: 'Courses virtuelles', description: 'Courses de chevaux simulées, toutes les 2 minutes.' },
];

/** Rythme et durée des épreuves de démonstration (fictifs : les vrais viendront du prestataire). */
const SCHEDULE: Record<VirtualGameKey, { periodMs: number; durationMs: number; closeBeforeMs: number }> = {
  football: { periodMs: 180_000, durationMs: 90_000, closeBeforeMs: 10_000 },
  courses: { periodMs: 120_000, durationMs: 60_000, closeBeforeMs: 10_000 },
};

const TEAMS = ['Kapwa FC', 'Lavil United', 'Mòn Wouj', 'Lakòt SC', 'Plato Stars', 'Rivyè Blè', 'Zetwal Nò', 'Solèy FC'];
const HORSES = ['Tonnè', 'Van Lib', 'Bèl Zetwal', 'Flèch Wouj', 'Kreyòl Gold', 'Lanmè Blè', 'Ti Loray', 'Siklòn', 'Mango Fran', 'Kalfou', 'Lalin Klè', 'Bourik Vit'];
const MATCHES_PER_ROUND = 4;
const RUNNERS_PER_RACE = 8;

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
  return { start, end, closes: start - closeBeforeMs, status };
}

function footballMatch(slot: number, index: number, now: number): VirtualEvent {
  const random = seeded(slot * 31 + 7);
  const teams = shuffle(TEAMS, random);
  const home = teams[index * 2];
  const away = teams[index * 2 + 1];
  const matchRandom = seeded(slot * 97 + index * 13 + 1);
  const strengthHome = 0.7 + matchRandom() * 0.9;
  const strengthAway = 0.6 + matchRandom() * 0.9;
  const draw = 0.22 + matchRandom() * 0.08;
  const homeShare = (1 - draw) * (strengthHome / (strengthHome + strengthAway));
  const over = 0.42 + matchRandom() * 0.18;
  const { start, end, closes, status } = timing('football', slot, now);
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

  const goals = (strength: number) => Math.floor(matchRandom() * matchRandom() * 4.5 * strength);

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
    result: status === 'finished' ? { kind: 'football', home: goals(strengthHome), away: goals(strengthAway) } : null,
  };
}

function race(slot: number, now: number): VirtualEvent {
  const random = seeded(slot * 53 + 3);
  const runners: VirtualRunner[] = shuffle(HORSES, random).slice(0, RUNNERS_PER_RACE).map((name, i) => ({ number: i + 1, name }));
  const weights = runners.map(() => 1 + random() * 5);
  const total = weights.reduce((sum, w) => sum + w, 0);
  const { start, end, closes, status } = timing('courses', slot, now);
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
    result: status === 'finished' ? { kind: 'courses', podium } : null,
  };
}

function eventsOfSlot(game: VirtualGameKey, slot: number, now: number): VirtualEvent[] {
  return game === 'football'
    ? Array.from({ length: MATCHES_PER_ROUND }, (_, i) => footballMatch(slot, i, now))
    : [race(slot, now)];
}

/** Épreuves en cours puis à venir (créneaux courant et suivants). */
export function demoEvents(game: VirtualGameKey, now: Date, slots = game === 'football' ? 3 : 5): VirtualEvent[] {
  const { periodMs, durationMs } = SCHEDULE[game];
  const first = Math.floor((now.getTime() - durationMs) / periodMs) + 1;
  return Array.from({ length: slots }, (_, i) => eventsOfSlot(game, first + i, now.getTime()))
    .flat()
    .filter((e) => e.status !== 'finished');
}

/** Dernières épreuves terminées, de la plus récente à la plus ancienne. */
export function demoResults(game: VirtualGameKey, now: Date, slots = game === 'football' ? 2 : 6): VirtualEvent[] {
  const { periodMs, durationMs } = SCHEDULE[game];
  const last = Math.floor((now.getTime() - durationMs) / periodMs);
  return Array.from({ length: slots }, (_, i) => eventsOfSlot(game, last - i, now.getTime())).flat();
}

/** Une épreuve par son identifiant (« football-<créneau>-<n> », « courses-<créneau> ») ; null si inconnu. */
export function demoEvent(id: string, now: Date): VirtualEvent | null {
  const football = /^football-(\d+)-([0-3])$/.exec(id);
  if (football) return footballMatch(Number(football[1]), Number(football[2]), now.getTime());
  const courses = /^courses-(\d+)$/.exec(id);
  if (courses) return race(Number(courses[1]), now.getTime());
  return null;
}
