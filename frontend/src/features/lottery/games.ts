/**
 * Jeux de borlette proposés sur une fiche (même vocabulaire que l'API Borlette du fournisseur) :
 * chaque tirage donne un numéro de 3 chiffres et deux de 2 chiffres, d'où les trois lots de bolèt.
 * Ici seulement la saisie et sa vérification de forme : le serveur décide de tout le reste (vente ouverte,
 * plafonds par boule, gains).
 */
export type LotteryGameKey = 'bolet' | 'maryaj' | 'loto3' | 'loto4' | 'loto5';

export type LotteryGame = {
  key: LotteryGameKey;
  name: string;
  /** Chiffres de chaque numéro saisi, et nombre de numéros (maryaj : deux boules). */
  digits: number;
  count: number;
  /** Options du loto 4 (1e+2e, 2e+3e, 1e+3e lot) et du loto 5 (3 chiffres + 2e ou 3e lot). */
  options: { value: number; label: string }[];
  help: string;
};

export const LOTTERY_GAMES: LotteryGame[] = [
  { key: 'bolet', name: 'Bolèt', digits: 2, count: 1, options: [], help: 'Une boule de 2 chiffres, gagnante au 1er, 2e ou 3e lot.' },
  { key: 'maryaj', name: 'Maryaj', digits: 2, count: 2, options: [], help: 'Deux boules de 2 chiffres qui doivent sortir ensemble.' },
  { key: 'loto3', name: 'Loto 3', digits: 3, count: 1, options: [], help: 'Les 3 chiffres du premier numéro.' },
  {
    key: 'loto4', name: 'Loto 4', digits: 4, count: 1, help: '4 chiffres formés par deux lots.',
    options: [
      { value: 1, label: 'Option 1 : 1er + 2e lot' },
      { value: 2, label: 'Option 2 : 2e + 3e lot' },
      { value: 3, label: 'Option 3 : 1er + 3e lot' },
    ],
  },
  {
    key: 'loto5', name: 'Loto 5', digits: 5, count: 1, help: '5 chiffres : le premier numéro suivi d\'un lot.',
    options: [
      { value: 1, label: 'Option 1 : 3 chiffres + 2e lot' },
      { value: 2, label: 'Option 2 : 3 chiffres + 3e lot' },
    ],
  },
];

export function lotteryGame(key: LotteryGameKey): LotteryGame {
  return LOTTERY_GAMES.find((g) => g.key === key) ?? LOTTERY_GAMES[0];
}

/** Une ligne de la fiche : un jeu, ses numéros (chaînes : « 07 » garde son zéro) et la mise en gourdes entières. */
export type LotteryPlay = {
  id: string;
  game: LotteryGameKey;
  option: number | null;
  numbers: string[];
  stake: number;
};

/** Vérifie la saisie d'une ligne ; renvoie un message d'erreur ou null. */
export function validatePlay(game: LotteryGame, numbers: string[], stake: string, option: number | null): string | null {
  if (numbers.length !== game.count || numbers.some((n) => !new RegExp(`^\\d{${game.digits}}$`).test(n))) {
    return game.count > 1
      ? `Saisissez ${game.count} boules de ${game.digits} chiffres.`
      : `Saisissez un numéro de ${game.digits} chiffres.`;
  }
  if (game.key === 'maryaj' && numbers[0] === numbers[1]) return 'Un maryaj associe deux boules différentes.';
  if (game.options.length > 0 && !game.options.some((o) => o.value === option)) return 'Choisissez une option.';
  if (!/^\d+$/.test(stake.trim()) || Number(stake) < 1) return 'La mise doit être un nombre entier de gourdes (1 au moins).';
  return null;
}

/** Libellé court d'une ligne : « 25 », « 25x07 », « 2507 (opt. 1) ». */
export function playLabel(play: Pick<LotteryPlay, 'game' | 'numbers' | 'option'>): string {
  const numbers = play.numbers.join('x');
  return play.option ? `${numbers} (opt. ${play.option})` : numbers;
}

/** Numéro au hasard de $digits chiffres (bouton « Au hasard »). */
export function randomNumber(digits: number, random: () => number = Math.random): string {
  return String(Math.floor(random() * 10 ** digits)).padStart(digits, '0');
}
