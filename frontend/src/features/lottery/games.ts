/**
 * Jeux de borlette proposés sur une fiche (même vocabulaire que l'API Borlette du fournisseur) :
 * chaque tirage donne un numéro de 3 chiffres et deux de 2 chiffres, d'où les trois lots de bolèt.
 *
 * Le serveur donne les jeux en vente, leurs options, les mises min/max et le barème (GET /lottery/games) ; ici
 * seulement les libellés, la vérification de forme d'une ligne et le calcul affiché du gain possible.
 */
import type { Money } from '../../shared/api/client';
import { formatMoney } from '../../shared/format/money';
import { parseStakeMinor } from '../betting/slip/stake';
import type { LotteryGameKey, LotteryGameOffer } from './api/lottery';

export type { LotteryGameKey } from './api/lottery';

type GameText = { name: string; help: string; options: Record<number, string> };

const GAME_TEXTS: Record<LotteryGameKey, GameText> = {
  bolet: { name: 'Bolèt', help: 'Une boule de 2 chiffres, gagnante au 1er, 2e ou 3e lot.', options: {} },
  maryaj: { name: 'Maryaj', help: 'Deux boules de 2 chiffres qui doivent sortir ensemble.', options: {} },
  loto3: { name: 'Loto 3', help: 'Les 3 chiffres du premier numéro.', options: {} },
  loto4: {
    name: 'Loto 4', help: '4 chiffres formés par deux lots.',
    options: { 1: 'Option 1 : 1er + 2e lot', 2: 'Option 2 : 2e + 3e lot', 3: 'Option 3 : 1er + 3e lot' },
  },
  loto5: {
    name: 'Loto 5', help: '5 chiffres : le premier numéro suivi d\'un lot.',
    options: { 1: 'Option 1 : 3 chiffres + 2e lot', 2: 'Option 2 : 3 chiffres + 3e lot' },
  },
};

/** Nom affiché d'un jeu (« Bolèt »). */
export function gameName(key: LotteryGameKey): string {
  return GAME_TEXTS[key]?.name ?? key;
}

export function gameHelp(key: LotteryGameKey): string {
  return GAME_TEXTS[key]?.help ?? '';
}

export function optionLabel(key: LotteryGameKey, option: number): string {
  return GAME_TEXTS[key]?.options[option] ?? `Option ${option}`;
}

/** Une ligne de la fiche en préparation : jeu, numéros (chaînes : « 07 » garde son zéro), mise en unité mineure. */
export type DraftPlay = {
  id: string;
  game: LotteryGameKey;
  option: number | null;
  numbers: string[];
  stake: Money;
};

/** Vérifie la saisie d'une ligne selon le jeu en vente ; renvoie un message d'erreur ou null. */
export function validatePlay(game: LotteryGameOffer, numbers: string[], stake: string, option: number | null): string | null {
  if (numbers.length !== game.count || numbers.some((n) => !new RegExp(`^\\d{${game.digits}}$`).test(n))) {
    return game.count > 1
      ? `Saisissez ${game.count} boules de ${game.digits} chiffres.`
      : `Saisissez un numéro de ${game.digits} chiffres.`;
  }
  if (game.count > 1 && new Set(numbers).size !== numbers.length) return 'Un maryaj associe deux boules différentes.';
  if (game.options.length > 0 && !game.options.includes(option ?? -1)) return 'Choisissez une option.';
  const minor = parseStakeMinor(stake.trim());
  if (minor === null) return 'Saisissez une mise.';
  if (minor < BigInt(game.min_stake.minor) || minor > BigInt(game.max_stake.minor)) {
    return `La mise doit être comprise entre ${formatMoney(game.min_stake)} et ${formatMoney(game.max_stake)}.`;
  }
  return null;
}

/**
 * Mise × multiplicateur, arrondi au centime inférieur comme le serveur. Le multiplicateur arrive en chaîne
 * (« 50 », « 12.5 ») : calcul en entiers, jamais en nombre décimal.
 */
export function multiplyMinor(stakeMinor: number, multiplier: string): number {
  const match = /^(\d+)(?:\.(\d+))?$/.exec(multiplier.trim());
  if (!match) return 0;
  const frac = match[2] ?? '';
  const scaled = BigInt(match[1] + frac);
  return Number((BigInt(stakeMinor) * scaled) / 10n ** BigInt(frac.length));
}

/** Gains possibles d'une ligne : un par lot pour la bolèt (1er, 2e, 3e), un seul sinon. */
export function possibleWins(game: LotteryGameOffer, stake: Money): { lot: number | null; win: Money }[] {
  return [...game.payouts]
    .sort((a, b) => (a.lot ?? 0) - (b.lot ?? 0))
    .map((p) => ({ lot: p.lot, win: { minor: multiplyMinor(stake.minor, p.multiplier), currency: stake.currency } }));
}

/** « 1er lot », « 2e lot ». */
export function lotLabel(lot: number): string {
  return lot === 1 ? '1er lot' : `${lot}e lot`;
}

/** Libellé court d'une ligne : « 25 », « 25x07 », « 2507 (opt. 1) ». */
export function playLabel(play: { numbers: string[]; option: number | null }): string {
  const numbers = play.numbers.join('x');
  return play.option ? `${numbers} (opt. ${play.option})` : numbers;
}

/** Numéro au hasard de $digits chiffres (bouton « Au hasard »). */
export function randomNumber(digits: number, random: () => number = Math.random): string {
  return String(Math.floor(random() * 10 ** digits)).padStart(digits, '0');
}
