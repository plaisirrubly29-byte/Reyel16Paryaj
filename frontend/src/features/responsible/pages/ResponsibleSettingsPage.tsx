import { useId, useState, type FormEvent, type ReactNode } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ApiError, newIdempotencyKey } from '../../../shared/api/client';
import { Alert } from '../../../shared/components/Alert';
import { Button } from '../../../shared/components/Button';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon, type IconName } from '../../../shared/components/Icon';
import { MoneyText } from '../../../shared/components/MoneyText';
import { Spinner } from '../../../shared/components/Spinner';
import { TextField } from '../../../shared/components/TextField';
import { parseStakeMinor, sanitizeStake } from '../../betting/slip/stake';
import {
  durationLabel, PERIODS, useResponsibleGaming, usePause, useSelfExclude, useSetLimit,
  type LimitPeriod, type ResponsibleGaming, type StakeLimit,
} from '../api/responsibleGaming';

/** « 9 novembre 2026 à 18:45 » (heure locale du joueur). */
function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/** Requête refusée (et non perdue) : la prochaine tentative est une nouvelle intention. */
const refused = (err: unknown) => err instanceof ApiError && err.status !== 0;

/**
 * Jeu responsable du joueur connecté (P7-02) : limites de mise, pause, auto-exclusion.
 * Le serveur applique ces règles (POST /bets) et calcule les mises de la période : l'écran les affiche tels quels.
 */
export function ResponsibleSettingsPage() {
  const rg = useResponsibleGaming();

  return (
    <section className="rg stack">
      <header className="rg-head">
        <h1>Jeu responsable</h1>
        <p className="muted">
          Fixez-vous des limites, faites une pause ou excluez-vous du jeu : ces réglages s'appliquent à tous vos paris.{' '}
          <Link to="/jeu-responsable">Conseils et aide</Link>
        </p>
      </header>

      {rg.isPending && <div className="table-state"><Spinner /></div>}
      {rg.isError && <ErrorAlert error={rg.error} onRetry={() => rg.refetch()} />}
      {rg.data && (
        <>
          {rg.data.pause.active && rg.data.pause.ends_at && (
            <Alert tone="warning" title="Paris en pause">
              <p>Vous ne pouvez pas parier jusqu'au {formatDateTime(rg.data.pause.ends_at)}. Votre solde et vos tickets restent accessibles.</p>
            </Alert>
          )}
          <LimitsCard state={rg.data} />
          <PauseCard state={rg.data} />
          <ExclusionCard durations={rg.data.options.exclusion_durations} />
        </>
      )}
    </section>
  );
}

function Card({ id, icon, title, intro, tone, children }: { id: string; icon: IconName; title: string; intro: string; tone?: 'danger'; children: ReactNode }) {
  return (
    <section className={['rg-card', tone && `rg-card-${tone}`].filter(Boolean).join(' ')} aria-labelledby={id}>
      <header className="money-card-head">
        <span className="money-card-icon" aria-hidden="true"><Icon name={icon} size={22} /></span>
        <div>
          <h2 id={id}>{title}</h2>
          <p className="muted small">{intro}</p>
        </div>
      </header>
      {children}
    </section>
  );
}

function LimitsCard({ state }: { state: ResponsibleGaming }) {
  const delay = state.options.limit_increase_delay_days;

  return (
    <Card
      id="rg-limits"
      icon="scale"
      title="Limites de mise"
      intro={`Le total de vos mises ne pourra pas dépasser ces montants. Une baisse s'applique tout de suite ; une hausse ou une suppression après ${delay} jours de réflexion.`}
    >
      <div className="rg-limits">
        {PERIODS.map(({ period, label }) => (
          <LimitRow key={period} period={period} label={label} limit={state.limits[period]} />
        ))}
      </div>
    </Card>
  );
}

function LimitRow({ period, label, limit }: { period: LimitPeriod; label: string; limit: StakeLimit }) {
  const setLimit = useSetLimit();
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState('');
  const [invalid, setInvalid] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey);
  const error = setLimit.error instanceof ApiError ? setLimit.error : null;

  function send(amountMinor: number | null) {
    setLimit.mutate({ period, amountMinor, idempotencyKey }, {
      onSuccess: (state) => {
        const pending = state.limits[period].pending;
        // La date d'effet d'une hausse ou d'une suppression est affichée juste au-dessus (pending).
        setNotice(pending ? `${pending.amount ? 'Hausse' : 'Suppression'} enregistrée, après le délai de réflexion.` : 'Limite appliquée.');
        setEditing(false);
        setIdempotencyKey(newIdempotencyKey());
      },
      onError: (err) => { if (refused(err)) setIdempotencyKey(newIdempotencyKey()); },
    });
  }

  function submit(e: FormEvent) {
    e.preventDefault();
    const minor = parseStakeMinor(value);
    setInvalid(minor === null);
    if (minor !== null) send(Number(minor));
  }

  function open() {
    setEditing(true);
    setNotice(null);
    setInvalid(false);
    setLimit.reset();
    setValue('');
  }

  return (
    <div className="rg-limit">
      <div className="rg-limit-head">
        <h3>{label}</h3>
        <strong>{limit.amount ? <MoneyText value={limit.amount} /> : <span className="muted">Aucune limite</span>}</strong>
      </div>

      {limit.amount && (
        <progress className="rg-progress" value={limit.used.minor} max={limit.amount.minor} aria-label={`Mises ${label.toLowerCase()}`} />
      )}
      <p className="rg-usage">
        Misé : <MoneyText value={limit.used} />
        {limit.remaining && <> · Reste : <MoneyText value={limit.remaining} /></>}
      </p>
      {limit.pending && (
        <p className="rg-pending">
          <Icon name="clock" size={15} />
          {limit.pending.amount
            ? <span>Passera à <MoneyText value={limit.pending.amount} /> le {formatDateTime(limit.pending.effective_at)}</span>
            : <span>Limite supprimée le {formatDateTime(limit.pending.effective_at)}</span>}
        </p>
      )}

      {editing ? (
        <form className="rg-limit-form" onSubmit={submit} noValidate>
          <TextField
            label={`Nouvelle limite ${label.toLowerCase()} (HTG)`}
            inputMode="decimal"
            autoComplete="off"
            placeholder="Ex. 5000"
            value={value}
            onChange={(e) => setValue(sanitizeStake(e.target.value))}
            error={invalid ? 'Saisissez un montant supérieur à 0.' : error?.fieldErrors.amount_minor}
            autoFocus
          />
          {error && error.code !== 'validation_failed' && <ErrorAlert error={error} />}
          <div className="rg-actions">
            <Button type="submit" className="btn-sm" loading={setLimit.isPending}>Enregistrer</Button>
            {limit.amount && !(limit.pending && limit.pending.amount === null) && (
              <Button variant="ghost" className="btn-sm" disabled={setLimit.isPending} onClick={() => send(null)}>Supprimer la limite</Button>
            )}
            <Button variant="ghost" className="btn-sm" disabled={setLimit.isPending} onClick={() => setEditing(false)}>Annuler</Button>
          </div>
        </form>
      ) : (
        <Button variant="secondary" className="btn-sm" onClick={open}>{limit.amount ? 'Modifier' : 'Fixer une limite'}</Button>
      )}
      {notice && <p className="rg-notice" role="status"><Icon name="check" size={15} />{notice}</p>}
    </div>
  );
}

/** Choix d'une durée parmi celles que propose le serveur (boutons radio en pastilles). */
function DurationChoice({ legend, durations, value, onChange }: { legend: string; durations: string[]; value: string; onChange: (d: string) => void }) {
  const name = useId();

  return (
    <fieldset className="rg-choices">
      <legend>{legend}</legend>
      {durations.map((d) => (
        <label key={d} className="rg-choice">
          <input type="radio" name={name} value={d} checked={value === d} onChange={() => onChange(d)} />
          <span>{durationLabel(d)}</span>
        </label>
      ))}
    </fieldset>
  );
}

function PauseCard({ state }: { state: ResponsibleGaming }) {
  const pause = usePause();
  const durations = state.options.pause_durations;
  const [duration, setDuration] = useState(durations[0] ?? '');
  const [confirming, setConfirming] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey);
  const label = durationLabel(duration);

  function confirm() {
    pause.mutate({ duration, idempotencyKey }, {
      onSuccess: (next) => {
        setConfirming(false);
        setNotice(next.pause.ends_at ? `Pause enregistrée jusqu'au ${formatDateTime(next.pause.ends_at)}.` : 'Pause enregistrée.');
        setIdempotencyKey(newIdempotencyKey());
      },
      onError: (err) => { if (refused(err)) setIdempotencyKey(newIdempotencyKey()); },
    });
  }

  return (
    <Card
      id="rg-pause"
      icon="clock"
      title="Faire une pause"
      intro="Pendant la pause, vous ne pouvez plus parier. Vous restez connecté : votre solde et vos tickets restent accessibles."
    >
      <DurationChoice legend="Durée de la pause" durations={durations} value={duration} onChange={(d) => { setDuration(d); setConfirming(false); }} />
      {state.pause.active && (
        <p className="small muted">Une pause en cours ne peut pas être raccourcie : une durée plus longue la prolonge.</p>
      )}

      {confirming ? (
        <div className="rg-confirm">
          <Alert tone="warning" title={`Pause de ${label}`}>
            <p>Vous ne pourrez plus parier pendant {label}. Une fois confirmée, la pause ne peut être ni annulée ni raccourcie.</p>
          </Alert>
          <ErrorAlert error={pause.error} />
          <div className="rg-actions">
            <Button loading={pause.isPending} onClick={confirm}>Confirmer la pause</Button>
            <Button variant="ghost" disabled={pause.isPending} onClick={() => setConfirming(false)}>Annuler</Button>
          </div>
        </div>
      ) : (
        <div className="rg-actions">
          <Button variant="secondary" onClick={() => { setConfirming(true); setNotice(null); pause.reset(); }}>Faire une pause de {label}</Button>
        </div>
      )}
      {notice && <p className="rg-notice" role="status"><Icon name="check" size={15} />{notice}</p>}
    </Card>
  );
}

function ExclusionCard({ durations }: { durations: string[] }) {
  const navigate = useNavigate();
  // Le serveur a fermé la session : on quitte l'espace joueur pour la page de confirmation.
  // flushSync : la page protégée est démontée avant que la session soit oubliée (sinon, redirection vers /login).
  const exclude = useSelfExclude((until) => navigate('/auto-exclusion', { replace: true, state: { until }, flushSync: true }));
  const [open, setOpen] = useState(false);
  const [duration, setDuration] = useState(durations[0] ?? '');
  const [password, setPassword] = useState('');
  const [understood, setUnderstood] = useState(false);
  const [checked, setChecked] = useState(false);
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey);
  const understoodId = useId();

  const error = exclude.error instanceof ApiError ? exclude.error : null;
  const passwordError = (checked && !password ? 'Saisissez votre mot de passe.' : undefined)
    ?? (error?.code === 'invalid_password' ? error.message : error?.fieldErrors.password);
  const understoodError = checked && !understood ? 'Cochez la case pour confirmer.' : undefined;

  function submit(e: FormEvent) {
    e.preventDefault();
    setChecked(true);
    if (!password || !understood) return;
    exclude.mutate({ duration, password, idempotencyKey }, {
      onError: (err) => { if (refused(err)) setIdempotencyKey(newIdempotencyKey()); },
    });
  }

  return (
    <Card
      id="rg-exclusion"
      icon="lock"
      tone="danger"
      title="S'exclure du jeu"
      intro="Votre compte est fermé au jeu et vous êtes déconnecté. À la fin de la période, il reste fermé : sa réouverture se demande au support."
    >
      {!open ? (
        <div className="rg-actions">
          <Button variant="secondary" onClick={() => setOpen(true)}>Je veux m'exclure</Button>
        </div>
      ) : (
        <form className="rg-exclusion-form" onSubmit={submit} noValidate>
          <DurationChoice legend="Durée de l'exclusion" durations={durations} value={duration} onChange={setDuration} />
          <TextField
            label="Mot de passe"
            type="password"
            autoComplete="current-password"
            hint="Pour confirmer que c'est bien vous."
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            error={passwordError}
          />
          <div className={['terms-field', understoodError && 'field-invalid'].filter(Boolean).join(' ')}>
            <input
              id={understoodId}
              type="checkbox"
              checked={understood}
              onChange={(e) => setUnderstood(e.target.checked)}
              aria-invalid={understoodError ? true : undefined}
            />
            <label htmlFor={understoodId}>
              Je comprends que cette décision est irrévocable : je ne pourrai plus me connecter ni parier pendant {durationLabel(duration)}.
            </label>
            {understoodError && <small className="field-error" role="alert">{understoodError}</small>}
          </div>
          {error && error.code !== 'validation_failed' && error.code !== 'invalid_password' && <ErrorAlert error={error} />}
          <div className="rg-actions">
            <Button type="submit" variant="danger" loading={exclude.isPending}>M'exclure pour {durationLabel(duration)}</Button>
            <Button variant="ghost" disabled={exclude.isPending} onClick={() => setOpen(false)}>Annuler</Button>
          </div>
        </form>
      )}
    </Card>
  );
}
