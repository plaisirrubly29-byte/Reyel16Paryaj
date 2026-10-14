import { createBrowserRouter } from 'react-router-dom';
import { PublicLayout } from './layouts/PublicLayout';
import { AdminLayout } from './layouts/AdminLayout';
import { HomePage } from '../features/catalogue/pages/HomePage';
import { Placeholder } from '../shared/components/Placeholder';

export const router = createBrowserRouter([
  {
    element: <PublicLayout />,
    children: [
      { index: true, element: <HomePage /> },
      { path: 'sports', element: <Placeholder title="Sports & matchs" task="P3-06" /> },
      { path: 'events/:id', element: <Placeholder title="Marchés & cotes" task="P3-06" /> },
      { path: 'login', element: <Placeholder title="Connexion" task="P2-06" /> },
      { path: 'register', element: <Placeholder title="Inscription" task="P2-06" /> },
      { path: 'bets', element: <Placeholder title="Mes tickets" task="P4-06" /> },
      { path: 'wallet', element: <Placeholder title="Portefeuille" task="P5-07" /> },
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
]);
