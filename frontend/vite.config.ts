import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Decoupled SPA. Talks to the Laravel API over Sanctum cookie auth and to
// Laravel Reverb for real-time updates. API base + Reverb config come from env.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    host: true,
  },
  build: {
    outDir: 'dist',
    sourcemap: true,
  },
});
