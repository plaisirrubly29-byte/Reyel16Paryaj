import { Link } from 'react-router-dom';
import { Alert } from '../../../shared/components/Alert';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon, type IconName } from '../../../shared/components/Icon';
import { MoneyText } from '../../../shared/components/MoneyText';
import { Spinner } from '../../../shared/components/Spinner';
import { ENTRY_LABELS, useWallet, useWalletTransactions } from '../api/wallet';

/**
 * Dépôt ou retrait. Aucun prestataire de paiement n'est intégré (P7-01, bloqué par la validation
 * juridique) : la section explique pourquoi l'opération est fermée, sans formulaire factice.
 */
function MoneyMovement({ id, icon, title, intro, simulated }: { id: string; icon: IconName; title: string; intro: string; simulated: boolean }) {
  return (
    <section className="money-card" aria-labelledby={id}>
      <header className="money-card-head">
        <span className="money-card-icon" aria-hidden="true"><Icon name={icon} size={22} /></span>
        <div>
          <h2 id={id}>{title}</h2>
          <p className="muted small">{intro}</p>
        </div>
        <span className="soon-tag">Bientôt</span>
      </header>
      <div className="money-card-body">
        <p>
          {simulated
            ? 'La plateforme fonctionne pour l’instant en fonds fictifs : aucun argent réel n’est accepté ni versé.'
            : 'Les paiements ne sont pas encore ouverts.'}
          {' '}Cette opération sera disponible dès que les moyens de paiement locaux seront autorisés et intégrés.
        </p>
        <button type="button" className="btn btn-secondary" disabled aria-describedby={`${id}-why`}>
          {title}
        </button>
        <p id={`${id}-why`} className="muted small">Indisponible pour le moment.</p>
      </div>
    </section>
  );
}

/** Solde du joueur connecté. Aucun calcul de solde côté client : tout vient de l'API. */
export function WalletPage() {
  const wallet = useWallet();
  const recent = useWalletTransactions({ page: 1 });
  const simulated = wallet.data?.simulated ?? true;

  return (
    <section className="stack">
      <h1>Solde</h1>

      {wallet.isPending && <Spinner />}
      <ErrorAlert error={wallet.error} onRetry={() => wallet.refetch()} />
      {wallet.data && (
        <div className="balance-card">
          <span className="muted">Solde disponible</span>
          <strong className="balance"><MoneyText value={wallet.data.balance} /></strong>
          {wallet.data.simulated && <span className="badge">Fonds fictifs</span>}
        </div>
      )}
      {wallet.data?.status === 'frozen' && (
        <Alert tone="warning" title="Solde gelé">Aucune mise n'est possible pour le moment. Contactez le support.</Alert>
      )}

      <div className="money-grid">
        <MoneyMovement id="deposit" icon="deposit" title="Dépôt" intro="Alimenter votre solde." simulated={simulated} />
        <MoneyMovement id="withdraw" icon="withdraw" title="Retrait" intro="Retirer vos gains." simulated={simulated} />
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
