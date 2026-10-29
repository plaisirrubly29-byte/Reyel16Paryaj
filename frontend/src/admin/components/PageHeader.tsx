import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { Icon } from '../../shared/components/Icon';

type Props = {
  title: ReactNode;
  subtitle?: ReactNode;
  /** Lien de retour (fiche → liste). */
  back?: { to: string; label: string };
  /** Badges ou boutons alignés à droite du titre. */
  actions?: ReactNode;
};

/** En-tête commun des écrans d'administration : retour, titre, sous-titre et actions. */
export function PageHeader({ title, subtitle, back, actions }: Props) {
  return (
    <header className="page-head">
      {back && (
        <Link to={back.to} className="page-back">
          <Icon name="chevron" size={14} />
          {back.label}
        </Link>
      )}
      <div className="page-head-row">
        <div className="page-head-text">
          <h1>{title}</h1>
          {subtitle && <p>{subtitle}</p>}
        </div>
        {actions && <div className="page-head-actions">{actions}</div>}
      </div>
    </header>
  );
}
