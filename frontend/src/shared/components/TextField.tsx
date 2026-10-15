import { useId, type InputHTMLAttributes } from 'react';

type Props = InputHTMLAttributes<HTMLInputElement> & {
  label: string;
  /** Message d'erreur (ex. ApiError.fieldErrors[name]). */
  error?: string;
  hint?: string;
};

export function TextField({ label, error, hint, id, className, ...rest }: Props) {
  const generatedId = useId();
  const inputId = id ?? generatedId;
  const describedBy = [error && `${inputId}-error`, hint && `${inputId}-hint`].filter(Boolean).join(' ') || undefined;

  return (
    <div className={['field', error && 'field-invalid', className].filter(Boolean).join(' ')}>
      <label htmlFor={inputId}>{label}</label>
      <input id={inputId} aria-invalid={error ? true : undefined} aria-describedby={describedBy} {...rest} />
      {hint && !error && <small id={`${inputId}-hint`} className="field-hint">{hint}</small>}
      {error && <small id={`${inputId}-error`} className="field-error" role="alert">{error}</small>}
    </div>
  );
}
