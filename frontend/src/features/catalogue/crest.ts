/** Mots trop génériques pour les initiales (« FC Barcelona » → BA, « Real Betis » → RB). */
const IGNORED_WORDS = new Set(['fc', 'cf', 'ac', 'sc', 'rc', 'afc', 'cd', 'ud', 'sd', 'ss', 'as', 'us', 'fk', 'sk', 'if', 'bk', 'club', 'de', 'la', 'el', 'the']);

/** Initiales d'une équipe : premières lettres des deux premiers mots utiles, ou deux lettres d'un mot unique. */
export function teamInitials(name: string): string {
  const words = name.split(/[\s.\-']+/).filter((w) => w && !IGNORED_WORDS.has(w.toLowerCase()) && /\p{L}/u.test(w));
  const letters = words.length >= 2 ? words[0][0] + words[1][0] : (words[0] ?? name).slice(0, 2);
  return letters.toUpperCase();
}

/** Teinte stable (0–359) dérivée du nom : la même équipe garde toujours la même couleur. */
export function teamHue(name: string): number {
  let hash = 0;
  for (const char of name) hash = (hash * 31 + char.codePointAt(0)!) % 360;
  return hash;
}
