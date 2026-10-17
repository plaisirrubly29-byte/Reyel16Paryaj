import type { ReactNode } from 'react';
import { Icon, type IconName } from '../../../shared/components/Icon';

const HIGHLIGHTS: { icon: IconName; text: string }[] = [
  { icon: 'bolt', text: 'Cotes actualisées chaque jour' },
  { icon: 'shield', text: 'Cote revérifiée au moment du pari' },
  { icon: 'wallet', text: 'Démonstration : fonds fictifs' },
];

type Props = {
  title: string;
  subtitle?: string;
  /** Icône de l'en-tête, affichée dans un badge (visible seulement sur mobile : le panneau de marque la remplace sur ordinateur). */
  icon: IconName;
  children: ReactNode;
};

/**
 * Habillage commun des pages de connexion, inscription et mot de passe :
 * panneau de marque à gauche (masqué sur mobile) et carte de formulaire à droite.
 * Sur mobile, un badge d'icône et un en-tête centré remplacent le panneau de marque.
 */
export function AuthShell({ title, subtitle, icon, children }: Props) {
  return (
    <div className="auth-page">
      <div className="auth-shell">
        <aside className="auth-visual" aria-hidden="true">
          <div className="auth-visual-brand">
            <span className="brand-mark">R16</span>
            <span className="auth-visual-brand-name">Reyel 16 <span>Payaj</span></span>
          </div>
          <div>
            <h2 className="auth-visual-heading">Pariez en toute confiance</h2>
            <ul className="auth-visual-list">
              {HIGHLIGHTS.map((h) => (
                <li key={h.text}><Icon name={h.icon} size={16} />{h.text}</li>
              ))}
            </ul>
          </div>
          <p className="auth-visual-foot">18+ · Jouez de manière responsable.</p>
        </aside>

        <section className="auth-card stack">
          <div className="auth-card-head">
            <span className="auth-icon-badge" aria-hidden="true"><Icon name={icon} size={22} /></span>
            <h1>{title}</h1>
            {subtitle && <p className="muted">{subtitle}</p>}
          </div>
          {children}
        </section>
      </div>
    </div>
  );
}
