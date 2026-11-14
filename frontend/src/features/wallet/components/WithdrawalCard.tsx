import { useState, type FormEvent } from 'react';
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
import { useDepositMethods } from '../api/deposits';
import { WITHDRAWAL_STATUS_LABELS, isInProgress, useCancelWithdrawal, useRequestWithdrawal, useWithdrawals, type Withdrawal } from '../api/withdrawals';

/** Saisie « +509 3412-3456 », « 3412 3456 »… → « 34123456 » ; null si ce n'est pas un numéro haïtien à 8 chiffres. */
function localNumber(value: string): string | null {
  const digits = value.replace(/\D/g, '').replace(/^509(?=\d{8}$)/, '');
  return /^[2-9]\d{7}$/.test(digits) ? digits : null;
}

/** « ****3456 » (numéro masqué par le serveur) → « +509 ****3456 ». */
const formatRecipient = (recipient: string) => `+509 ${recipient}`;

/** Message du retrait que le joueur vient de demander, selon son statut du moment. */
function LatestWithdrawal({ w }: { w: Withdrawal }) {
  const to = <>sur votre {w.method_label} au {formatRecipient(w.recipient)}</>;

  switch (w.status) {
    case 'paid':
      return <Alert tone="success" title="Retrait versé"><MoneyText value={w.amount} /> ont été envoyés {to}.</Alert>;
    case 'failed':
    case 'rejected':
      return <Alert tone="error" title="Retrait échoué">Le versement {to} n'a pas abouti : <MoneyText value={w.amount} /> ont été rendus sur votre solde. Vérifiez le numéro et réessayez.</Alert>;
    case 'cancelled':
      return <Alert tone="info" title="Retrait annulé"><MoneyText value={w.amount} /> ont été rendus sur votre solde.</Alert>;
    case 'processing':
      return <Alert tone="info" title="Versement en cours"><MoneyText value={w.amount} /> sont en cours d'envoi {to}.</Alert>;
    default:
      return (
        <Alert tone="success" title="Retrait demandé">
          <MoneyText value={w.amount} /> seront versés {to}. Le montant est déjà retiré de votre solde ; vous pouvez
          annuler tant que le versement n'est pas parti.
        </Alert>
      );
  }
}

function WithdrawalRow({ w }: { w: Withdrawal }) {
  const cancel = useCancelWithdrawal();

  return (
    <li>
      <span>
        <strong>{w.method_label} · {formatRecipient(w.recipient)}</strong>
        <span className="muted small">
          {new Date(w.created_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })} · {WITHDRAWAL_STATUS_LABELS[w.status]}
        </span>
      </span>
      <span className="withdrawal-side">
        <MoneyText value={w.amount} />
        {w.cancellable && (
          <Button variant="ghost" loading={cancel.isPending}
            onClick={() => cancel.mutate({ reference: w.reference, idempotencyKey: newIdempotencyKey() })}>
            Annuler
          </Button>
        )}
      </span>
    </li>
  );
}

/**
 * Retrait en ligne : montant, portefeuille MonCash ou NatCash et numéro. Le montant est réservé tout de suite sur le
 * solde, puis versé par Plop Plop depuis le portefeuille marchand. Sans retraits ouverts, les instructions de retrait
 * auprès de l'opérateur restent affichées.
 */
export function WithdrawalCard({ accountId, phone, currency, balance, frozen }: {
  accountId?: string; phone?: string; currency: string; balance?: Money; frozen: boolean;
}) {
  const config = useDepositMethods();
  const withdrawals = useWithdrawals();
  const request = useRequestWithdrawal();
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState<string | null>(null);
  const [number, setNumber] = useState<string | null>(null);
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey);

  const settings = config.data?.withdrawal;
  const open = !!settings?.methods.length;
  const chosen = settings?.methods.find((m) => m.method === method) ?? settings?.methods[0] ?? null;
  const wallet = number ?? phone ?? '';
  const recipient = localNumber(wallet);
  const phoneValid = recipient !== null;
  const amountMinor = parseStakeMinor(amount);
  const min = settings?.min_amount;
  const max = settings?.max_amount;
  const outOfBounds = amountMinor !== null && !!min && !!max && (amountMinor < BigInt(min.minor) || amountMinor > BigInt(max.minor));
  const aboveBalance = amountMinor !== null && !!balance && amountMinor > BigInt(balance.minor);
  const pending = withdrawals.data?.data.find(isInProgress);
  // Retrait demandé dans cette page : son statut suit la liste rafraîchie (versé, échoué…).
  const latest = request.data && (withdrawals.data?.data.find((w) => w.reference === request.data.reference) ?? request.data);
  const error = request.error instanceof ApiError ? request.error : null;

  function submit(e: FormEvent) {
    e.preventDefault();
    if (amountMinor === null || chosen === null || recipient === null || outOfBounds || aboveBalance) return;
    request.mutate(
      { provider: chosen.provider, method: chosen.method, recipient, amountMinor: Number(amountMinor), idempotencyKey },
      {
        onSuccess: () => { setAmount(''); setIdempotencyKey(newIdempotencyKey()); },
        onError: (err) => { if (err instanceof ApiError && err.status !== 0) setIdempotencyKey(newIdempotencyKey()); },
      },
    );
  }

  return (
    <section className="money-card" aria-labelledby="withdraw">
      <header className="money-card-head">
        <span className="money-card-icon" aria-hidden="true"><Icon name="withdraw" size={22} /></span>
        <div>
          <h2 id="withdraw">Retrait</h2>
          <p className="muted small">Retirer vos gains.</p>
        </div>
      </header>
      <div className="money-card-body">
        {config.isPending && <Spinner />}

        {config.data && !open && (
          <>
            <p>Demandez votre retrait auprès de REYEL 16 PARYAJ avec votre numéro de compte. Le montant est débité de votre solde puis vous est remis.</p>
            {accountId && <p className="small">Votre numéro de compte : <strong className="mono">{accountId}</strong></p>}
          </>
        )}

        {open && (frozen ? (
          <p className="muted small">Les retraits sont indisponibles tant que votre solde est gelé.</p>
        ) : (
          <form className="deposit-form stack-sm" onSubmit={submit} noValidate>
            {latest && <LatestWithdrawal w={latest} />}
            {pending && !latest && (
              <Alert tone="info" title="Retrait en cours">
                Votre retrait de <MoneyText value={pending.amount} /> est en cours. Vous pourrez en demander un autre une fois celui-ci terminé.
              </Alert>
            )}
            {error?.code === 'withdrawal_daily_limit_exceeded' ? (
              <Alert tone="error" title="Plafond de retraits atteint">
                Vous avez atteint le plafond de retraits sur 24 heures.
                {typeof error.details.remaining_minor === 'number' && <> Vous pouvez encore retirer {formatMoney({ minor: error.details.remaining_minor, currency })}.</>}
              </Alert>
            ) : error?.code === 'insufficient_funds' ? (
              <Alert tone="error" title="Solde insuffisant">Ce montant dépasse votre solde disponible.</Alert>
            ) : error?.code === 'kyc_required' ? (
              <Alert tone="warning" title="Vérification d'identité requise">Faites vérifier votre identité avant votre premier retrait.</Alert>
            ) : error && error.code !== 'validation_failed' && error.code !== 'withdrawal_already_pending' && <ErrorAlert error={error} />}

            {settings.methods.length > 1 && (
              <fieldset className="deposit-methods">
                <legend>Recevoir sur</legend>
                {settings.methods.map((m) => (
                  <label key={m.method} className="deposit-method">
                    <input type="radio" name="withdrawal-method" value={m.method} checked={chosen?.method === m.method} onChange={() => setMethod(m.method)} />
                    {m.label}
                  </label>
                ))}
              </fieldset>
            )}

            <TextField
              label="Numéro du portefeuille"
              inputMode="tel"
              name="withdrawal-phone"
              autoComplete="tel-national"
              prefix="+509"
              placeholder="3412 3456"
              value={wallet}
              onChange={(e) => setNumber(e.target.value)}
              error={error?.fieldErrors.recipient ?? (wallet !== '' && !phoneValid ? 'Indiquez un numéro haïtien à 8 chiffres.' : undefined)}
            />
            <TextField
              label="Montant du retrait"
              inputMode="decimal"
              name="withdrawal-amount"
              autoComplete="off"
              prefix={currency}
              placeholder="0.00"
              value={amount}
              onChange={(e) => setAmount(sanitizeStake(e.target.value))}
              hint={min && max ? `De ${formatMoney(min)} à ${formatMoney(max)}` : undefined}
              error={error?.fieldErrors.amount_minor
                ?? (aboveBalance ? 'Ce montant dépasse votre solde disponible.' : undefined)
                ?? (outOfBounds && min && max ? `Le montant doit être compris entre ${formatMoney(min)} et ${formatMoney(max)}.` : undefined)}
            />

            <Button type="submit" block loading={request.isPending}
              disabled={amountMinor === null || amountMinor === 0n || outOfBounds || aboveBalance || !phoneValid || chosen === null || !!pending}>
              Demander le retrait
            </Button>
            <p className="muted small">Le montant est réservé tout de suite sur votre solde, puis versé sur votre portefeuille mobile.</p>
          </form>
        ))}

        <ErrorAlert error={withdrawals.error} onRetry={() => withdrawals.refetch()} />
        {!!withdrawals.data?.data.length && (
          <details className="deposit-history" open={!!pending}>
            <summary>Mes derniers retraits</summary>
            <ul className="recent-ops">
              {withdrawals.data.data.map((w) => <WithdrawalRow key={w.reference} w={w} />)}
            </ul>
          </details>
        )}
      </div>
    </section>
  );
}
