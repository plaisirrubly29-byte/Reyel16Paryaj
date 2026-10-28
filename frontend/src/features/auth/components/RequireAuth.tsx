import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Spinner } from '../../../shared/components/Spinner';
import { useMe } from '../api/auth';

/**
 * Protège une page : redirige vers /login (avec retour prévu) si personne n'est connecté.
 * Confort d'affichage uniquement — l'API refuse elle-même tout accès non autorisé.
 */
export function RequireAuth({ children }: { children: ReactNode }) {
  const me = useMe();
  const location = useLocation();

  if (me.isPending) {
    return <div className="table-state"><Spinner /></div>;
  }

  if (me.isError) {
    return <ErrorAlert error={me.error} onRetry={() => me.refetch()} />;
  }

  if (!me.data) {
    const redirect = encodeURIComponent(location.pathname + location.search);
    return <Navigate to={`/login?redirect=${redirect}`} replace />;
  }

  return <>{children}</>;
}
