import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router-dom';
import { registerServiceWorker } from './app/pwa';
import { router } from './app/router';
import { reloadOnStalePreload } from './app/staleAssets';
import { createQueryClient } from './shared/api/queryClient';
import './index.css';

const queryClient = createQueryClient();
registerServiceWorker();
reloadOnStalePreload();

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>
  </StrictMode>,
);
