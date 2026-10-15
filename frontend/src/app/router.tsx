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
import { Placeholder } from '../shared/components/Placeholder';

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
      { path: 'wallet', element: <RequireAuth><Placeholder title="Portefeuille" task="P5-07" /></RequireAuth> },
    ],
  },
  {
    path: 'admin',
    element: <AdminLayout />,
    children: [
      { index: true, element: <Placeholder title="Vue d'ensemble" task="P6-05" /> },
      { path: 'events', element: <Placeholder title="Événements & marchés" task="P6-06" /> },
      { path: 'settlements', element: <Placeholder title="Résultats & règlement" task="P6-06" /> },
      { path: 'users', element: <Placeholder title="Utilisateurs" task="P6-07" /> },
      { path: 'tickets', element: <Placeholder title="Tickets" task="P6-07" /> },
      { path: 'finance', element: <Placeholder title="Finance" task="P6-08" /> },
      { path: 'settings', element: <Placeholder title="Paramètres" task="P6-08" /> },
      { path: 'audit', element: <Placeholder title="Audit & rapports" task="P6-08" /> },
    ],
  },
  { path: '*', element: <Placeholder title="Page introuvable" task="—" /> },
];

export const router = createBrowserRouter(routes);
