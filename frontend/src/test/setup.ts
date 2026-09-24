import '@testing-library/jest-dom/vitest'
import { afterEach, beforeEach } from 'vitest'
import { cleanup } from '@testing-library/react'
import { MotionGlobalConfig } from 'motion/react'

/**
 * Global test setup.
 *
 * Two things matter here. The DOM is torn down between tests, and so is
 * localStorage — the API client keeps the session token there, and a token
 * surviving into the next test is how a suite starts passing for the wrong
 * reason.
 */

// Animations finish instantly. Assertions are about what a screen shows, and
// an exit transition still running would keep an element in the document.
MotionGlobalConfig.skipAnimations = true

// jsdom does no layout, so it has no scrolling to do; the command palette
// keeps its highlighted row in view with this.
Element.prototype.scrollIntoView = function scrollIntoView() {}

beforeEach(() => {
  localStorage.clear()
})

afterEach(() => {
  cleanup()
  localStorage.clear()
})
