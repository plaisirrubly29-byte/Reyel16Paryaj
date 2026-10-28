import type { ReactNode } from 'react';
import { Link, Navigate, useLocation } from 'react-router-dom';
import { Alert } from '../../shared/components/Alert';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Spinner } from '../../shared/components/Spinner';
import { useMe } from '../../features/auth/api/auth';
import { MfaSetupPage } from '../pages/MfaSetupPage';
import { MfaVerifyPage } from '../pages/MfaVerifyPage';

/**
 * Entrée de l'administration : session → rôle administratif → MFA activé → MFA validé pour la session.
 * Reflète les contrôles de l'API (middlewares auth, mfa, permission) qui restent la seule vraie barrière.
 */
export function RequireAdmin({ children }: { children: ReactNode }) {
  const me = useMe();
  const location = useLocation();

  if (me.isPending) return <div className="table-state"><Spinner /></div>;
  if (me.isError) return <div className="content"><ErrorAlert error={me.error} onRetry={() => me.refetch()} /></div>;

  const user = me.data;
  if (!user) {
    return <Navigate to={`/login?redirect=${encodeURIComponent(location.pathname + location.search)}`} replace />;
  }

  if (!user.is_admin) {
    return (
      <div className="content">
        <Alert tone="error" title="Accès refusé">Cet espace est réservé aux administrateurs. <Link to="/">Retour au site</Link></Alert>
      </div>
    );
  }

  if (!user.mfa.enabled) return <div className="content"><MfaSetupPage /></div>;
  if (!user.mfa.verified) return <div className="content"><MfaVerifyPage /></div>;

  return <>{children}</>;
}

/** Section réservée à certaines permissions (l'URL peut être saisie directement). */
export function RequirePermission({ permissions, children }: { permissions: string[]; children: ReactNode }) {
  const { data: user } = useMe();

  if (!user || !permissions.some((p) => user.permissions.includes(p))) {
    return <Alert tone="error" title="Accès refusé">Votre rôle ne donne pas accès à cette section.</Alert>;
  }

  return <>{children}</>;
}
