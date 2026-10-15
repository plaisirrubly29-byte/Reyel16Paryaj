import type { ReactNode } from 'react';
import { createBrowserRouter } from 'react-router-dom';
import { PublicLayout } from './layouts/PublicLayout';
import { AdminLayout } from './layouts/AdminLayout';
import { HomePage } from '../features/catalogue/pages/HomePage';
import { RequireAuth } from '../features/auth/components/RequireAuth';
import { AccountPage } from '../features/auth/pages/AccountPage';
import { ForgotPasswordPage } from '../features/auth/pages/ForgotPasswordPage';
import { LoginPage } from '../features/auth/pages/LoginPage';
import { RegisterPage } from '../features/auth/pages/RegisterPage';
import { ResetPasswordPage } from '../features/auth/pages/ResetPasswordPage';
import { WalletPage } from '../features/wallet/pages/WalletPage';
import { RequirePermission } from '../admin/components/RequireAdmin';
import { ADMIN_SECTIONS } from '../admin/navigation';
import { AdminHomePage } from '../admin/pages/AdminHomePage';
import { UserDetailPage } from '../admin/pages/UserDetailPage';
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
      { path: 'sports', element: <Placeholder title="Sports & matchs" task="P3-06" /> },
      { path: 'events/:id', element: <Placeholder title="Marchés & cotes" task="P3-06" /> },
      { path: 'login', element: <LoginPage /> },
      { path: 'register', element: <RegisterPage /> },
      { path: 'forgot-password', element: <ForgotPasswordPage /> },
      { path: 'reset-password', element: <ResetPasswordPage /> },
      { path: 'account', element: <RequireAuth><AccountPage /></RequireAuth> },
      { path: 'bets', element: <RequireAuth><Placeholder title="Mes tickets" task="P4-06" /></RequireAuth> },
      { path: 'wallet', element: <RequireAuth><WalletPage /></RequireAuth> },
    ],
  },
  {
    path: 'admin',
    element: <AdminLayout />,
    children: [
      section('', <AdminHomePage />),
      section('events', <Placeholder title="Événements & marchés" task="P6-06" />),
      section('settlements', <Placeholder title="Résultats & règlement" task="P6-06" />),
      section('users', <UsersPage />),
      section('users/:id', <UserDetailPage />),
      section('tickets', <Placeholder title="Tickets" task="P6-07" />),
      section('finance', <Placeholder title="Finance" task="P6-08" />),
      section('settings', <Placeholder title="Paramètres" task="P6-08" />),
      section('audit', <Placeholder title="Audit & rapports" task="P6-08" />),
    ],
  },
  { path: '*', element: <Placeholder title="Page introuvable" task="—" /> },
];

export const router = createBrowserRouter(routes);
