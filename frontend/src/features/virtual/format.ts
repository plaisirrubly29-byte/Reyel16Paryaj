import type { VirtualEvent, VirtualResult } from './types';

/** Temps restant : « 02:45 », « 1:05:09 » ; « 00:00 » une fois passé. */
export function formatClock(ms: number): string {
  const total = Math.max(0, Math.ceil(ms / 1000));
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  const mmss = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
  return h > 0 ? `${h}:${mmss}` : mmss;
}

/** Heure de départ d'une épreuve, heure locale : « 14:27 ». */
export function formatStartTime(iso: string): string {
  return new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
}

/** Libellé court de l'épreuve : « Kapwa FC – Mòn Wouj » ou « Course 48 ». */
export function eventTitle(event: Pick<VirtualEvent, 'name' | 'home_team' | 'away_team'>): string {
  return event.home_team && event.away_team ? `${event.home_team} – ${event.away_team}` : event.name;
}

/** Résultat en une ligne : « 2 - 1 » ou « 1er 4 Kreyòl Gold, 2e 7 Siklòn, 3e 1 Tonnè ». */
export function resultLabel(result: VirtualResult): string {
  if (result.kind === 'football') return `${result.home} - ${result.away}`;
  return result.podium.map((r, i) => `${i === 0 ? '1er' : `${i + 1}e`} ${r.number} ${r.name}`).join(', ');
}

/** État lisible d'une épreuve à l'heure du serveur. */
export function eventState(event: VirtualEvent, serverNow: number): { label: string; tone: 'open' | 'closed' | 'running' | 'finished' } {
  if (event.status === 'finished') return { label: 'Terminé', tone: 'finished' };
  if (event.status === 'running' || serverNow >= Date.parse(event.starts_at)) return { label: 'En cours', tone: 'running' };
  if (serverNow >= Date.parse(event.betting_closes_at)) return { label: 'Mises fermées', tone: 'closed' };
  return { label: `Départ dans ${formatClock(Date.parse(event.starts_at) - serverNow)}`, tone: 'open' };
}
