/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    // Origine du frontend : le client lit les cookies (XSRF-TOKEN) de ce domaine.
    environmentOptions: { jsdom: { url: 'http://localhost:5173' } },
  },
})
