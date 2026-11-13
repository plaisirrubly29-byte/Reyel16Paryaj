import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ApiError } from '../../../shared/api/client';
import { Alert } from '../../../shared/components/Alert';
import { Button } from '../../../shared/components/Button';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon } from '../../../shared/components/Icon';
import { Spinner } from '../../../shared/components/Spinner';
import { useRevokeOtherSessions, useRevokeSession, useSessions, type DeviceSession } from '../api/sessions';
import { describeDevice } from '../device';

/** « 9 novembre 2026 à 18:45 » (heure locale du joueur). */
function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/** Appareil déjà déconnecté ailleurs (404) : le but est atteint, la liste relue le confirme. */
const alreadyGone = (err: unknown) => err instanceof ApiError && err.status === 404;

/**
 * Appareils connectés au compte (V2-C06) : le joueur voit où sa session est ouverte et ferme celles qu'il
 * ne reconnaît pas. Le serveur tient la liste et ferme les sessions ; l'appareil en cours se ferme par la déconnexion.
 */
export function DevicesPage() {
  const sessions = useSessions();
  const revokeOthers = useRevokeOtherSessions();
  const [confirming, setConfirming] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  const others = sessions.data?.filter((s) => !s.current) ?? [];

  function revokeAll() {
    revokeOthers.mutate(undefined, {
      onSuccess: () => { setConfirming(false); setNotice('Tous les autres appareils sont déconnectés.'); },
    });
  }

  return (
    <section className="devices stack">
      <header className="rg-head">
        <h1>Appareils connectés</h1>
        <p className="muted">
          Les appareils sur lesquels votre compte est ouvert. Une session se ferme aussi d'elle-même après un temps sans activité.
        </p>
      </header>

      {sessions.isPending && <div className="table-state"><Spinner /></div>}
      {sessions.isError && <ErrorAlert error={sessions.error} onRetry={() => sessions.refetch()} />}
      {sessions.data && (
        <>
          <section className="rg-card" aria-labelledby="devices-list">
            <h2 id="devices-list" className="sr-only">Sessions ouvertes</h2>
            <ul className="device-list">
              {sessions.data.map((session) => (
                <DeviceRow key={session.id} session={session} onRevoked={() => { setNotice('Appareil déconnecté.'); setConfirming(false); }} />
              ))}
            </ul>
            {others.length === 0 && <p className="muted small">Aucun autre appareil n'est connecté à votre compte.</p>}

            {others.length > 0 && (confirming ? (
              <div className="rg-confirm">
                <Alert tone="warning" title="Déconnecter tous les autres appareils">
                  <p>
                    {others.length === 1 ? "L'autre appareil devra" : `Les ${others.length} autres appareils devront`} se reconnecter avec le mot de passe.
                    Cet appareil reste connecté.
                  </p>
                </Alert>
                <ErrorAlert error={revokeOthers.error} />
                <div className="rg-actions">
                  <Button variant="danger" loading={revokeOthers.isPending} onClick={revokeAll}>Confirmer</Button>
                  <Button variant="ghost" disabled={revokeOthers.isPending} onClick={() => setConfirming(false)}>Annuler</Button>
                </div>
              </div>
            ) : (
              <div className="rg-actions">
                <Button variant="secondary" onClick={() => { setConfirming(true); setNotice(null); revokeOthers.reset(); }}>
                  Déconnecter tous les autres appareils
                </Button>
              </div>
            ))}
            {notice && <p className="rg-notice" role="status"><Icon name="check" size={15} />{notice}</p>}
          </section>

          <Alert tone="info" title="Vous ne reconnaissez pas un appareil ?">
            <p>
              Déconnectez-le, puis <Link to="/forgot-password">changez votre mot de passe</Link> : sans le nouveau mot de passe,
              il ne pourra plus se reconnecter.
            </p>
          </Alert>
        </>
      )}
    </section>
  );
}

function DeviceRow({ session, onRevoked }: { session: DeviceSession; onRevoked: () => void }) {
  const revoke = useRevokeSession();
  const device = describeDevice(session.user_agent);
  const error = revoke.error && !alreadyGone(revoke.error) ? revoke.error : null;

  function disconnect() {
    revoke.mutate(session.id, {
      onSuccess: onRevoked,
      onError: (err) => { if (alreadyGone(err)) onRevoked(); },
    });
  }

  return (
    <li className={['device', session.current && 'is-current'].filter(Boolean).join(' ')}>
      <span className="device-icon" aria-hidden="true"><Icon name={device.kind === 'mobile' ? 'phone' : 'monitor'} size={22} /></span>
      <div className="device-text">
        <strong>
          {device.label}
          {session.current && <span className="device-current">Cet appareil</span>}
        </strong>
        <span className="muted small">
          {session.current ? 'Actif maintenant' : `Dernière activité : ${formatDateTime(session.last_active_at)}`}
          {session.ip_address && <> · Adresse IP {session.ip_address}</>}
        </span>
        {error && <ErrorAlert error={error} />}
      </div>
      {!session.current && (
        <Button variant="secondary" className="btn-sm" loading={revoke.isPending} onClick={disconnect} aria-label={`Déconnecter ${device.label}`}>
          Déconnecter
        </Button>
      )}
    </li>
  );
}
