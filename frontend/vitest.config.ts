import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

/**
 * The test configuration, kept apart from vite.config.ts.
 *
 * The build config carries a dev proxy and a chunking strategy that have
 * nothing to do with tests, and merging the two means every change to one is a
 * change to the other.
 */
export default defineConfig({
  plugins: [react()],

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },

  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],

    // No globals. `describe` and `expect` are imported like anything else, so
    // a test file type-checks under the same tsconfig as the application and
    // an editor can follow them.
    globals: false,

    include: ['src/**/*.test.{ts,tsx}'],

    // A component that renders differently on a second run has a bug, and
    // isolation is what makes that visible rather than intermittent.
    restoreMocks: true,
    clearMocks: true,

    coverage: {
      provider: 'v8',
      reporter: ['text', 'html'],
      include: ['src/**/*.{ts,tsx}'],
      exclude: ['src/**/*.test.{ts,tsx}', 'src/test/**', 'src/main.tsx', 'src/vite-env.d.ts'],
    },
  },
})
