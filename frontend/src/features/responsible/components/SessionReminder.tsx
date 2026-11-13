import { useEffect, useId, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Button } from '../../../shared/components/Button';
import { Icon } from '../../../shared/components/Icon';
import { useLogout, useMe } from '../../auth/api/auth';
import { useResponsibleGaming } from '../api/responsibleGaming';

/** 45 → « 45 min » ; 60 → « 1 h » ; 90 → « 1 h 30 ». */
function formatDuration(minutes: number): string {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  if (h === 0) return `${m} min`;
  return m === 0 ? `${h} h` : `${h} h ${String(m).padStart(2, '0')}`;
}

/**
 * Rappel de durée de session (V2-E06) : toutes les `reminder_minutes` depuis la connexion (`session.started_at`,
 * fourni par le serveur), un message dit au joueur depuis combien de temps il joue. Seuls les rappels échus pendant
 * que la page est ouverte s'affichent : rien au chargement pour une échéance déjà passée.
 */
export function SessionReminder() {
  const { data: user } = useMe();
  const rg = useResponsibleGaming(!!user && !user.is_admin);
  const session = user && !user.is_admin ? rg.data?.session : undefined;
  if (!session?.reminder_minutes || !session.started_at) return null;

  // Nouvel intervalle ou nouvelle connexion : le décompte repart de l'instant présent.
  return <ReminderClock key={`${session.started_at}-${session.reminder_minutes}`} startedAt={session.started_at} intervalMinutes={session.reminder_minutes} />;
}

function ReminderClock({ startedAt, intervalMinutes }: { startedAt: string; intervalMinutes: number }) {
  const start = new Date(startedAt).getTime();
  const interval = intervalMinutes * 60_000;
  // Dernière échéance déjà passée au montage : elle n'est pas rappelée.
  const [lastIndex, setLastIndex] = useState(() => Math.floor(Math.max(0, Date.now() - start) / interval));
  const [shown, setShown] = useState<number | null>(null);

  useEffect(() => {
    const next = start + (lastIndex + 1) * interval;
    const timer = window.setTimeout(() => {
      setLastIndex((i) => i + 1);
      setShown(Math.round((next - start) / 60_000));
    }, Math.max(0, next - Date.now()));
    return () => window.clearTimeout(timer);
  }, [start, interval, lastIndex]);

  return shown === null ? null : <ReminderDialog elapsedMinutes={shown} onClose={() => setShown(null)} />;
}

function ReminderDialog({ elapsedMinutes, onClose }: { elapsedMinutes: number; onClose: () => void }) {
  const titleId = useId();
  const logout = useLogout();
  const navigate = useNavigate();
  useEffect(() => {
    const close = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', close);
    return () => window.removeEventListener('keydown', close);
  }, [onClose]);

  return (
    <div className="session-reminder-backdrop">
      <div className="session-reminder" role="alertdialog" aria-modal="true" aria-labelledby={titleId}>
        <span className="session-reminder-icon" aria-hidden="true"><Icon name="clock" size={26} /></span>
        <h2 id={titleId}>Vous êtes connecté depuis {formatDuration(elapsedMinutes)}</h2>
        <p className="muted">Prenez le temps de faire le point : le jeu doit rester un divertissement.</p>
        <div className="session-reminder-links">
          <Link to="/responsible-gaming/history" onClick={onClose}>Voir mes dépenses</Link>
          <Link to="/responsible-gaming" onClick={onClose}>Faire une pause ou fixer une limite</Link>
        </div>
        <div className="session-reminder-actions">
          <Button autoFocus onClick={onClose}>Continuer</Button>
          <Button
            variant="secondary"
            loading={logout.isPending}
            // On quitte d'abord une éventuelle page protégée : sinon elle redirigerait vers /login.
            onClick={() => { navigate('/', { replace: true }); logout.mutate(undefined, { onSettled: onClose }); }}
          >
            Se déconnecter
          </Button>
        </div>
      </div>
    </div>
  );
}
