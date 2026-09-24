/**
 * Transient confirmations.
 *
 * For acknowledging something the user just did that has no other visible
 * result — a value copied, a theme changed, an organization switched to.
 * Failures that need reading stay inline next to the thing that failed; a
 * toast is gone in four seconds.
 */

export interface Toast {
  id: number
  title: string
  body?: string
  tone: 'success' | 'error'
}

export const TOAST_DURATION = 4000

let toasts: Toast[] = []
let nextId = 1
const listeners = new Set<() => void>()

function emit() {
  listeners.forEach((listener) => listener())
}

export function toast(title: string, options: { body?: string; tone?: Toast['tone'] } = {}) {
  const item: Toast = { id: nextId++, title, body: options.body, tone: options.tone ?? 'success' }
  toasts = [...toasts.slice(-3), item]
  emit()
}

export function dismissToast(id: number) {
  toasts = toasts.filter((item) => item.id !== id)
  emit()
}

export function subscribeToasts(listener: () => void) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function currentToasts(): Toast[] {
  return toasts
}
