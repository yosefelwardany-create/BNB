import '@testing-library/jest-dom/vitest'
import { afterEach, beforeEach } from 'vitest'
import { cleanup } from '@testing-library/react'

/**
 * Global test setup.
 *
 * Two things matter here. The DOM is torn down between tests, and so is
 * localStorage — the API client keeps the session token there, and a token
 * surviving into the next test is how a suite starts passing for the wrong
 * reason.
 */

beforeEach(() => {
  localStorage.clear()
})

afterEach(() => {
  cleanup()
  localStorage.clear()
})
