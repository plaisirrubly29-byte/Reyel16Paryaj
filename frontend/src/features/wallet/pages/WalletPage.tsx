import { Link } from 'react-router-dom';
import { Alert } from '../../../shared/components/Alert';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { MoneyText } from '../../../shared/components/MoneyText';
import { Spinner } from '../../../shared/components/Spinner';
import { useMe } from '../../auth/api/auth';
import { ENTRY_LABELS, useWallet, useWalletTransactions } from '../api/wallet';
import { DepositCard } from '../components/DepositCard';
import { WithdrawalCard } from '../components/WithdrawalCard';

/** Solde du joueur connecté, dépôt et retrait. Aucun calcul de solde côté client : tout vient de l'API. */
export function WalletPage() {
  const wallet = useWallet();
  const recent = useWalletTransactions({ page: 1 });
  const { data: me } = useMe();
  const frozen = wallet.data?.status === 'frozen';
  const currency = wallet.data?.currency ?? 'HTG';

  return (
    <section className="stack">
      <h1>Solde</h1>

      {wallet.isPending && <Spinner />}
      <ErrorAlert error={wallet.error} onRetry={() => wallet.refetch()} />
      {wallet.data && (
        <div className="balance-card">
          <span className="muted">Solde disponible</span>
          <strong className="balance"><MoneyText value={wallet.data.balance} /></strong>
        </div>
      )}
      {frozen && (
        <Alert tone="warning" title="Solde gelé">Aucune mise n'est possible pour le moment. Contactez le support.</Alert>
      )}

      <div className="money-grid">
        <DepositCard accountId={me?.id} currency={currency} frozen={frozen} />
        <WithdrawalCard accountId={me?.id} phone={me?.phone} currency={currency} balance={wallet.data?.balance} frozen={frozen} />
      </div>

      <section className="stack-sm" aria-labelledby="recent-ops">
        <div className="toolbar">
          <h2 id="recent-ops">Dernières opérations</h2>
          <Link to="/transactions">Toutes mes transactions</Link>
        </div>
        <ErrorAlert error={recent.error} onRetry={() => recent.refetch()} />
        {recent.isPending && <Spinner />}
        {recent.data?.data.length === 0 && <p className="muted">Aucune opération pour le moment.</p>}
        {!!recent.data?.data.length && (
          <ul className="recent-ops">
            {recent.data.data.slice(0, 5).map((e) => (
              <li key={e.id}>
                <span>
                  <strong>{ENTRY_LABELS[e.type]}</strong>
                  <span className="muted small">{new Date(e.created_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })}</span>
                </span>
                <MoneyText value={e.amount} signed />
              </li>
            ))}
          </ul>
        )}
      </section>
    </section>
  );
}
