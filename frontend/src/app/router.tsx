import { lazy, Suspense, type ComponentType, type ReactNode } from 'react';
import { createBrowserRouter } from 'react-router-dom';
import { PublicLayout } from './layouts/PublicLayout';
import { HomePage } from '../features/catalogue/pages/HomePage';
import { MatchPage } from '../features/catalogue/pages/MatchPage';
import { MyBetsPage } from '../features/betting/pages/MyBetsPage';
import { SportsPage } from '../features/catalogue/pages/SportsPage';
import { VirtualGate } from '../features/virtual/components/VirtualGate';
import { RequireAuth } from '../features/auth/components/RequireAuth';
import { AccountPage } from '../features/auth/pages/AccountPage';
import { LoginPage } from '../features/auth/pages/LoginPage';
import { RegisterPage } from '../features/auth/pages/RegisterPage';
import { WalletPage } from '../features/wallet/pages/WalletPage';
import { AccountLayout } from '../features/account/AccountLayout';
import { ADMIN_SECTIONS } from '../admin/navigation';
import { NotFoundPage } from '../features/legal/pages/NotFoundPage';
import { Spinner } from '../shared/components/Spinner';

/**
 * Écran chargé à la demande (V2-E05, site léger) : l'administration est dans des fichiers à part, que le joueur ne
 * télécharge jamais.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any -- n'importe quel composant exporté par le module
function lazyPage<K extends string, M extends Record<K, ComponentType<any>>>(load: () => Promise<M>, name: K) {
  return lazy(async () => ({ default: (await load())[name] as M[K] }));
}

/** Pages joueur moins visitées, chargées à la demande (V2-E05) : le premier chargement reste léger. */
const TermsPage = lazyPage(() => import('../features/legal/pages/TermsPage'), 'TermsPage');
const BettingRulesPage = lazyPage(() => import('../features/legal/pages/BettingRulesPage'), 'BettingRulesPage');
const PrivacyPage = lazyPage(() => import('../features/legal/pages/PrivacyPage'), 'PrivacyPage');
const ResponsibleGamingPage = lazyPage(() => import('../features/legal/pages/ResponsibleGamingPage'), 'ResponsibleGamingPage');
const SelfExclusionDonePage = lazyPage(() => import('../features/responsible/pages/SelfExclusionDonePage'), 'SelfExclusionDonePage');
const VirtualGamesPage = lazyPage(() => import('../features/virtual/pages/VirtualGamesPage'), 'VirtualGamesPage');
const VirtualEventPage = lazyPage(() => import('../features/virtual/pages/VirtualEventPage'), 'VirtualEventPage');
const LotteryPage = lazyPage(() => import('../features/lottery/pages/LotteryPage'), 'LotteryPage');
const LoyaltyPage = lazyPage(() => import('../features/rewards/pages/LoyaltyPage'), 'LoyaltyPage');
const ReferralPage = lazyPage(() => import('../features/rewards/pages/ReferralPage'), 'ReferralPage');
const KycPage = lazyPage(() => import('../features/kyc/pages/KycPage'), 'KycPage');
const DevicesPage = lazyPage(() => import('../features/auth/pages/DevicesPage'), 'DevicesPage');
const ResponsibleSettingsPage = lazyPage(() => import('../features/responsible/pages/ResponsibleSettingsPage'), 'ResponsibleSettingsPage');
const SpendingHistoryPage = lazyPage(() => import('../features/responsible/pages/SpendingHistoryPage'), 'SpendingHistoryPage');
const TransactionsPage = lazyPage(() => import('../features/wallet/pages/TransactionsPage'), 'TransactionsPage');
const CouponsPage = lazyPage(() => import('../features/betting/pages/CouponsPage'), 'CouponsPage');
const ForgotPasswordPage = lazyPage(() => import('../features/auth/pages/ForgotPasswordPage'), 'ForgotPasswordPage');
const ResetPasswordPage = lazyPage(() => import('../features/auth/pages/ResetPasswordPage'), 'ResetPasswordPage');
const MessagesPage = lazyPage(() => import('../features/account/ComingSoonPage'), 'MessagesPage');

const pageLoading = <div className="table-state"><Spinner /></div>;
const page = (element: ReactNode) => <Suspense fallback={pageLoading}>{element}</Suspense>;

const adminLoading = <div className="table-state"><Spinner label="Chargement…" /></div>;

// Contrôle des permissions (et écran de configuration MFA, avec sa bibliothèque de QR code) : à la demande aussi.
const RequirePermission = lazyPage(() => import('../admin/components/RequireAdmin'), 'RequirePermission');
const AdminLayout = lazyPage(() => import('./layouts/AdminLayout'), 'AdminLayout');
const KycQueuePage = lazyPage(() => import('../admin/pages/KycQueuePage'), 'KycQueuePage');
const KycSubmissionPage = lazyPage(() => import('../admin/pages/KycSubmissionPage'), 'KycSubmissionPage');
const AdminHomePage = lazyPage(() => import('../admin/pages/AdminHomePage'), 'AdminHomePage');
const AuditPage = lazyPage(() => import('../admin/pages/AuditPage'), 'AuditPage');
const CorrectionDetailPage = lazyPage(() => import('../admin/pages/CorrectionDetailPage'), 'CorrectionDetailPage');
const EventDetailPage = lazyPage(() => import('../admin/pages/EventDetailPage'), 'EventDetailPage');
const EventsPage = lazyPage(() => import('../admin/pages/EventsPage'), 'EventsPage');
const FinanceCorrectionDetailPage = lazyPage(() => import('../admin/pages/FinanceCorrectionDetailPage'), 'FinanceCorrectionDetailPage');
const FinanceCorrectionsPage = lazyPage(() => import('../admin/pages/FinanceCorrectionsPage'), 'FinanceCorrectionsPage');
const LedgerPage = lazyPage(() => import('../admin/pages/LedgerPage'), 'LedgerPage');
const ReconciliationPage = lazyPage(() => import('../admin/pages/ReconciliationPage'), 'ReconciliationPage');
const SettingsPage = lazyPage(() => import('../admin/pages/SettingsPage'), 'SettingsPage');
const SettlementsPage = lazyPage(() => import('../admin/pages/SettlementsPage'), 'SettlementsPage');
const UserDetailPage = lazyPage(() => import('../admin/pages/UserDetailPage'), 'UserDetailPage');
const TicketDetailPage = lazyPage(() => import('../admin/pages/TicketDetailPage'), 'TicketDetailPage');
const TicketsPage = lazyPage(() => import('../admin/pages/TicketsPage'), 'TicketsPage');
const RiskPage = lazyPage(() => import('../admin/pages/RiskPage'), 'RiskPage');
const SystemPage = lazyPage(() => import('../admin/pages/SystemPage'), 'SystemPage');
const UsersPage = lazyPage(() => import('../admin/pages/UsersPage'), 'UsersPage');

/** Écran d'une section admin, protégé par la permission déclarée dans admin/navigation.ts. */
function section(path: string, element: ReactNode, sectionPath = path.split('/')[0]) {
  const permissions = ADMIN_SECTIONS.find((s) => s.to === `/admin${sectionPath ? `/${sectionPath}` : ''}`)?.permissions ?? [];
  return { ...(path ? { path } : { index: true }), element: <Suspense fallback={adminLoading}><RequirePermission permissions={permissions}>{element}</RequirePermission></Suspense> };
}

export const routes = [
  {
    element: <PublicLayout />,
    children: [
      { index: true, element: <HomePage /> },
      { path: 'sports', element: <SportsPage /> },
      { path: 'events/:id', element: <MatchPage /> },
      { path: 'borlette', element: page(<LotteryPage />) },
      // Jeux virtuels (V2-D08) : « page introuvable » tant que VITE_VIRTUAL_GAMES n'est pas activée (D-11).
      { path: 'jeux-virtuels', element: <VirtualGate>{page(<VirtualGamesPage />)}</VirtualGate> },
      { path: 'jeux-virtuels/:id', element: <VirtualGate>{page(<VirtualEventPage />)}</VirtualGate> },
      { path: 'login', element: <LoginPage /> },
      { path: 'register', element: <RegisterPage /> },
      { path: 'forgot-password', element: page(<ForgotPasswordPage />) },
      { path: 'reset-password', element: page(<ResetPasswordPage />) },
      // Pages d'information (V2-E01), liées depuis le pied de page et l'inscription.
      { path: 'conditions', element: page(<TermsPage />) },
      { path: 'reglement', element: page(<BettingRulesPage />) },
      { path: 'confidentialite', element: page(<PrivacyPage />) },
      { path: 'jeu-responsable', element: page(<ResponsibleGamingPage />) },
      // Après l'auto-exclusion, le joueur est déconnecté : confirmation hors de l'espace joueur.
      { path: 'auto-exclusion', element: page(<SelfExclusionDonePage />) },
      // Espace joueur : menu commun des rubriques, accessible une fois connecté.
      {
        element: <RequireAuth><AccountLayout /></RequireAuth>,
        children: [
          { path: 'wallet', element: <WalletPage /> },
          { path: 'bets', element: <MyBetsPage /> },
          { path: 'coupons', element: page(<CouponsPage />) },
          { path: 'transactions', element: page(<TransactionsPage />) },
          { path: 'loyalty', element: page(<LoyaltyPage />) },
          { path: 'referral', element: page(<ReferralPage />) },
          { path: 'account', element: <AccountPage /> },
          { path: 'verification', element: page(<KycPage />) },
          { path: 'devices', element: page(<DevicesPage />) },
          { path: 'responsible-gaming', element: page(<ResponsibleSettingsPage />) },
          { path: 'responsible-gaming/history', element: page(<SpendingHistoryPage />) },
          { path: 'messages', element: page(<MessagesPage />) },
        ],
      },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
  // Connexion de l'équipe : hors AdminLayout (qui exige déjà une session admin).
  { path: 'admin/login', element: <div className="admin-login"><LoginPage admin /></div> },
  {
    path: 'admin',
    element: <Suspense fallback={adminLoading}><AdminLayout /></Suspense>,
    children: [
      section('', <AdminHomePage />),
      section('events', <EventsPage />),
      section('events/:id', <EventDetailPage />),
      section('settlements', <SettlementsPage />),
      section('settlements/:id', <CorrectionDetailPage />),
      section('users', <UsersPage />),
      section('users/:id', <UserDetailPage />),
      section('kyc', <KycQueuePage />),
      section('kyc/:id', <KycSubmissionPage />),
      section('tickets', <TicketsPage />),
      section('tickets/:id', <TicketDetailPage />),
      section('risk', <RiskPage />),
      section('finance', <LedgerPage />),
      section('finance/reconciliation', <ReconciliationPage />),
      section('finance/corrections', <FinanceCorrectionsPage />),
      section('finance/corrections/:id', <FinanceCorrectionDetailPage />),
      section('system', <SystemPage />),
      section('settings', <SettingsPage />),
      section('audit', <AuditPage />),
    ],
  },
];

// basename = sous-dossier de publication (ex. XAMPP : /dashboard/…/frontend/dist), "/" sinon.
export const router = createBrowserRouter(routes, { basename: import.meta.env.BASE_URL.replace(/\/$/, '') || '/' });
