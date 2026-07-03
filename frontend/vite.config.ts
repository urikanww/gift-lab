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
    rollupOptions: {
      output: {
        // Split large, rarely-changing vendor libraries out of the entry chunk
        // so they cache independently and the initial payload stays small.
        // (fabric.js and three.js are pulled in only by lazy route chunks, so
        // Rollup already keeps them out of the entry chunk on their own.)
        manualChunks: {
          react: ['react', 'react-dom'],
          router: ['react-router-dom'],
          motion: ['framer-motion'],
          realtime: ['laravel-echo', 'pusher-js'],
        },
      },
    },
  },
});
