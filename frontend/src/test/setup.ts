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

// React only warns when two siblings share a key, and the warning scrolls
// past. In a browser the clash remounts part of the screen on the next redraw
// and leaves the first copy drawn but dead: the Accounts page's Add login
// dialog stopped answering that way. A clash fails the test that renders it.
const clashes: string[] = []
const consoleError = console.error.bind(console)
console.error = (...args: unknown[]) => {
  if (typeof args[0] === 'string' && args[0].includes('two children with the same key')) {
    let next = 1
    clashes.push(args[0].replace(/%s/g, () => String(args[next++])).slice(0, 300))
  }
  consoleError(...args)
}

beforeEach(() => {
  localStorage.clear()
  clashes.length = 0
})

afterEach(() => {
  cleanup()
  localStorage.clear()

  if (clashes.length > 0) {
    throw new Error(`Siblings share a React key: ${clashes[0]}`)
  }
})
