import { useId, type SelectHTMLAttributes } from 'react';
import { Icon, type IconName } from './Icon';

type Props = SelectHTMLAttributes<HTMLSelectElement> & {
  label: string;
  options: { value: string; label: string }[];
  /** Message d'erreur (ex. ApiError.fieldErrors[name]). */
  error?: string;
  hint?: string;
  /** Icône décorative affichée dans un badge au début du champ, comme TextField. */
  icon?: IconName;
};

/** Liste déroulante avec libellé, indication et erreur : même présentation que TextField. */
export function SelectField({ label, options, error, hint, id, className, icon, ...rest }: Props) {
  const generatedId = useId();
  const selectId = id ?? generatedId;
  const describedBy = [error && `${selectId}-error`, hint && `${selectId}-hint`].filter(Boolean).join(' ') || undefined;

  return (
    <div className={['field', error && 'field-invalid', className].filter(Boolean).join(' ')}>
      <label htmlFor={selectId}>{label}</label>
      <div className={icon ? 'field-control' : undefined}>
        {icon && <span className="field-icon-badge" aria-hidden="true"><Icon name={icon} size={17} /></span>}
        <select id={selectId} aria-invalid={error ? true : undefined} aria-describedby={describedBy} {...rest}>
          {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
      </div>
      {hint && !error && <small id={`${selectId}-hint`} className="field-hint">{hint}</small>}
      {error && <small id={`${selectId}-error`} className="field-error" role="alert">{error}</small>}
    </div>
  );
}
