import { useSyncExternalStore } from 'react'

/**
 * Whether the platform owner is looking at the selected account the way its
 * client does.
 *
 * Purely a choice of screens: the client view reads the same read-only portal
 * endpoints the client's own login uses, and the server answers them for the
 * platform owner with the account holder's portfolio. Nothing about access
 * changes, so this lives in the browser like the theme, and is remembered per
 * browser so a reload keeps the view that was on screen.
 */

const STORAGE_KEY = 'habitat.clientView'

const listeners = new Set<() => void>()

function read(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) === 'on'
  } catch {
    // Storage can be unavailable (private windows, blocked site data).
    return false
  }
}

let enabled = read()

export function setClientView(next: boolean) {
  enabled = next
  try {
    if (next) {
      localStorage.setItem(STORAGE_KEY, 'on')
    } else {
      localStorage.removeItem(STORAGE_KEY)
    }
  } catch {
    // Not remembered, but still applied for this visit.
  }
  listeners.forEach((listener) => listener())
}

function subscribe(listener: () => void) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function useClientView(): boolean {
  return useSyncExternalStore(subscribe, () => enabled, () => false)
}
