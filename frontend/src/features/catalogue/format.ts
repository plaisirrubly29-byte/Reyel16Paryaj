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

/** Compte à rebours court avant le coup d'envoi : « Dans 45 min », « Dans 3 h » ; null au-delà de 24 h ou si commencé. */
export function startsIn(iso: string, now: Date = new Date()): string | null {
  const minutes = Math.round((new Date(iso).getTime() - now.getTime()) / 60_000);
  if (minutes <= 0 || minutes >= 24 * 60) return null;
  if (minutes < 60) return `Dans ${minutes} min`;
  return `Dans ${Math.floor(minutes / 60)} h${minutes % 60 >= 5 ? ` ${String(minutes % 60).padStart(2, '0')}` : ''}`;
}

export function isLiveStatus(status: string): boolean {
  return ['live', 'in_play', 'in-play'].includes(status.trim().toLowerCase());
}

/** Affiche clairement un événement dont les cotes sont encore ouvertes pendant le match. */
export function liveLabel(status: string): string | null {
  return isLiveStatus(status) ? 'EN DIRECT' : null;
}
