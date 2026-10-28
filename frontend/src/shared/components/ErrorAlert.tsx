import { ApiError } from '../api/client';
import { Alert } from './Alert';

type Props = {
  error: unknown;
  /** Proposé quand l'erreur peut être temporaire (réseau, 5xx). */
  onRetry?: () => void;
};

/**
 * Affiche une erreur de l'API avec son message (déjà rédigé côté serveur, sans détail interne).
 * Les erreurs de validation par champ s'affichent dans les formulaires (TextField.error) :
 * ici, seul le message général est montré.
 */
export function ErrorAlert({ error, onRetry }: Props) {
  if (!error) return null;

  const apiError = error instanceof ApiError ? error : null;
  const message = apiError?.message ?? 'Une erreur inattendue est survenue.';
  const retryable = !apiError || apiError.status === 0 || apiError.status >= 500;

  return (
    <Alert tone="error" title={titleFor(apiError)}>
      <p>{message}</p>
      {onRetry && retryable && (
        <button type="button" className="link-button" onClick={onRetry}>Réessayer</button>
      )}
    </Alert>
  );
}

function titleFor(error: ApiError | null): string {
  switch (error?.code) {
    case 'network_error': return 'Connexion impossible';
    case 'unauthenticated': return 'Session expirée';
    case 'forbidden': return 'Accès refusé';
    case 'not_found': return 'Introuvable';
    case 'validation_failed': return 'Vérifiez le formulaire';
    case 'too_many_requests': return 'Trop de tentatives';
    default: return error && error.status < 500 ? 'Action impossible' : 'Erreur du serveur';
  }
}
