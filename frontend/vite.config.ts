/// <reference types="vitest/config" />
import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, '.')

  return {
    plugins: [react()],
    // Chemin de publication. "/" en développement ; sous-dossier pour XAMPP (npm run build:xampp, voir .env.xampp).
    base: env.VITE_BASE || '/',
    test: {
      environment: 'jsdom',
      setupFiles: ['./src/test/setup.ts'],
      // Origine du frontend : le client lit les cookies (XSRF-TOKEN) de ce domaine.
      environmentOptions: { jsdom: { url: 'http://localhost:5173' } },
    },
  }
})
