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
    // Straight into Laravel's document root, because that is where it is
    // served from. Building to a local `dist` meant the only thing that ever
    // put the bundle where the web server looks was a COPY line in the
    // Dockerfile — so following the README locally produced a working API and
    // a 404 at /app, which reads as a broken install rather than a missing
    // step.
    outDir: fileURLToPath(new URL('../public/app', import.meta.url)),

    // The target is outside the Vite project root, so consent to clearing it
    // has to be explicit; without this Vite asks, and a CI build has nobody to
    // answer.
    emptyOutDir: true,

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
