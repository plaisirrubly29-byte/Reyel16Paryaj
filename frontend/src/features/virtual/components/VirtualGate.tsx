import type { ReactNode } from 'react';
import { NotFoundPage } from '../../legal/pages/NotFoundPage';
import { virtualGamesEnabled } from '../api/virtualGames';

/** Pages des jeux virtuels : « page introuvable » tant que l'option VITE_VIRTUAL_GAMES n'est pas activée. */
export function VirtualGate({ children }: { children: ReactNode }) {
  return virtualGamesEnabled() ? children : <NotFoundPage />;
}
