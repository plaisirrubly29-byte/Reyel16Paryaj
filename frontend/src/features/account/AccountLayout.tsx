import { NavLink, Outlet } from 'react-router-dom';
import { Icon } from '../../shared/components/Icon';
import { MoneyText } from '../../shared/components/MoneyText';
import { useMe } from '../auth/api/auth';
import { useWallet } from '../wallet/api/wallet';
import { ACCOUNT_SECTIONS } from './sections';

/** Espace joueur : menu des rubriques (colonne sur ordinateur, bande défilante sur mobile) + page. */
export function AccountLayout() {
  const { data: user } = useMe();
  const wallet = useWallet();

  return (
    <div className="account-shell">
      <aside className="account-side">
        <div className="account-side-head">
          <span className="account-avatar" aria-hidden="true">{user?.name.charAt(0).toUpperCase()}</span>
          <div>
            <strong>{user?.name}</strong>
            <span>
              Solde{' '}
              {wallet.data ? <MoneyText value={wallet.data.balance} /> : '…'}
            </span>
          </div>
        </div>
        <nav className="account-nav" aria-label="Rubriques du compte">
          {ACCOUNT_SECTIONS.map((s) => (
            <NavLink key={s.to} to={s.to}>
              <Icon name={s.icon} size={18} />
              <span>{s.label}</span>
              {s.soon && <span className="soon-tag">Bientôt</span>}
            </NavLink>
          ))}
        </nav>
      </aside>
      <div className="account-main">
        <Outlet />
      </div>
    </div>
  );
}
