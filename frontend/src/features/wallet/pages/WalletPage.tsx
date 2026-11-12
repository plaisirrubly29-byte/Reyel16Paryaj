import { Link } from 'react-router-dom';
import { Alert } from '../../../shared/components/Alert';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon, type IconName } from '../../../shared/components/Icon';
import { MoneyText } from '../../../shared/components/MoneyText';
import { Spinner } from '../../../shared/components/Spinner';
import { useMe } from '../../auth/api/auth';
import { ENTRY_LABELS, useWallet, useWalletTransactions } from '../api/wallet';
import { DepositCard } from '../components/DepositCard';

/**
 * Retrait : pas encore de retrait en ligne (V2-B03), le joueur est payé auprès de REYEL 16 PARYAJ avec son
 * numéro de compte, puis l'opération est enregistrée par l'administration. Le dépôt est dans DepositCard.
 */
function MoneyMovement({ id, icon, title, intro, steps, accountId }: { id: string; icon: IconName; title: string; intro: string; steps: string; accountId?: string }) {
  return (
    <section className="money-card" aria-labelledby={id}>
      <header className="money-card-head">
        <span className="money-card-icon" aria-hidden="true"><Icon name={icon} size={22} /></span>
        <div>
          <h2 id={id}>{title}</h2>
          <p className="muted small">{intro}</p>
        </div>
      </header>
      <div className="money-card-body">
        <p>{steps}</p>
        {accountId && <p className="small">Votre numéro de compte : <strong className="mono">{accountId}</strong></p>}
      </div>
    </section>
  );
}

/** Solde du joueur connecté. Aucun calcul de solde côté client : tout vient de l'API. */
export function WalletPage() {
  const wallet = useWallet();
  const recent = useWalletTransactions({ page: 1 });
  const { data: me } = useMe();

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
      {wallet.data?.status === 'frozen' && (
        <Alert tone="warning" title="Solde gelé">Aucune mise n'est possible pour le moment. Contactez le support.</Alert>
      )}

      <div className="money-grid">
        <DepositCard accountId={me?.id} currency={wallet.data?.currency ?? 'HTG'} frozen={wallet.data?.status === 'frozen'} />
        <MoneyMovement id="withdraw" icon="withdraw" title="Retrait" intro="Retirer vos gains." accountId={me?.id}
          steps="Demandez votre retrait auprès de REYEL 16 PARYAJ avec votre numéro de compte. Le montant est débité de votre solde puis vous est remis." />
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
