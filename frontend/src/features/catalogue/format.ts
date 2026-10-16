/** « Aujourd'hui 15:30 », « Demain 17:00 » ou « sam. 18/10 · 15:00 » (heure locale du navigateur). */
export function formatKickoff(iso: string, now: Date = new Date()): { day: string; time: string } {
  const date = new Date(iso);
  const time = date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });

  const startOfDay = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
  const days = Math.round((startOfDay(date) - startOfDay(now)) / 86_400_000);

  const day = days === 0
    ? "Aujourd'hui"
    : days === 1
      ? 'Demain'
      : date.toLocaleDateString('fr-FR', { weekday: 'short', day: '2-digit', month: '2-digit' });

  return { day, time };
}
