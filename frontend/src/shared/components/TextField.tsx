import { useId, useState, type InputHTMLAttributes, type ReactNode, type Ref } from 'react';
import { Icon, type IconName } from './Icon';

type Props = InputHTMLAttributes<HTMLInputElement> & {
  /** Transmis au champ (React 19 : `ref` est une prop), ex. pour y remettre le curseur après une erreur. */
  ref?: Ref<HTMLInputElement>;
  label: string;
  /** Message d'erreur (ex. ApiError.fieldErrors[name]). */
  error?: string;
  hint?: ReactNode;
  /** Icône décorative affichée dans un badge au début du champ (exclusif avec `prefix`). */
  icon?: IconName;
  /** Texte fixe affiché dans un badge au début du champ (ex. « +509 » pour un numéro local), exclusif avec `icon`. */
  prefix?: string;
  /**
   * Masque visuellement le libellé (reste associé au champ pour les lecteurs d'écran) : le `placeholder`
   * le remplace à l'écran, dans l'esprit d'un écran de connexion mobile épuré.
   */
  labelHidden?: boolean;
  /** Champ mot de passe : bouton « œil » pour afficher ou masquer la saisie (pratique sur téléphone). */
  revealable?: boolean;
};

export function TextField({ label, error, hint, id, className, icon, prefix, labelHidden, revealable, type, ...rest }: Props) {
  const generatedId = useId();
  const inputId = id ?? generatedId;
  const [revealed, setRevealed] = useState(false);
  const describedBy = [error && `${inputId}-error`, hint && `${inputId}-hint`].filter(Boolean).join(' ') || undefined;
  const canReveal = revealable === true && type === 'password';
  const controlClass = [
    'field-control',
    canReveal && 'field-control-reveal',
    !icon && !prefix && 'field-control-plain',
  ].filter(Boolean).join(' ');

  return (
    <div className={['field', error && 'field-invalid', className].filter(Boolean).join(' ')}>
      <label htmlFor={inputId} className={labelHidden ? 'sr-only' : undefined}>{label}</label>
      <div className={icon || prefix || canReveal ? controlClass : undefined}>
        {icon && <span className="field-icon-badge" aria-hidden="true"><Icon name={icon} size={17} /></span>}
        {prefix && <span className="field-icon-badge field-icon-badge-text" aria-hidden="true">{prefix}</span>}
        <input
          id={inputId}
          type={canReveal && revealed ? 'text' : type}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
          {...rest}
        />
        {canReveal && (
          <button
            type="button"
            className="field-reveal"
            onClick={() => setRevealed((v) => !v)}
            aria-label={revealed ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
            aria-pressed={revealed}
            aria-controls={inputId}
          >
            <Icon name={revealed ? 'eyeOff' : 'eye'} size={18} />
          </button>
        )}
      </div>
      {hint && !error && <small id={`${inputId}-hint`} className="field-hint">{hint}</small>}
      {error && <small id={`${inputId}-error`} className="field-error" role="alert">{error}</small>}
    </div>
  );
}
