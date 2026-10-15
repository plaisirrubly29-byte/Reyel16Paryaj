import { useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ApiError, newIdempotencyKey } from '../../shared/api/client';
import { Alert } from '../../shared/components/Alert';
import { Button } from '../../shared/components/Button';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Spinner } from '../../shared/components/Spinner';
import { useMe } from '../../features/auth/api/auth';
import { ROLE_LABELS, useAdminUser, useChangeUserStatus, type AdminUser } from '../api/users';
import { StatusBadge } from '../components/StatusBadge';

const dateTime = (iso: string | null) => (iso ? new Date(iso).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '—');

/** Fiche d'un compte. Les données personnelles n'apparaissent que si l'API les envoie (permission). */
export function UserDetailPage() {
  const { id = '' } = useParams();
  const user = useAdminUser(id);

  return (
    <section className="stack">
      <p><Link to="/admin/users">← Utilisateurs</Link></p>

      {user.isPending && <Spinner />}
      <ErrorAlert error={user.error} onRetry={() => user.refetch()} />

      {user.data && (
        <>
          <div className="toolbar">
            <h1>{user.data.name}</h1>
            <StatusBadge status={user.data.status} />
          </div>
          <UserDetails user={user.data} />
          <StatusPanel user={user.data} />
        </>
      )}
    </section>
  );
}

function UserDetails({ user }: { user: AdminUser }) {
  return (
    <dl className="details">
      <dt>N°</dt><dd>{user.id}</dd>
      <dt>Email</dt><dd>{user.email}</dd>
      <dt>Type</dt><dd>{user.is_admin ? 'Administrateur' : 'Joueur'}</dd>
      <dt>Inscription</dt><dd>{dateTime(user.created_at)}</dd>
      <dt>Dernière connexion</dt><dd>{dateTime(user.last_login_at)}</dd>
      <dt>Statut modifié le</dt><dd>{dateTime(user.status_changed_at)}</dd>
      {user.roles !== undefined && (
        <>
          <dt>Rôles</dt>
          <dd>{user.roles.length ? user.roles.map((r) => ROLE_LABELS[r] ?? r).join(', ') : '—'}</dd>
          <dt>Double authentification</dt>
          <dd>{user.mfa_enabled ? 'Activée' : 'Non activée'}</dd>
        </>
      )}
      {user.profile && (
        <>
          <dt>Nom complet</dt><dd>{[user.profile.first_name, user.profile.last_name].filter(Boolean).join(' ') || '—'}</dd>
          <dt>Date de naissance</dt><dd>{user.profile.birth_date ? new Date(`${user.profile.birth_date}T00:00:00`).toLocaleDateString('fr-FR') : '—'}</dd>
          <dt>Téléphone</dt><dd>{user.profile.phone ?? '—'}</dd>
          <dt>Pays</dt><dd>{user.profile.country ?? '—'}</dd>
        </>
      )}
    </dl>
  );
}

/**
 * Suspension / réactivation avec motif. Affiché selon la permission users.suspend ;
 * les règles (pas soi-même, compte admin réservé au Super Admin, auto-exclusion) sont appliquées par l'API.
 */
function StatusPanel({ user }: { user: AdminUser }) {
  const { data: me } = useMe();
  const change = useChangeUserStatus(user.id);
  const [reason, setReason] = useState('');
  // Une clé par intention : conservée si l'on renvoie le même formulaire après une erreur réseau.
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey);

  if (!me?.permissions.includes('users.suspend')) return null;
  if (me.id === user.id) {
    return <Alert tone="info">Vous ne pouvez pas modifier le statut de votre propre compte.</Alert>;
  }
  if (user.status !== 'active' && user.status !== 'suspended') {
    return (
      <Alert tone="info">
        {user.status === 'excluded'
          ? "Auto-exclusion en cours : elle ne peut pas être levée par l'administration."
          : 'Compte fermé : aucune action possible.'}
      </Alert>
    );
  }

  const target = user.status === 'active' ? 'suspended' : 'active';
  const error = change.error instanceof ApiError ? change.error : null;

  function submit(e: FormEvent) {
    e.preventDefault();
    change.mutate(
      { status: target, reason: reason.trim(), idempotencyKey },
      {
        onSuccess: () => { setReason(''); setIdempotencyKey(newIdempotencyKey()); },
        onError: (err) => {
          // Requête refusée (et non perdue) : la prochaine tentative est une nouvelle intention.
          if (err instanceof ApiError && err.status !== 0) setIdempotencyKey(newIdempotencyKey());
        },
      },
    );
  }

  return (
    <form className="panel stack" onSubmit={submit} noValidate>
      <h2>{target === 'suspended' ? 'Suspendre le compte' : 'Réactiver le compte'}</h2>
      <p className="muted small">
        {target === 'suspended'
          ? "Le joueur perd immédiatement l'accès, même avec une session ouverte."
          : 'Le joueur pourra de nouveau se connecter.'}
        {' '}L'action est enregistrée dans le journal d'audit avec votre motif.
      </p>

      {change.isSuccess && <Alert tone="success">Statut mis à jour.</Alert>}
      {error && error.code !== 'validation_failed' && <ErrorAlert error={error} />}

      <div className={['field', error?.fieldErrors.reason && 'field-invalid'].filter(Boolean).join(' ')}>
        <label htmlFor="status-reason">Motif (obligatoire)</label>
        <textarea
          id="status-reason"
          rows={3}
          maxLength={500}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          aria-invalid={error?.fieldErrors.reason ? true : undefined}
          aria-describedby={error?.fieldErrors.reason ? 'status-reason-error' : undefined}
        />
        {error?.fieldErrors.reason && <small id="status-reason-error" className="field-error" role="alert">{error.fieldErrors.reason}</small>}
      </div>

      <div>
        <Button type="submit" variant={target === 'suspended' ? 'danger' : 'primary'} loading={change.isPending} disabled={reason.trim().length < 5}>
          {target === 'suspended' ? 'Suspendre' : 'Réactiver'}
        </Button>
      </div>
    </form>
  );
}
