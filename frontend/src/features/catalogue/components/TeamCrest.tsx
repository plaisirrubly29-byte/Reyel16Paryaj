import type { CSSProperties } from 'react';
import { teamHue, teamInitials } from '../crest';

/** Écusson générique (bouclier en dégradé et initiales) : aucun logo officiel n'est utilisé. */
export function TeamCrest({ name, size = 'md' }: { name: string; size?: 'sm' | 'md' }) {
  return (
    <span className={`team-crest team-crest-${size}`} style={{ '--crest-hue': teamHue(name) } as CSSProperties} aria-hidden="true">
      <span>{teamInitials(name)}</span>
    </span>
  );
}
