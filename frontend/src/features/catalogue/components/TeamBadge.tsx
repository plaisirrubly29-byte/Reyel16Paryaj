import type { CSSProperties } from 'react';

/** Écusson générique d'une équipe : initiales sur une teinte stable dérivée du nom (pas de logo officiel). */
export function TeamBadge({ name }: { name: string }) {
  const words = name.replace(/[^\p{L}\p{N} ]/gu, ' ').split(' ').filter(Boolean);
  const initials = (words.length > 1 ? words[0][0] + words[words.length - 1][0] : name.slice(0, 2)).toUpperCase();

  let hash = 0;
  for (const char of name) hash = (hash * 31 + char.charCodeAt(0)) % 360;

  return (
    <span className="team-badge" style={{ '--team-hue': hash } as CSSProperties} aria-hidden="true">
      {initials}
    </span>
  );
}
