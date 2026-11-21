/** Mise en forme des heures et dates de tirage, toujours dans le fuseau du tirage (heure d'Haïti). */

function dayKey(date: Date, timeZone: string): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
}

/** « aujourd'hui 14:20 », « demain 14:20 » ou « sam. 8 nov. 14:20 ». */
export function formatDrawMoment(iso: string, timeZone: string, now: Date = new Date()): string {
  const date = new Date(iso);
  const time = new Intl.DateTimeFormat('fr-FR', { timeZone, hour: '2-digit', minute: '2-digit' }).format(date);
  const day = dayKey(date, timeZone);
  if (day === dayKey(now, timeZone)) return `aujourd'hui ${time}`;
  if (day === dayKey(new Date(now.getTime() + 86_400_000), timeZone)) return `demain ${time}`;
  const label = new Intl.DateTimeFormat('fr-FR', { timeZone, weekday: 'short', day: 'numeric', month: 'short' }).format(date);
  return `${label} ${time}`;
}

/** Temps restant avant la fermeture : « 2 h 15 min », « 8 min », « moins d'une minute ». */
export function formatCountdown(iso: string, now: Date = new Date()): string {
  const minutes = Math.floor((Date.parse(iso) - now.getTime()) / 60_000);
  if (minutes < 1) return 'moins d\'une minute';
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  if (h === 0) return `${m} min`;
  return m === 0 ? `${h} h` : `${h} h ${m} min`;
}

/** Date d'un résultat ("YYYY-MM-DD") : « jeu. 6 nov. ». */
export function formatResultDate(date: string): string {
  const [y, m, d] = date.split('-').map(Number);
  return new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short' })
    .format(new Date(Date.UTC(y, m - 1, d)));
}
