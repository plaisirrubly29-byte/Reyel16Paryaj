import { useId, type InputHTMLAttributes } from 'react';
import { Icon, type IconName } from './Icon';

type Props = InputHTMLAttributes<HTMLInputElement> & {
  label: string;
  /** Message d'erreur (ex. ApiError.fieldErrors[name]). */
  error?: string;
  hint?: string;
  /** Icône décorative affichée dans un badge au début du champ (exclusif avec `prefix`). */
  icon?: IconName;
  /** Texte fixe affiché dans un badge au début du champ (ex. « +509 » pour un numéro local), exclusif avec `icon`. */
  prefix?: string;
  /**
   * Masque visuellement le libellé (reste associé au champ pour les lecteurs d'écran) : le `placeholder`
   * le remplace à l'écran, dans l'esprit d'un écran de connexion mobile épuré.
   */
  labelHidden?: boolean;
};

export function TextField({ label, error, hint, id, className, icon, prefix, labelHidden, ...rest }: Props) {
  const generatedId = useId();
  const inputId = id ?? generatedId;
  const describedBy = [error && `${inputId}-error`, hint && `${inputId}-hint`].filter(Boolean).join(' ') || undefined;

  return (
    <div className={['field', error && 'field-invalid', className].filter(Boolean).join(' ')}>
      <label htmlFor={inputId} className={labelHidden ? 'sr-only' : undefined}>{label}</label>
      <div className={icon || prefix ? 'field-control' : undefined}>
        {icon && <span className="field-icon-badge" aria-hidden="true"><Icon name={icon} size={17} /></span>}
        {prefix && <span className="field-icon-badge field-icon-badge-text" aria-hidden="true">{prefix}</span>}
        <input id={inputId} aria-invalid={error ? true : undefined} aria-describedby={describedBy} {...rest} />
      </div>
      {hint && !error && <small id={`${inputId}-hint`} className="field-hint">{hint}</small>}
      {error && <small id={`${inputId}-error`} className="field-error" role="alert">{error}</small>}
    </div>
  );
}
