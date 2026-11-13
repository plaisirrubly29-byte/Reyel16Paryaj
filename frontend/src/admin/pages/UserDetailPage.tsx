import { useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ApiError, newIdempotencyKey } from '../../shared/api/client';
import { Alert } from '../../shared/components/Alert';
import { Button } from '../../shared/components/Button';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { MoneyText } from '../../shared/components/MoneyText';
import { Spinner } from '../../shared/components/Spinner';
import { TextField } from '../../shared/components/TextField';
import { formatMoney } from '../../shared/format/money';
import { useMe } from '../../features/auth/api/auth';
import { parseStakeMinor, sanitizeStake } from '../../features/betting/slip/stake';
import { moneySetting, usePlatformSettings } from '../api/settings';
import { ROLE_LABELS, useAdjustUserWallet, useAdminUser, useAdminUserWallet, useChangeUserStatus, useSetStakeLimit, type AdminUser } from '../api/users';
import { initials } from '../roles';
import { PageHeader } from '../components/PageHeader';
import { StatusBadge } from '../components/StatusBadge';

const dateTime = (iso: string | null) => (iso ? new Date(iso).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '—');

/** Fiche d'un compte. Les données personnelles n'apparaissent que si l'API les envoie (permission). */
export function UserDetailPage() {
  const { id = '' } = useParams();
  const user = useAdminUser(id);
  const { data: me } = useMe();
  const u = user.data;

  return (
    <section className="admin-page">
      <PageHeader
        back={{ to: '/admin/users', label: 'Utilisateurs' }}
        title={u ? (
          <span className="user-cell">
            <span className="user-avatar user-avatar-lg" aria-hidden="true">{initials(u.name)}</span>
            {u.name}
          </span>
        ) : 'Compte'}
        subtitle={u ? `${u.is_admin ? 'Administrateur' : 'Joueur'} · n° ${u.id}` : undefined}
        actions={u && (
          <>
            <StatusBadge status={u.status} />
            {me?.permissions.includes('tickets.view') && !u.is_admin && (
              <Link to={`/admin/tickets?user_id=${u.id}`} className="btn btn-secondary btn-sm">Voir ses tickets</Link>
            )}
          </>
        )}
      />

      {user.isPending && <div className="table-state"><Spinner /></div>}
      <ErrorAlert error={user.error} onRetry={() => user.refetch()} />

      {u && (
        <div className="detail-grid">
          <section className="admin-card">
            <h2 className="card-title">Informations du compte</h2>
            <UserDetails user={u} />
          </section>
          <StatusPanel user={u} />
          {!u.is_admin && <StakeLimitPanel user={u} />}
          {!u.is_admin && <WalletPanel user={u} />}
        </div>
      )}
    </section>
  );
}

function UserDetails({ user }: { user: AdminUser }) {
  return (
    <dl className="details">
      <dt>Téléphone</dt><dd className="mono">{user.phone}</dd>
      <dt>Email</dt><dd>{user.email ?? '—'}</dd>
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
    <form className="admin-card stack" onSubmit={submit} noValidate>
      <h2 className="card-title">{target === 'suspended' ? 'Suspendre le compte' : 'Réactiver le compte'}</h2>
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

const DEPOSIT_PERIODS: Record<string, string> = { day: 'sur 24 heures', week: 'sur 7 jours', month: 'sur 30 jours' };

/**
 * Dépôt refusé par une limite de dépôt du joueur (V2-B06, 422 deposit_limit_exceeded) : rien n'est enregistré.
 * Le montant encore acceptable aide l'agent à rendre ou à fractionner un dépôt déjà reçu.
 */
function DepositLimitRefusal({ error, currency }: { error: ApiError; currency: string }) {
  const { period, limit_minor: limit, remaining_minor: remaining } = error.details as { period?: string; limit_minor?: number; remaining_minor?: number };

  return (
    <Alert tone="error" title="Dépôt refusé : limite du joueur">
      <p>
        Ce dépôt dépasse la limite de dépôt que le joueur s'est fixée
        {typeof limit === 'number' && <> ({formatMoney({ minor: limit, currency })} {DEPOSIT_PERIODS[period ?? ''] ?? ''})</>}.
        {' '}Rien n'a été enregistré.
      </p>
      {typeof remaining === 'number' && (
        <p>
          {remaining > 0
            ? <>Montant encore accepté sur la période : <strong>{formatMoney({ minor: remaining, currency })}</strong>.</>
            : 'Le joueur ne peut plus déposer sur cette période.'}
        </p>
      )}
    </Alert>
  );
}

/**
 * Mise maximale par ticket propre à ce joueur (V2-D05, permission users.suspend) : elle baisse la limite générale
 * pour lui seul, ou la retire. Les bornes (mise minimale et maximale générales) sont appliquées par l'API ;
 * elles ne sont rappelées ici que si l'administrateur peut lire les paramètres (settings.view).
 */
function StakeLimitPanel({ user }: { user: AdminUser }) {
  const { data: me } = useMe();
  const canChange = !!me?.permissions.includes('users.suspend');
  const settings = usePlatformSettings(canChange && !!me?.permissions.includes('settings.view'));
  const change = useSetStakeLimit(user.id);
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey);
  // Ce que le dernier envoi a fait, pour l'annoncer (la fiche affiche déjà la nouvelle limite).
  const [applied, setApplied] = useState<'set' | 'removed' | null>(null);
  const [outOfRange, setOutOfRange] = useState(false);

  if (!canChange) return null;

  const min = moneySetting(settings.data, 'betting.min_stake_minor');
  const max = moneySetting(settings.data, 'betting.max_stake_minor');
  const currency = max?.currency ?? 'HTG';
  const current = user.max_stake_minor ?? null;
  const amountMinor = parseStakeMinor(amount);
  const error = change.error instanceof ApiError ? change.error : null;
  const reasonMissing = reason.trim().length < 5;
  // Hors bornes : vérifié ici quand les bornes sont connues, et toujours par le serveur. Son message de
  // validation est technique (unité mineure) : on le remplace par les bornes en gourdes.
  const rangeError = outOfRange || error?.fieldErrors.max_stake_minor
    ? (min && max
      ? `La limite doit être comprise entre ${formatMoney(min)} et ${formatMoney(max)}.`
      : 'La limite doit rester entre la mise minimale et la mise maximale générales.')
    : undefined;

  function send(maxStakeMinor: number | null) {
    setApplied(null);
    setOutOfRange(false);
    if (maxStakeMinor !== null && min && max && (maxStakeMinor < min.minor || maxStakeMinor > max.minor)) {
      change.reset();
      setOutOfRange(true);
      return;
    }
    change.mutate(
      { maxStakeMinor, reason: reason.trim(), idempotencyKey },
      {
        onSuccess: () => { setAmount(''); setReason(''); setIdempotencyKey(newIdempotencyKey()); setApplied(maxStakeMinor === null ? 'removed' : 'set'); },
        onError: (err) => {
          if (err instanceof ApiError && err.status !== 0) setIdempotencyKey(newIdempotencyKey());
        },
      },
    );
  }

  function submit(e: FormEvent) {
    e.preventDefault();
    if (amountMinor !== null) send(Number(amountMinor));
  }

  return (
    <form className="admin-card stack" onSubmit={submit} noValidate aria-labelledby="stake-limit-title">
      <h2 id="stake-limit-title" className="card-title">Mise maximale par ticket</h2>
      <p>
        {current === null
          ? <>Limite générale{max && <> : <strong><MoneyText value={max} /></strong></>}. Aucune limite propre à ce joueur.</>
          : <>Limite propre à ce joueur : <strong><MoneyText value={{ minor: current, currency }} /></strong>{max && <> (limite générale : <MoneyText value={max} />)</>}.</>}
      </p>
      <p className="muted small">
        Baisse la mise maximale pour ce joueur seulement : un pari au-delà est refusé et le joueur voit sa propre limite.
        {min && max && <> Montant entre <MoneyText value={min} /> et <MoneyText value={max} />.</>}
        {' '}L'action est enregistrée dans le journal d'audit avec votre motif.
      </p>

      {applied && <Alert tone="success">{applied === 'set' ? 'Limite appliquée.' : 'Limite retirée : la limite générale s\'applique de nouveau.'}</Alert>}
      {error && error.code !== 'validation_failed' && <ErrorAlert error={error} />}

      <TextField
        label={current === null ? 'Limite pour ce joueur' : 'Nouvelle limite'}
        inputMode="decimal"
        name="max_stake"
        autoComplete="off"
        prefix={currency}
        placeholder="0.00"
        value={amount}
        onChange={(e) => { setAmount(sanitizeStake(e.target.value)); setOutOfRange(false); }}
        error={rangeError}
      />

      <div className={['field', error?.fieldErrors.reason && 'field-invalid'].filter(Boolean).join(' ')}>
        <label htmlFor="stake-limit-reason">Motif de la limite (obligatoire)</label>
        <textarea
          id="stake-limit-reason"
          rows={2}
          maxLength={500}
          placeholder="Ex. mises inhabituelles sur des matchs à faible audience"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          aria-invalid={error?.fieldErrors.reason ? true : undefined}
          aria-describedby={error?.fieldErrors.reason ? 'stake-limit-reason-error' : undefined}
        />
        {error?.fieldErrors.reason && <small id="stake-limit-reason-error" className="field-error" role="alert">{error.fieldErrors.reason}</small>}
      </div>

      <div className="rg-actions">
        <Button type="submit" loading={change.isPending} disabled={amountMinor === null || reasonMissing}>
          {current === null ? 'Fixer la limite' : 'Modifier la limite'}
        </Button>
        {current !== null && (
          <Button variant="secondary" disabled={change.isPending || reasonMissing} onClick={() => send(null)}>Retirer la limite</Button>
        )}
      </div>
    </form>
  );
}

/**
 * Solde du joueur (ledger.view) et dépôt / retrait reçu hors plateforme (wallet.adjust, Super Admin).
 * Le montant est saisi en gourdes ; le solde, le plafond et le solde suffisant sont vérifiés par l'API.
 */
function WalletPanel({ user }: { user: AdminUser }) {
  const { data: me } = useMe();
  const canView = !!me?.permissions.includes('ledger.view');
  const canAdjust = !!me?.permissions.includes('wallet.adjust');
  const wallet = useAdminUserWallet(user.id, canView);
  const adjust = useAdjustUserWallet(user.id);
  const [type, setType] = useState<'deposit' | 'withdrawal'>('deposit');
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey);

  if (!canView && !canAdjust) return null;

  const amountMinor = parseStakeMinor(amount);
  const error = adjust.error instanceof ApiError ? adjust.error : null;

  function submit(e: FormEvent) {
    e.preventDefault();
    if (amountMinor === null) return;
    adjust.mutate(
      { type, amount_minor: Number(amountMinor), reason: reason.trim(), idempotencyKey },
      {
        onSuccess: () => { setAmount(''); setReason(''); setIdempotencyKey(newIdempotencyKey()); },
        onError: (err) => {
          if (err instanceof ApiError && err.status !== 0) setIdempotencyKey(newIdempotencyKey());
        },
      },
    );
  }

  return (
    <section className="admin-card stack">
      <h2 className="card-title">Solde</h2>
      {canView && (
        <>
          {wallet.isPending && <Spinner />}
          <ErrorAlert error={wallet.error} onRetry={() => wallet.refetch()} />
          {wallet.data && (
            <p>
              <strong className="balance"><MoneyText value={wallet.data.balance} /></strong>
              {wallet.data.status === 'frozen' && <span className="badge"> Gelé</span>}
            </p>
          )}
        </>
      )}

      {canAdjust && (
        <form className="stack" onSubmit={submit} noValidate>
          <p className="muted small">
            Enregistrez un dépôt reçu ou un retrait remis au joueur hors plateforme. L'opération apparaît dans son
            historique et dans le journal d'audit avec votre motif.
          </p>

          {adjust.isSuccess && (
            <Alert tone="success">
              {adjust.data.type === 'deposit' ? 'Dépôt enregistré.' : 'Retrait enregistré.'} Nouveau solde : <MoneyText value={adjust.data.balance_after} />
            </Alert>
          )}
          {error?.code === 'deposit_limit_exceeded'
            ? <DepositLimitRefusal error={error} currency={wallet.data?.currency ?? 'HTG'} />
            : error && error.code !== 'validation_failed' && <ErrorAlert error={error} />}

          <div className="field">
            <label htmlFor="wallet-type">Opération</label>
            <select id="wallet-type" value={type} onChange={(e) => setType(e.target.value as 'deposit' | 'withdrawal')}>
              <option value="deposit">Créditer (dépôt)</option>
              <option value="withdrawal">Débiter (retrait)</option>
            </select>
          </div>

          <TextField
            label="Montant"
            inputMode="decimal"
            name="amount"
            autoComplete="off"
            prefix={wallet.data?.currency ?? 'HTG'}
            placeholder="0.00"
            value={amount}
            onChange={(e) => setAmount(sanitizeStake(e.target.value))}
            error={error?.fieldErrors.amount_minor}
          />

          <div className={['field', error?.fieldErrors.reason && 'field-invalid'].filter(Boolean).join(' ')}>
            <label htmlFor="wallet-reason">Motif (obligatoire)</label>
            <textarea
              id="wallet-reason"
              rows={2}
              maxLength={500}
              placeholder="Ex. dépôt MonCash reçu, réf. 123456"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              aria-invalid={error?.fieldErrors.reason ? true : undefined}
            />
            {error?.fieldErrors.reason && <small className="field-error" role="alert">{error.fieldErrors.reason}</small>}
          </div>

          <div>
            <Button type="submit" variant={type === 'deposit' ? 'primary' : 'danger'} loading={adjust.isPending}
              disabled={amountMinor === null || reason.trim().length < 5}>
              {type === 'deposit' ? 'Créditer le solde' : 'Débiter le solde'}
            </Button>
          </div>
        </form>
      )}
    </section>
  );
}
