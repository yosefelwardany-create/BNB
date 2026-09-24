import js from '@eslint/js'
import globals from 'globals'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import tseslint from 'typescript-eslint'

/**
 * Linting.
 *
 * Type-aware rules, because the ones worth having are the ones that need to
 * know what a value is: a promise nobody awaited, a `catch` that swallows, a
 * truthiness check on a string that is legitimately empty. `npm run lint` was a
 * script with no configuration behind it until this existed, which is a check
 * that reports success without running.
 */
export default tseslint.config(
  { ignores: ['dist', 'coverage'] },

  {
    files: ['**/*.{ts,tsx}'],
    extends: [js.configs.recommended, ...tseslint.configs.recommendedTypeChecked],

    languageOptions: {
      ecmaVersion: 2022,
      globals: globals.browser,
      parserOptions: {
        project: ['./tsconfig.app.json', './tsconfig.node.json'],
        tsconfigRootDir: import.meta.dirname,
      },
    },

    plugins: {
      'react-hooks': reactHooks,
      'react-refresh': reactRefresh,
    },

    rules: {
      ...reactHooks.configs.recommended.rules,

      'react-refresh/only-export-components': ['warn', { allowConstantExport: true }],

      // A rejected promise nobody handles is a screen that silently stops
      // updating, so an unawaited call must be marked `void` deliberately.
      '@typescript-eslint/no-floating-promises': 'error',

      // The API's shapes are declared in api/types.ts. An `any` that reaches a
      // component is a shape nobody checked.
      '@typescript-eslint/no-explicit-any': 'error',

      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
      ],
    },
  },

  {
    // Vite's configs run in Node, not a browser.
    files: ['vite.config.ts', 'vitest.config.ts'],
    languageOptions: { globals: globals.node },
  },

  {
    // This file itself. It is not part of a TypeScript project, so the
    // type-aware rules have nothing to read.
    files: ['**/*.js'],
    extends: [tseslint.configs.disableTypeChecked],
    languageOptions: { globals: globals.node },
  },
)
