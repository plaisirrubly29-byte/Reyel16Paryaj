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
    // Dossier séparé pour XAMPP : un "npm run build" classique ne peut plus écraser la version servie par Apache.
    build: { outDir: mode === 'xampp' ? 'dist-xampp' : 'dist' },
    server: {
      // Avec VITE_API_URL=/api/v1, l'API est servie depuis l'origine du frontend : ni CORS ni cookies tiers.
      proxy: {
        '/api': env.VITE_API_PROXY || 'http://localhost:8000',
        '/sanctum': env.VITE_API_PROXY || 'http://localhost:8000',
      },
    },
    test: {
      environment: 'jsdom',
      setupFiles: ['./src/test/setup.ts'],
      // Origine du frontend : le client lit les cookies (XSRF-TOKEN) de ce domaine.
      environmentOptions: { jsdom: { url: 'http://localhost:5173' } },
    },
  }
})
