import { useSyncExternalStore } from 'react'

/**
 * Light and dark themes.
 *
 * A module-level store rather than a context provider, so any component can
 * read or change the theme without the tree having to be wrapped — which also
 * keeps the test harness, which renders screens with the real providers only,
 * unchanged.
 *
 * The preference is remembered per browser. "system" follows the operating
 * system and keeps following it if it changes while the app is open.
 */

export type ThemePreference = 'light' | 'dark' | 'system'
export type Theme = 'light' | 'dark'

const STORAGE_KEY = 'habitat.theme'

const listeners = new Set<() => void>()

function readPreference(): ThemePreference {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)
    if (stored === 'light' || stored === 'dark' || stored === 'system') return stored
  } catch {
    // Storage can be unavailable (private windows, blocked site data).
  }
  return 'system'
}

function systemTheme(): Theme {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return 'light'
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

let preference: ThemePreference = 'system'
let resolved: Theme = 'light'

function apply() {
  resolved = preference === 'system' ? systemTheme() : preference
  document.documentElement.dataset.theme = resolved
  document
    .querySelector('meta[name="theme-color"]')
    ?.setAttribute('content', resolved === 'dark' ? '#141a11' : '#1e271b')
  listeners.forEach((listener) => listener())
}

/** Called once at start-up, before the first render, so nothing flashes. */
export function initTheme() {
  preference = readPreference()
  apply()

  if (typeof window.matchMedia === 'function') {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (preference === 'system') apply()
    })
  }
}

export function setThemePreference(next: ThemePreference) {
  preference = next
  try {
    localStorage.setItem(STORAGE_KEY, next)
  } catch {
    // Not remembered, but still applied for this visit.
  }
  apply()
}

export function toggleTheme() {
  setThemePreference(resolved === 'dark' ? 'light' : 'dark')
}

function subscribe(listener: () => void) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function useTheme(): { theme: Theme; preference: ThemePreference } {
  const theme = useSyncExternalStore(subscribe, () => resolved, (): Theme => 'light')
  const pref = useSyncExternalStore(subscribe, () => preference, (): ThemePreference => 'system')

  return { theme, preference: pref }
}
