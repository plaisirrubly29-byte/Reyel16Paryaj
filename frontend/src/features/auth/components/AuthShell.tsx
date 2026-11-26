import type { ReactNode } from 'react';
import { BrandLogo } from '../../../app/layouts/BrandLogo';
import { Icon, type IconName } from '../../../shared/components/Icon';

const HIGHLIGHTS: { icon: IconName; text: string }[] = [
  { icon: 'bolt', text: 'Cotes actualisées chaque jour' },
  { icon: 'shield', text: 'Cote revérifiée au moment du pari' },
  { icon: 'wallet', text: 'Solde et historique toujours à jour' },
];

type Props = {
  title: string;
  subtitle?: string;
  /** Icône de l'en-tête (ordinateur sans panneau de marque, pages secondaires). */
  icon: IconName;
  children: ReactNode;
  /** Panneau de marque (ordinateur) : texte joueur par défaut, remplacé pour l'espace administrateur. */
  visual?: { heading: string; highlights: { icon: IconName; text: string }[] };
};

/**
 * Habillage commun des pages de connexion, inscription et mot de passe :
 * - ordinateur : panneau de marque à gauche et carte de formulaire à droite ;
 * - mobile : bandeau bordeaux avec le logo et le titre, puis le formulaire sur une feuille blanche arrondie
 *   qui remonte sur le bandeau, comme l'écran d'accueil d'une application.
 */
export function AuthShell({ title, subtitle, icon, children, visual = { heading: 'Pariez en toute confiance', highlights: HIGHLIGHTS } }: Props) {
  return (
    <div className="auth-page">
      <div className="auth-shell">
        <aside className="auth-visual" aria-hidden="true">
          <div className="auth-visual-brand">
            <BrandLogo large />
          </div>
          <div>
            <h2 className="auth-visual-heading">{visual.heading}</h2>
            <ul className="auth-visual-list">
              {visual.highlights.map((h) => (
                <li key={h.text}><Icon name={h.icon} size={16} />{h.text}</li>
              ))}
            </ul>
          </div>
          <p className="auth-visual-foot">18+ · Jouez de manière responsable.</p>
        </aside>

        <section className="auth-card stack">
          <div className="auth-card-head">
            <span className="auth-hero-logo" aria-hidden="true"><BrandLogo /></span>
            <span className="auth-icon-badge" aria-hidden="true"><Icon name={icon} size={22} /></span>
            <h1>{title}</h1>
            {subtitle && <p className="muted">{subtitle}</p>}
          </div>
          <div className="auth-sheet stack">{children}</div>
        </section>
      </div>
    </div>
  );
}
