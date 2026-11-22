/**
 * Date de naissance saisie au clavier au format JJ/MM/AAAA (inscription).
 * Le serveur attend toujours AAAA-MM-JJ (RegisterRequest : date_format:Y-m-d) : la conversion se fait ici.
 */

/**
 * Âge minimum pour ouvrir un compte : miroir de IDENTITY_MINIMUM_AGE (18 par défaut, config/identity.php).
 * Le serveur reste l'autorité : s'il exige plus, son message s'affiche sous le champ.
 */
export const MINIMUM_AGE = 18;

export const BIRTH_DATE_FORMAT = 'JJ/MM/AAAA';

/**
 * Masque de saisie : ne garde que les chiffres (8 au plus) et place les « / » tout seul.
 * - « / » ajouté dès que le jour puis le mois sont complets, sauf quand le joueur efface (sinon il ne pourrait
 *   pas revenir en arrière) ;
 * - un jour ou un mois d'un seul chiffre suivi d'un séparateur est complété par un zéro (« 5/3/1998 » → « 05/03/1998 ») ;
 * - une date ISO collée ou remplie par le navigateur (« 1998-05-21 ») est remise dans l'ordre JJ/MM/AAAA.
 */
export function maskBirthDate(raw: string, previous = ''): string {
  const iso = /^\s*(\d{4})-(\d{2})-(\d{2})\s*$/.exec(raw);
  if (iso) return `${iso[3]}/${iso[2]}/${iso[1]}`;

  const deleting = raw.length < previous.length;
  const padded = raw
    .replace(/[.\-\s]/g, '/')
    .replace(/^(\d)\//, '0$1/')
    .replace(/^(\d{2})\/(\d)\//, '$1/0$2/');
  const digits = padded.replace(/\D/g, '').slice(0, 8);

  let out = digits.slice(0, 2);
  if (digits.length > 2 || (digits.length === 2 && !deleting)) out += '/';
  out += digits.slice(2, 4);
  if (digits.length > 4 || (digits.length === 4 && !deleting)) out += '/';
  return out + digits.slice(4);
}

export type BirthDateCheck = { ok: true; iso: string } | { ok: false; message: string };

const pad = (n: number) => String(n).padStart(2, '0');
const toIso = (date: Date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

/**
 * Vérifie la date saisie et la convertit en AAAA-MM-JJ pour l'API.
 * Mêmes règles que le serveur : date réelle, pas dans le futur, âge minimum atteint (jour d'anniversaire compris).
 */
export function checkBirthDate(text: string, today: Date = new Date(), minimumAge = MINIMUM_AGE): BirthDateCheck {
  const value = text.trim();
  if (value === '') return { ok: false, message: `Saisissez votre date de naissance (${BIRTH_DATE_FORMAT}).` };

  const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value);
  if (!match) return { ok: false, message: `Saisissez la date au format ${BIRTH_DATE_FORMAT}, par exemple 21/05/1998.` };

  const [day, month, year] = [Number(match[1]), Number(match[2]), Number(match[3])];
  if (month < 1 || month > 12) return { ok: false, message: 'Le mois doit être compris entre 01 et 12.' };
  // new Date(2000, 1, 31) glisse au 2 mars : si le jour ou le mois change, la date n'existe pas (31/02, 29/02/2001…).
  const date = new Date(year, month - 1, day);
  if (day < 1 || date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
    return { ok: false, message: `Le ${match[1]}/${match[2]}/${match[3]} n'existe pas : vérifiez le jour et le mois.` };
  }

  const iso = toIso(date);
  const todayIso = toIso(today);
  if (iso > todayIso) return { ok: false, message: 'La date de naissance ne peut pas être dans le futur.' };
  if (year < 1900) return { ok: false, message: "Vérifiez l'année de naissance." };

  // Date limite = aujourd'hui moins l'âge minimum (un 29 février sans équivalent glisse au 1er mars, comme côté serveur).
  const limit = toIso(new Date(today.getFullYear() - minimumAge, today.getMonth(), today.getDate()));
  if (iso > limit) return { ok: false, message: `Vous devez avoir au moins ${minimumAge} ans pour ouvrir un compte.` };

  return { ok: true, iso };
}
