import { useState, type FormEvent, type ReactNode } from 'react';
import { ApiError, newIdempotencyKey } from '../../shared/api/client';
import { Alert } from '../../shared/components/Alert';
import { Button } from '../../shared/components/Button';
import { ErrorAlert } from '../../shared/components/ErrorAlert';

type Props = {
  /** Préfixe des identifiants du formulaire (plusieurs formulaires par page). */
  id: string;
  title: string;
  description?: ReactNode;
  submitLabel: string;
  variant?: 'primary' | 'secondary' | 'danger';
  /** Champs supplémentaires, affichés avant le motif. */
  children?: ReactNode;
  /** Désactive l'envoi (champs supplémentaires incomplets). */
  incomplete?: boolean;
  successMessage?: string;
  /** Envoie l'action ; une erreur rejetée est affichée sous le formulaire. */
  onSubmit: (reason: string, idempotencyKey: string) => Promise<unknown>;
};

/**
 * Action sensible avec motif obligatoire (5 caractères au moins), enregistré dans le journal d'audit.
 * Une clé d'idempotence par intention : conservée après une erreur réseau (le même envoi peut être rejoué sans
 * doublon), renouvelée après un refus du serveur ou un succès.
 */
export function ReasonForm({ id, title, description, submitLabel, variant = 'primary', children, incomplete = false, successMessage, onSubmit }: Props) {
  const [reason, setReason] = useState('');
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [done, setDone] = useState(false);

  const apiError = error instanceof ApiError ? error : null;
  const reasonError = apiError?.fieldErrors.reason;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setPending(true);
    setError(null);
    setDone(false);
    try {
      await onSubmit(reason.trim(), idempotencyKey);
      setReason('');
      setIdempotencyKey(newIdempotencyKey());
      setDone(true);
    } catch (err) {
      setError(err);
      if (err instanceof ApiError && err.status !== 0) setIdempotencyKey(newIdempotencyKey());
    } finally {
      setPending(false);
    }
  }

  return (
    <form className="stack" onSubmit={submit} noValidate aria-labelledby={`${id}-title`}>
      <h3 id={`${id}-title`} className="card-title">{title}</h3>
      {description && <p className="muted small">{description}</p>}

      {done && successMessage && <Alert tone="success">{successMessage}</Alert>}
      {error !== null && apiError?.code !== 'validation_failed' && <ErrorAlert error={error} />}

      {children}

      <div className={['field', reasonError && 'field-invalid'].filter(Boolean).join(' ')}>
        <label htmlFor={`${id}-reason`}>Motif (obligatoire)</label>
        <textarea
          id={`${id}-reason`}
          rows={3}
          maxLength={500}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          aria-invalid={reasonError ? true : undefined}
          aria-describedby={reasonError ? `${id}-reason-error` : undefined}
        />
        {reasonError && <small id={`${id}-reason-error`} className="field-error" role="alert">{reasonError}</small>}
        <small className="muted">Enregistré dans le journal d'audit avec votre nom.</small>
      </div>

      <div>
        <Button type="submit" variant={variant} loading={pending} disabled={incomplete || reason.trim().length < 5}>
          {submitLabel}
        </Button>
      </div>
    </form>
  );
}
