import { useId, type InputHTMLAttributes } from 'react';
import { Icon, type IconName } from './Icon';

type Props = InputHTMLAttributes<HTMLInputElement> & {
  label: string;
  /** Message d'erreur (ex. ApiError.fieldErrors[name]). */
  error?: string;
  hint?: string;
  /** Icône décorative affichée au début du champ (exclusif avec `prefix`). */
  icon?: IconName;
  /** Texte fixe affiché au début du champ (ex. « +509 » pour un numéro local), exclusif avec `icon`. */
  prefix?: string;
};

export function TextField({ label, error, hint, id, className, icon, prefix, ...rest }: Props) {
  const generatedId = useId();
  const inputId = id ?? generatedId;
  const describedBy = [error && `${inputId}-error`, hint && `${inputId}-hint`].filter(Boolean).join(' ') || undefined;

  return (
    <div className={['field', error && 'field-invalid', className].filter(Boolean).join(' ')}>
      <label htmlFor={inputId}>{label}</label>
      <div className={icon || prefix ? 'field-control' : undefined}>
        {icon && <Icon name={icon} size={18} />}
        {prefix && <span className="field-prefix" aria-hidden="true">{prefix}</span>}
        <input
          id={inputId}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
          className={prefix ? 'has-prefix' : undefined}
          {...rest}
        />
      </div>
      {hint && !error && <small id={`${inputId}-hint`} className="field-hint">{hint}</small>}
      {error && <small id={`${inputId}-error`} className="field-error" role="alert">{error}</small>}
    </div>
  );
}
