import type { ButtonHTMLAttributes } from 'react';
import { Spinner } from './Spinner';

type Props = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
  /** Désactive le bouton et affiche un indicateur : évite les doubles soumissions. */
  loading?: boolean;
  block?: boolean;
};

export function Button({ variant = 'primary', loading = false, block = false, disabled, className, children, type = 'button', ...rest }: Props) {
  const classes = ['btn', `btn-${variant}`, block && 'btn-block', className].filter(Boolean).join(' ');

  return (
    <button type={type} className={classes} disabled={disabled || loading} aria-busy={loading || undefined} {...rest}>
      {loading && <Spinner size="sm" />}
      <span>{children}</span>
    </button>
  );
}
