import { useState, type ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Alert } from '../../../shared/components/Alert';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon, type IconName } from '../../../shared/components/Icon';
import { MoneyText } from '../../../shared/components/MoneyText';
import { Spinner } from '../../../shared/components/Spinner';
import { formatMoney } from '../../../shared/format/money';
import { useMe, type User } from '../../auth/api/auth';
import { useDepositMethods } from '../api/deposits';
import { ENTRY_LABELS, useWallet, useWalletTransactions, type LedgerEntry } from '../api/wallet';
import { DepositCard } from '../components/DepositCard';
import { WithdrawalCard } from '../components/WithdrawalCard';

type Operation = 'depot' | 'retrait';

/** Prénom pour le « Bonjour » : celui du profil, sinon le premier mot du nom du compte. */
const firstName = (user: User) => user.profile?.first_name?.trim() || user.name.trim().split(/\s+/)[0] || '';

/** Carte d'information fermée par le joueur : retenue sur cet appareil (sans stockage, elle revient au rechargement). */
function useDismissed(key: string): [boolean, () => void] {
  const storageKey = `reyel16.wallet-tip.${key}`;
  const [dismissed, setDismissed] = useState(() => {
    try { return localStorage.getItem(storageKey) === '1'; } catch { return false; }
  });
  return [dismissed, () => {
    setDismissed(true);
    try { localStorage.setItem(storageKey, '1'); } catch { /* stockage indisponible : fermée pour cette visite */ }
  }];
}

/** Petite carte d'information sous les boutons, qu'on peut fermer. */
function WalletTip({ id, icon, title, children }: { id: string; icon: IconName; title: string; children: ReactNode }) {
  const [dismissed, dismiss] = useDismissed(id);
  if (dismissed) return null;

  return (
    <aside className="wallet-tip" aria-label={title}>
      <span className="wallet-tip-icon" aria-hidden="true"><Icon name={icon} size={20} /></span>
      <div className="wallet-tip-text">
        <strong>{title}</strong>
        <span>{children}</span>
      </div>
      <button type="button" className="wallet-tip-close" aria-label="Fermer ce message" onClick={dismiss}>
        <Icon name="close" size={16} />
      </button>
    </aside>
  );
}

/** Une opération du solde : pastille, libellé et date, montant signé dans une pastille verte (entrée) ou rouge (sortie). */
function OperationRow({ entry }: { entry: LedgerEntry }) {
  const credit = entry.amount.minor > 0;

  return (
    <li className="wallet-op">
      <span className={`wallet-op-icon ${credit ? 'is-in' : 'is-out'}`} aria-hidden="true">
        <Icon name={credit ? 'deposit' : 'withdraw'} size={18} />
      </span>
      <span className="wallet-op-text">
        <strong>{ENTRY_LABELS[entry.type]}</strong>
        <span>{new Date(entry.created_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })}</span>
      </span>
      <span className="wallet-op-side">
        <span className="wallet-op-balance">Solde {formatMoney(entry.balance_after)}</span>
        <span className={`wallet-op-pill ${credit ? 'is-in' : 'is-out'}`}><MoneyText value={entry.amount} signed /></span>
      </span>
    </li>
  );
}

/**
 * Solde du joueur connecté, façon application mobile : en haut sur fond bordeaux, « Bonjour », le solde en grand et une
 * rangée de boutons ronds (Déposer, Retirer, Historique, Mes paris) ; en dessous, une feuille claire avec les dernières
 * opérations, ou le dépôt / retrait ouvert (choix MonCash ou NatCash, puis formulaire). Aucun calcul de solde côté
 * client : tout vient de l'API.
 */
export function WalletPage() {
  const wallet = useWallet();
  const recent = useWalletTransactions({ page: 1 });
  const methods = useDepositMethods();
  const { data: me } = useMe();
  const frozen = wallet.data?.status === 'frozen';
  const currency = wallet.data?.currency ?? 'HTG';
  const [params, setParams] = useSearchParams();
  // Panneau ouvert, gardé dans l'URL (?operation=depot|retrait) ; le retour du prestataire (?depot=, ?refference_id=)
  // rouvre le dépôt pour suivre le paiement.
  const returning = params.has('depot') || params.has('refference_id');
  const operation: Operation | null = params.get('operation') === 'retrait' ? 'retrait'
    : params.get('operation') === 'depot' || returning ? 'depot' : null;
  const payLabels = methods.data?.methods.map((m) => m.label) ?? [];

  /** Ouvre le panneau choisi (nouvelle étape dans l'historique) ou le referme s'il l'était déjà. */
  function open(next: Operation) {
    if (operation === next) setParams({}, { replace: true });
    else setParams({ operation: next });
  }
  const close = () => setParams({}, { replace: true });

  return (
    <section className="wallet-page wallet-app">
      <h1 className="sr-only">Solde</h1>

      <div className="wallet-top">
        <div className="wallet-greet">
          {me && (
            <Link to="/account" className="wallet-hello">
              <span className="wallet-avatar" aria-hidden="true">{firstName(me).charAt(0).toUpperCase()}</span>
              Bonjour, {firstName(me)}
            </Link>
          )}
          <Link to="/responsible-gaming" className="wallet-round-link" aria-label="Jeu responsable">
            <Icon name="shield" size={19} />
          </Link>
        </div>

        <div className="wallet-balance">
          <span className="wallet-balance-label">{wallet.error ? 'Solde indisponible' : 'Votre solde'}</span>
          {wallet.isPending && (
            <span role="status" aria-label="Chargement du solde…" className="skeleton skeleton-on-dark skeleton-amount" />
          )}
          {wallet.data && <strong className="balance"><MoneyText value={wallet.data.balance} /></strong>}
        </div>

        <ErrorAlert error={wallet.error} onRetry={() => wallet.refetch()} />

        <div className="wallet-actions">
          <button
            type="button"
            className={`wallet-action wallet-action-main${operation === 'depot' ? ' active' : ''}`}
            aria-expanded={operation === 'depot'}
            aria-controls="money-panel"
            onClick={() => open('depot')}
          >
            <span className="wallet-action-icon" aria-hidden="true"><Icon name="deposit" size={21} /></span>
            <span className="wallet-action-label">Déposer</span>
          </button>
          <button
            type="button"
            className={`wallet-action${operation === 'retrait' ? ' active' : ''}`}
            aria-expanded={operation === 'retrait'}
            aria-controls="money-panel"
            onClick={() => open('retrait')}
          >
            <span className="wallet-action-icon" aria-hidden="true"><Icon name="withdraw" size={21} /></span>
            <span className="wallet-action-label">Retirer</span>
          </button>
          <Link to="/transactions" className="wallet-action">
            <span className="wallet-action-icon" aria-hidden="true"><Icon name="clock" size={21} /></span>
            <span className="wallet-action-label">Historique</span>
          </Link>
          <Link to="/bets" className="wallet-action">
            <span className="wallet-action-icon" aria-hidden="true"><Icon name="tickets" size={21} /></span>
            <span className="wallet-action-label">Mes paris</span>
          </Link>
        </div>

        {/* Une seule carte à la fois, et seulement sur l'accueil du solde (un dépôt ou retrait ouvert a ses propres
            messages) : d'abord la vérification d'identité (nécessaire aux retraits), sinon les moyens de paiement
            réellement ouverts. */}
        {operation ? null : me && (me.kyc_status === 'none' || me.kyc_status === 'pending' || me.kyc_status === 'rejected') ? (
          <WalletTip id={`kyc-${me.kyc_status}`} icon="shield" title={me.kyc_status === 'pending' ? 'Identité en cours de vérification' : 'Vérifiez votre identité'}>
            {me.kyc_status === 'pending'
              ? <>Vous pourrez retirer vos gains dès la validation de votre dossier. <Link to="/verification">Voir mon dossier</Link></>
              : <>Elle est demandée pour retirer vos gains. <Link to="/verification">Vérifier mon identité</Link></>}
          </WalletTip>
        ) : payLabels.length > 0 && (
          <WalletTip id="moyens" icon="phone" title={`Dépôt avec ${payLabels.join(' ou ')}`}>
            Payez depuis votre téléphone : le solde est crédité dès la confirmation du paiement.
          </WalletTip>
        )}
      </div>

      <div className="wallet-sheet">
        {frozen && (
          <Alert tone="warning" title="Solde gelé">Aucune mise n'est possible pour le moment. Contactez le support.</Alert>
        )}

        {operation ? (
          <div id="money-panel" className="money-panel">
            {operation === 'depot'
              ? <DepositCard accountId={me?.id} currency={currency} frozen={frozen} onClose={close} />
              : <WithdrawalCard accountId={me?.id} phone={me?.phone} currency={currency} balance={wallet.data?.balance} frozen={frozen} onClose={close} />}
          </div>
        ) : (
          <section className="wallet-ops-block" aria-labelledby="recent-ops">
            <div className="wallet-sheet-head">
              <h2 id="recent-ops">Dernières opérations</h2>
              <Link to="/transactions">Tout voir</Link>
            </div>
            <ErrorAlert error={recent.error} onRetry={() => recent.refetch()} />
            {recent.isPending && <Spinner />}
            {recent.data?.data.length === 0 && <p className="ops-empty">Aucune opération pour le moment.</p>}
            {!!recent.data?.data.length && (
              <ul className="wallet-ops-list">
                {recent.data.data.slice(0, 5).map((e) => <OperationRow key={e.id} entry={e} />)}
              </ul>
            )}
          </section>
        )}
      </div>
    </section>
  );
}
