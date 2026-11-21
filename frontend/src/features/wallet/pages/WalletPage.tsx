import { Link, useSearchParams } from 'react-router-dom';
import { Alert } from '../../../shared/components/Alert';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon } from '../../../shared/components/Icon';
import { MoneyText } from '../../../shared/components/MoneyText';
import { Spinner } from '../../../shared/components/Spinner';
import { useMe } from '../../auth/api/auth';
import { ENTRY_LABELS, useWallet, useWalletTransactions } from '../api/wallet';
import { DepositCard } from '../components/DepositCard';
import { WithdrawalCard } from '../components/WithdrawalCard';

type Operation = 'depot' | 'retrait';

/**
 * Solde du joueur connecté, puis deux boutons « Dépôt » et « Retrait » : le panneau choisi propose MonCash ou NatCash,
 * puis le formulaire. Aucun calcul de solde côté client : tout vient de l'API.
 */
export function WalletPage() {
  const wallet = useWallet();
  const recent = useWalletTransactions({ page: 1 });
  const { data: me } = useMe();
  const frozen = wallet.data?.status === 'frozen';
  const currency = wallet.data?.currency ?? 'HTG';
  const [params, setParams] = useSearchParams();
  // Panneau ouvert, gardé dans l'URL (?operation=depot|retrait) ; le retour du prestataire (?depot=, ?refference_id=)
  // rouvre le dépôt pour suivre le paiement.
  const returning = params.has('depot') || params.has('refference_id');
  const operation: Operation | null = params.get('operation') === 'retrait' ? 'retrait'
    : params.get('operation') === 'depot' || returning ? 'depot' : null;

  /** Ouvre le panneau choisi (nouvelle étape dans l'historique) ou le referme s'il l'était déjà. */
  function open(next: Operation) {
    if (operation === next) setParams({}, { replace: true });
    else setParams({ operation: next });
  }

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

      {/* Deux boutons sous le solde : chacun ouvre son panneau (choix MonCash / NatCash, puis formulaire). */}
      <div className="money-actions">
        <button
          type="button"
          className={`money-action money-action-deposit${operation === 'depot' ? ' active' : ''}`}
          aria-expanded={operation === 'depot'}
          aria-controls="money-panel"
          onClick={() => open('depot')}
        >
          <Icon name="deposit" size={22} />Dépôt
        </button>
        <button
          type="button"
          className={`money-action money-action-withdraw${operation === 'retrait' ? ' active' : ''}`}
          aria-expanded={operation === 'retrait'}
          aria-controls="money-panel"
          onClick={() => open('retrait')}
        >
          <Icon name="withdraw" size={22} />Retrait
        </button>
      </div>

      {operation && (
        <div id="money-panel" className="money-panel">
          {operation === 'depot'
            ? <DepositCard accountId={me?.id} currency={currency} frozen={frozen} />
            : <WithdrawalCard accountId={me?.id} phone={me?.phone} currency={currency} balance={wallet.data?.balance} frozen={frozen} />}
        </div>
      )}

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
