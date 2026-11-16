import { useEffect, useState } from 'react';

/**
 * Application installable (V2-E05) : enregistrement du service worker (public/sw.js) et proposition d'installation.
 * En développement, aucun service worker : le rechargement à chaud de Vite reste direct.
 */
export function registerServiceWorker(): void {
  if (!import.meta.env.PROD || !('serviceWorker' in navigator)) return;
  window.addEventListener('load', () => {
    const base = import.meta.env.BASE_URL;
    navigator.serviceWorker.register(`${base}sw.js`, { scope: base }).catch(() => {
      // Sans service worker, le site fonctionne normalement (seulement sans cache hors ligne).
    });
  });
}

/** Événement « beforeinstallprompt » de Chrome sur Android (absent des types du DOM). */
type InstallPromptEvent = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> };

let deferred: InstallPromptEvent | null = null;
const listeners = new Set<() => void>();
const notify = () => listeners.forEach((l) => l());

if (typeof window !== 'undefined') {
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault(); // pas de bandeau automatique : le joueur installe depuis le bouton, quand il le veut
    deferred = e as InstallPromptEvent;
    notify();
  });
  window.addEventListener('appinstalled', () => { deferred = null; notify(); });
}

/** Proposition d'installation, quand le navigateur la permet (Chrome sur Android) ; null sinon ou déjà installée. */
export function useInstallPrompt(): (() => Promise<void>) | null {
  const [available, setAvailable] = useState(deferred !== null);

  useEffect(() => {
    const update = () => setAvailable(deferred !== null);
    listeners.add(update);
    update();
    return () => { listeners.delete(update); };
  }, []);

  if (!available) return null;
  return async () => {
    const event = deferred;
    if (!event) return;
    await event.prompt();
    await event.userChoice;
    deferred = null;
    notify();
  };
}
