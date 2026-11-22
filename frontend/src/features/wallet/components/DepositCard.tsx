import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ApiError, newIdempotencyKey, type Money } from '../../../shared/api/client';
import { Alert } from '../../../shared/components/Alert';
import { Button } from '../../../shared/components/Button';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon } from '../../../shared/components/Icon';
import { MoneyText } from '../../../shared/components/MoneyText';
import { Spinner } from '../../../shared/components/Spinner';
import { TextField } from '../../../shared/components/TextField';
import { formatMoney } from '../../../shared/format/money';
import { parseStakeMinor, sanitizeStake } from '../../betting/slip/stake';
import { KycRequiredAlert } from './KycRequiredAlert';
import { MethodChosen, MethodPicker, MethodPickerSkeleton, useChosenMethod } from './MethodPicker';
import { QuickAmounts } from './QuickAmounts';
import {
  DEPOSIT_STATUS_LABELS,
  providerNavigation,
  providerPageUrl,
  useDeposit,
  useDepositMethods,
  useDeposits,
  useStartDeposit,
  type Deposit,
  type DepositMethod,
} from '../api/deposits';

/** Identifiant d'un choix de paiement, gardé dans l'URL (?moyen=moncash) : le moyen, sinon le prestataire. */
const methodKey = (m: DepositMethod) => m.method ?? m.provider;

const LIMIT_PERIODS: Record<string, string> = { day: 'sur 24 heures', week: 'sur 7 jours', month: 'sur 30 jours' };

/** Montants proposés en un clic (en gourdes), dans les bornes acceptées par le serveur. */
const QUICK_AMOUNTS = [500, 1_000, 2_500, 5_000];

/** Pastille de couleur du statut d'un dépôt dans l'historique. */
const DEPOSIT_TONES: Record<Deposit['status'], 'ok' | 'ko' | 'wait'> = {
  succeeded: 'ok', failed: 'ko', expired: 'ko', pending: 'wait', review: 'wait',
};

/** Dépôt refusé par une limite que le joueur s'est fixée (V2-B06) : montant encore possible et lien vers le réglage. */
function DepositLimitRefusal({ error, currency }: { error: ApiError; currency: string }) {
  const { period, limit_minor: limit, remaining_minor: remaining } = error.details as { period?: string; limit_minor?: number; remaining_minor?: number };

  return (
    <Alert tone="error" title="Limite de dépôt atteinte">
      Ce dépôt dépasse la limite que vous vous êtes fixée
      {typeof limit === 'number' && <> ({formatMoney({ minor: limit, currency })} {LIMIT_PERIODS[period ?? ''] ?? ''})</>}.
      {typeof remaining === 'number' && <> Vous pouvez encore déposer {formatMoney({ minor: remaining, currency })}.</>}
      {' '}<Link to="/responsible-gaming">Gérer mes limites</Link>
    </Alert>
  );
}

/** Retour de la page du prestataire (?depot=…) : statut suivi jusqu'à la confirmation, message clair pour chacun. */
function DepositFollowUp({ reference, onClose }: { reference: string; onClose: () => void }) {
  const deposit = useDeposit(reference);

  if (deposit.isPending) return <Spinner />;
  if (deposit.error) return <ErrorAlert error={deposit.error} onRetry={() => deposit.refetch()} />;

  const d = deposit.data;
  const amount = <MoneyText value={d.amount} />;
  const close = <Button variant="ghost" onClick={onClose}>Fermer</Button>;

  switch (d.status) {
    case 'succeeded':
      return <Alert tone="success" title="Dépôt crédité">{amount} ont été ajoutés à votre solde. {close}</Alert>;
    case 'failed':
      return <Alert tone="error" title="Paiement refusé">Le paiement de {amount} n'a pas abouti. Votre solde n'a pas changé. {close}</Alert>;
    case 'expired':
      return (
        <Alert tone="warning" title="Délai dépassé">
          Le paiement de {amount} n'a pas été confirmé à temps. Si votre compte {d.provider_label} a été débité, le dépôt
          sera crédité dès sa confirmation. {close}
        </Alert>
      );
    case 'review':
      return <Alert tone="warning" title="Dépôt en vérification">Votre dépôt de {amount} est vérifié par notre équipe avant d'être crédité. {close}</Alert>;
    default:
      return (
        <Alert tone="info" title="En attente de confirmation">
          <span className="deposit-waiting"><Spinner size="sm" /> Nous attendons la confirmation de {d.provider_label} pour {amount}.</span>
          {d.redirect_url && (
            <Button variant="secondary" onClick={() => providerNavigation.open(providerPageUrl(d.redirect_url!))}>Reprendre le paiement</Button>
          )}
        </Alert>
      );
  }
}

function RecentDeposits() {
  const deposits = useDeposits();
  if (!deposits.data?.data.length) return null;

  return (
    <details className="deposit-history">
      <summary>Mes derniers dépôts</summary>
      <ul className="recent-ops">
        {deposits.data.data.map((d: Deposit) => (
          <li key={d.reference}>
            <span>
              <strong>{d.provider_label}</strong>
              <span className="muted small">{new Date(d.created_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })}</span>
              <span className={`op-status op-status-${DEPOSIT_TONES[d.status]}`}>{DEPOSIT_STATUS_LABELS[d.status]}</span>
            </span>
            <MoneyText value={d.amount} />
          </li>
        ))}
      </ul>
    </details>
  );
}

/**
 * Dépôt en ligne : le joueur choisit MonCash ou NatCash (étape sautée s'il n'y a qu'un moyen), saisit le montant, puis
 * confirme sur la page du prestataire. Sans moyen de
 * paiement activé, les instructions de dépôt auprès de l'opérateur restent affichées.
 */
export function DepositCard({ accountId, currency, frozen }: { accountId?: string; currency: string; frozen: boolean }) {
  const methods = useDepositMethods();
  const start = useStartDeposit();
  const [params, setParams] = useSearchParams();
  // Retour du prestataire : ?depot=… (prestataire simulé) ou ?refference_id=… (lien de retour Plop Plop).
  const returned = params.get('depot') ?? params.get('refference_id');
  const followed = returned && /^pay_[a-z0-9]+$/.test(returned) ? returned : null;
  const [amount, setAmount] = useState('');
  const [choice, setChoice] = useChosenMethod();
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey);

  const available = methods.data?.methods ?? [];
  // Plusieurs moyens (MonCash, NatCash) : le joueur choisit lui-même ; un seul : choisi d'office.
  const chosen: DepositMethod | null = available.find((m) => methodKey(m) === choice) ?? (available.length === 1 ? available[0]! : null);
  const amountMinor = parseStakeMinor(amount);
  const min: Money | undefined = methods.data?.min_amount;
  const max: Money | undefined = methods.data?.max_amount;
  const outOfBounds = amountMinor !== null && !!min && !!max && (amountMinor < BigInt(min.minor) || amountMinor > BigInt(max.minor));
  const error = start.error instanceof ApiError ? start.error : null;

  function submit(e: FormEvent) {
    e.preventDefault();
    if (amountMinor === null || chosen === null || outOfBounds) return;
    start.mutate(
      { provider: chosen.provider, method: chosen.method, amountMinor: Number(amountMinor), idempotencyKey },
      {
        onSuccess: (deposit) => {
          setIdempotencyKey(newIdempotencyKey());
          if (deposit.redirect_url) providerNavigation.open(providerPageUrl(deposit.redirect_url));
          else setParams({ operation: 'depot', depot: deposit.reference }, { replace: true });
        },
        onError: (err) => {
          if (err instanceof ApiError && err.status !== 0) setIdempotencyKey(newIdempotencyKey());
        },
      },
    );
  }

  return (
    <section className="money-card" aria-labelledby="deposit">
      <header className="money-card-head">
        <span className="money-card-icon" aria-hidden="true"><Icon name="deposit" size={22} /></span>
        <div>
          <h2 id="deposit">Dépôt</h2>
          <p className="muted small">Alimenter votre solde.</p>
        </div>
      </header>
      <div className="money-card-body">
        {followed && <DepositFollowUp reference={followed} onClose={() => setParams({ operation: 'depot' }, { replace: true })} />}

        {methods.isPending && <MethodPickerSkeleton />}
        <ErrorAlert error={methods.error} onRetry={() => methods.refetch()} />

        {methods.data && available.length === 0 && (
          <>
            <p>Effectuez votre dépôt auprès de REYEL 16 PARYAJ en indiquant votre numéro de compte. Votre solde est crédité dès réception du paiement.</p>
            {accountId && <p className="small">Votre numéro de compte : <strong className="mono">{accountId}</strong></p>}
          </>
        )}

        {methods.data && available.length > 0 && (frozen ? (
          <p className="muted small">Les dépôts sont indisponibles tant que votre solde est gelé.</p>
        ) : chosen === null ? (
          <MethodPicker
            title="Déposer avec"
            options={available.map((m) => ({ key: methodKey(m), label: m.label }))}
            onPick={setChoice}
            describe={() => 'Payer depuis votre compte'}
          />
        ) : (
          <form className="deposit-form stack-sm" onSubmit={submit} noValidate>
            <MethodChosen
              option={{ key: methodKey(chosen), label: chosen.label }}
              verb="Dépôt avec"
              onChange={available.length > 1 ? () => setChoice(null) : undefined}
            />
            {error?.code === 'deposit_limit_exceeded'
              ? <DepositLimitRefusal error={error} currency={currency} />
              : error?.code === 'kyc_required'
                ? <KycRequiredAlert error={error} operation="deposit" currency={currency} />
                : error && error.code !== 'validation_failed' && <ErrorAlert error={error} />}

            <TextField
              className="amount-field"
              label="Montant du dépôt"
              inputMode="decimal"
              name="deposit-amount"
              autoComplete="off"
              prefix={currency}
              placeholder="0.00"
              value={amount}
              onChange={(e) => setAmount(sanitizeStake(e.target.value))}
              hint={min && max ? `De ${formatMoney(min)} à ${formatMoney(max)}` : undefined}
              error={error?.fieldErrors.amount_minor ?? (outOfBounds && min && max ? `Le montant doit être compris entre ${formatMoney(min)} et ${formatMoney(max)}.` : undefined)}
            />
            <QuickAmounts
              amounts={QUICK_AMOUNTS.map((v) => ({ minor: v * 100 }))
                .filter((a) => (!min || a.minor >= min.minor) && (!max || a.minor <= max.minor))}
              currency={currency}
              current={amountMinor}
              onPick={setAmount}
            />

            <Button type="submit" block className="money-submit" loading={start.isPending} disabled={amountMinor === null || amountMinor === 0n || outOfBounds}>
              Déposer avec {chosen.label}
            </Button>
            <p className="money-note">
              <Icon name="lock" size={15} />
              <span>Vous confirmerez le paiement sur la page de {chosen.label}. Votre solde est crédité dès sa confirmation.</span>
            </p>
          </form>
        ))}

        <RecentDeposits />
      </div>
    </section>
  );
}
