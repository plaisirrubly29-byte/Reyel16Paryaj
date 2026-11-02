/** Codes pays ISO 3166-1 alpha-2 acceptés par l'API (champ `country` de l'inscription). */
const CODES = (
  'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ '
  + 'CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO '
  + 'FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE '
  + 'JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO '
  + 'MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW '
  + 'PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM '
  + 'TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW'
).split(' ');

/** Pays proposé par défaut (et en tête de liste) : le site s'adresse d'abord aux joueurs d'Haïti. */
export const DEFAULT_COUNTRY = 'HT';

export type CountryOption = { code: string; name: string };

/** Liste des pays pour un champ de sélection : Haïti d'abord, puis les autres par nom (noms fournis par le navigateur). */
export function countryOptions(locale = 'fr'): CountryOption[] {
  const names = new Intl.DisplayNames([locale], { type: 'region' });
  const name = (code: string) => names.of(code) ?? code;
  const others = CODES
    .filter((code) => code !== DEFAULT_COUNTRY)
    .map((code) => ({ code, name: name(code) }))
    .sort((a, b) => a.name.localeCompare(b.name, locale));

  return [{ code: DEFAULT_COUNTRY, name: name(DEFAULT_COUNTRY) }, ...others];
}
