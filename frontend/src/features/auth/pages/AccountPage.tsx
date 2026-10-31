import type { ReactNode } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Button } from '../../../shared/components/Button';
import { Icon, type IconName } from '../../../shared/components/Icon';
import { MoneyText } from '../../../shared/components/MoneyText';
import { useBets } from '../../betting/api/bets';
import { useWallet } from '../../wallet/api/wallet';
import { useLogout, useMe, type User } from '../api/auth';

const STATUS_LABELS: Record<User['status'], string> = {
  active: 'Compte actif',
  suspended: 'Compte suspendu',
  excluded: 'Auto-exclu',
  closed: 'Compte fermé',
};

/** "34123456" → "+509 34 12 34 56". */
function formatPhone(phone: string): string {
  return `+509 ${phone.replace(/(\d{2})(?=\d)/g, '$1 ')}`;
}

/** Code pays ISO ("HT") → nom en français ("Haïti") ; le code tel quel si le navigateur ne le connaît pas. */
function countryName(code: string | null | undefined): string | null {
  if (!code) return null;
  try {
    return new Intl.DisplayNames(['fr'], { type: 'region' }).of(code.toUpperCase()) ?? code;
  } catch {
    return code;
  }
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase() || '?';
}

function InfoRow({ icon, label, children }: { icon: IconName; label: string; children: ReactNode }) {
  return (
    <div className="profile-row">
      <span className="profile-row-icon" aria-hidden="true"><Icon name={icon} size={18} /></span>
      <dt>{label}</dt>
      <dd>{children}</dd>
    </div>
  );
}

/** Profil du joueur connecté (page protégée par RequireAuth). */
export function AccountPage() {
  const { data: user } = useMe();
  const wallet = useWallet();
  const bets = useBets();
  const logout = useLogout();
  const navigate = useNavigate();

  if (!user) return null;

  const fullName = [user.profile?.first_name, user.profile?.last_name].filter(Boolean).join(' ') || user.name;
  const birthDate = user.profile?.birth_date
    ? new Date(`${user.profile.birth_date}T00:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
    : null;
  const memberSince = new Date(user.created_at).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
  const ticketCount = bets.data?.meta.total;
  const missing = <span className="profile-missing">Non renseigné</span>;

  return (
    <section className="profile">
      <h1 className="sr-only">Mon compte</h1>

      <header className="profile-hero">
        <div className="profile-identity">
          <span className="profile-avatar" aria-hidden="true">{initials(fullName)}</span>
          <div className="profile-identity-text">
            <p className="profile-kicker">Mon compte</p>
            <p className="profile-name">{fullName}</p>
            <p className="profile-meta">
              <span className={`profile-status status-${user.status}`}><span className="profile-status-dot" />{STATUS_LABELS[user.status]}</span>
              <span>Membre depuis {memberSince}</span>
            </p>
          </div>
        </div>

        <Link to="/wallet" className="profile-balance">
          <span className="profile-balance-label"><Icon name="wallet" size={16} />Solde disponible</span>
          <strong className="profile-balance-amount">
            {wallet.data ? <MoneyText value={wallet.data.balance} /> : wallet.isPending ? '…' : '—'}
          </strong>
          {wallet.data?.simulated && <span className="profile-balance-badge">Fonds fictifs</span>}
          <span className="profile-balance-cta">Voir le solde<Icon name="arrowRight" size={15} /></span>
        </Link>
      </header>

      <nav className="profile-shortcuts" aria-label="Raccourcis">
        <Link to="/bets" className="profile-shortcut">
          <span className="profile-shortcut-icon"><Icon name="coupon" /></span>
          <span><strong>Mes paris</strong><span>{ticketCount === undefined ? 'Vos tickets' : ticketCount === 0 ? 'Aucun ticket' : `${ticketCount} ticket${ticketCount > 1 ? 's' : ''}`}</span></span>
        </Link>
        <Link to="/wallet" className="profile-shortcut">
          <span className="profile-shortcut-icon"><Icon name="wallet" /></span>
          <span><strong>Solde</strong><span>Dépôt, retrait et opérations</span></span>
        </Link>
        <Link to="/sports" className="profile-shortcut">
          <span className="profile-shortcut-icon"><Icon name="ball" /></span>
          <span><strong>Parier</strong><span>Matchs et cotes</span></span>
        </Link>
      </nav>

      <div className="profile-grid">
        <section className="profile-card" aria-labelledby="profile-info">
          <h2 id="profile-info">Informations personnelles</h2>
          <dl className="profile-list">
            <InfoRow icon="user" label="Nom">{fullName}</InfoRow>
            <InfoRow icon="phone" label="Téléphone">{formatPhone(user.phone)}</InfoRow>
            <InfoRow icon="mail" label="Email">{user.email ?? missing}</InfoRow>
            <InfoRow icon="calendar" label="Date de naissance">{birthDate ?? missing}</InfoRow>
            <InfoRow icon="flag" label="Pays">{countryName(user.profile?.country) ?? missing}</InfoRow>
          </dl>
        </section>

        <div className="profile-side">
          <section className="profile-card" aria-labelledby="profile-security">
            <h2 id="profile-security">Sécurité</h2>
            <dl className="profile-list">
              <InfoRow icon="lock" label="Mot de passe">
                <Link to="/forgot-password" className="profile-link">Réinitialiser</Link>
              </InfoRow>
              {user.is_admin && (
                <InfoRow icon="shield" label="Double authentification">
                  {user.mfa.enabled ? <span className="profile-ok">Activée</span> : <span className="profile-missing">Non activée</span>}
                </InfoRow>
              )}
            </dl>
          </section>

          <section className="profile-card profile-responsible" aria-labelledby="profile-responsible">
            <span className="profile-responsible-badge" aria-hidden="true">18+</span>
            <div>
              <h2 id="profile-responsible">Jeu responsable</h2>
              <p>Le jeu doit rester un divertissement. Fixez-vous des limites et faites une pause si besoin.</p>
            </div>
          </section>

          <Button
            variant="secondary"
            className="profile-logout"
            loading={logout.isPending}
            // On quitte d'abord la page protégée : sinon elle redirigerait vers /login dès la fin de la session.
            onClick={() => { navigate('/', { replace: true }); logout.mutate(); }}
          >
            <Icon name="logout" size={18} />Se déconnecter
          </Button>
        </div>
      </div>
    </section>
  );
}
