import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },

  // The admin application is served from /app on the same host as the API, so
  // built asset URLs must carry that prefix.
  base: '/app/',

  server: {
    port: 5173,
    // In development the SPA runs on its own origin and the API on another;
    // proxying keeps them same-origin so cookies and relative URLs behave as
    // they do in production.
    proxy: {
      '/api': {
        target: process.env.VITE_API_URL ?? 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },

  build: {
    outDir: 'dist',
    sourcemap: true,
    rollupOptions: {
      output: {
        // Split the vendor bundle so a change to application code does not
        // invalidate the whole download for returning users.
        manualChunks: {
          react: ['react', 'react-dom', 'react-router-dom'],
          query: ['@tanstack/react-query'],
        },
      },
    },
  },
})
