import type { ReactNode } from 'react';
import { createBrowserRouter } from 'react-router-dom';
import { PublicLayout } from './layouts/PublicLayout';
import { AdminLayout } from './layouts/AdminLayout';
import { HomePage } from '../features/catalogue/pages/HomePage';
import { MatchPage } from '../features/catalogue/pages/MatchPage';
import { MyBetsPage } from '../features/betting/pages/MyBetsPage';
import { SportsPage } from '../features/catalogue/pages/SportsPage';
import { RequireAuth } from '../features/auth/components/RequireAuth';
import { AccountPage } from '../features/auth/pages/AccountPage';
import { ForgotPasswordPage } from '../features/auth/pages/ForgotPasswordPage';
import { LoginPage } from '../features/auth/pages/LoginPage';
import { RegisterPage } from '../features/auth/pages/RegisterPage';
import { ResetPasswordPage } from '../features/auth/pages/ResetPasswordPage';
import { TransactionsPage } from '../features/wallet/pages/TransactionsPage';
import { WalletPage } from '../features/wallet/pages/WalletPage';
import { AccountLayout } from '../features/account/AccountLayout';
import { LoyaltyPage, MessagesPage, ReferralPage } from '../features/account/ComingSoonPage';
import { CouponsPage } from '../features/betting/pages/CouponsPage';
import { RequirePermission } from '../admin/components/RequireAdmin';
import { ADMIN_SECTIONS } from '../admin/navigation';
import { AdminHomePage } from '../admin/pages/AdminHomePage';
import { CorrectionDetailPage } from '../admin/pages/CorrectionDetailPage';
import { EventDetailPage } from '../admin/pages/EventDetailPage';
import { EventsPage } from '../admin/pages/EventsPage';
import { SettlementsPage } from '../admin/pages/SettlementsPage';
import { UserDetailPage } from '../admin/pages/UserDetailPage';
import { TicketDetailPage } from '../admin/pages/TicketDetailPage';
import { TicketsPage } from '../admin/pages/TicketsPage';
import { RiskPage } from '../admin/pages/RiskPage';
import { SystemPage } from '../admin/pages/SystemPage';
import { UsersPage } from '../admin/pages/UsersPage';
import { Placeholder } from '../shared/components/Placeholder';

/** Écran d'une section admin, protégé par la permission déclarée dans admin/navigation.ts. */
function section(path: string, element: ReactNode, sectionPath = path.split('/')[0]) {
  const permissions = ADMIN_SECTIONS.find((s) => s.to === `/admin${sectionPath ? `/${sectionPath}` : ''}`)?.permissions ?? [];
  return { ...(path ? { path } : { index: true }), element: <RequirePermission permissions={permissions}>{element}</RequirePermission> };
}

export const routes = [
  {
    element: <PublicLayout />,
    children: [
      { index: true, element: <HomePage /> },
      { path: 'sports', element: <SportsPage /> },
      { path: 'events/:id', element: <MatchPage /> },
      { path: 'login', element: <LoginPage /> },
      { path: 'register', element: <RegisterPage /> },
      { path: 'forgot-password', element: <ForgotPasswordPage /> },
      { path: 'reset-password', element: <ResetPasswordPage /> },
      // Espace joueur : menu commun des rubriques, accessible une fois connecté.
      {
        element: <RequireAuth><AccountLayout /></RequireAuth>,
        children: [
          { path: 'wallet', element: <WalletPage /> },
          { path: 'bets', element: <MyBetsPage /> },
          { path: 'coupons', element: <CouponsPage /> },
          { path: 'transactions', element: <TransactionsPage /> },
          { path: 'loyalty', element: <LoyaltyPage /> },
          { path: 'referral', element: <ReferralPage /> },
          { path: 'account', element: <AccountPage /> },
          { path: 'messages', element: <MessagesPage /> },
        ],
      },
    ],
  },
  // Connexion de l'équipe : hors AdminLayout (qui exige déjà une session admin).
  { path: 'admin/login', element: <div className="admin-login"><LoginPage admin /></div> },
  {
    path: 'admin',
    element: <AdminLayout />,
    children: [
      section('', <AdminHomePage />),
      section('events', <EventsPage />),
      section('events/:id', <EventDetailPage />),
      section('settlements', <SettlementsPage />),
      section('settlements/:id', <CorrectionDetailPage />),
      section('users', <UsersPage />),
      section('users/:id', <UserDetailPage />),
      section('tickets', <TicketsPage />),
      section('tickets/:id', <TicketDetailPage />),
      section('risk', <RiskPage />),
      section('finance', <Placeholder title="Finance" task="P6-08" />),
      section('system', <SystemPage />),
      section('settings', <Placeholder title="Paramètres" task="P6-08" />),
      section('audit', <Placeholder title="Audit & rapports" task="P6-08" />),
    ],
  },
  { path: '*', element: <Placeholder title="Page introuvable" task="—" /> },
];

// basename = sous-dossier de publication (ex. XAMPP : /dashboard/…/frontend/dist), "/" sinon.
export const router = createBrowserRouter(routes, { basename: import.meta.env.BASE_URL.replace(/\/$/, '') || '/' });
