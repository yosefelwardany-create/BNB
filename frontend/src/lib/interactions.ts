import { toast } from '@/lib/toast'

/**
 * Pointer effects that apply to every screen at once.
 *
 * Installed once, as delegated listeners on the document, so the thirty-odd
 * screens get them from their existing class names without each one wiring
 * anything up:
 *
 *   - a spotlight that follows the pointer across any .card
 *   - a slight 3D tilt towards the pointer on any .tilt
 *   - a ripple from the point a .btn or .icon-btn was pressed
 *   - click-to-copy on a .secret, the credentials shown exactly once
 *
 * The first two are decoration and stand down for anybody who has asked their
 * system for less motion. The third is behaviour and always works.
 */

export function prefersReducedMotion(): boolean {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return true
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

export function installInteractions(): () => void {
  let frame = 0
  let pending: PointerEvent | null = null
  let tilted: HTMLElement | null = null

  function spotlight() {
    frame = 0
    const event = pending
    if (event === null) return

    const target = event.target as Element | null
    const card = target?.closest?.('.card')

    if (card instanceof HTMLElement) {
      const rect = card.getBoundingClientRect()
      card.style.setProperty('--mx', `${event.clientX - rect.left}px`)
      card.style.setProperty('--my', `${event.clientY - rect.top}px`)
    }

    const tilt = target?.closest?.('.tilt')
    const next = tilt instanceof HTMLElement && !prefersReducedMotion() ? tilt : null

    if (tilted !== null && tilted !== next) {
      tilted.style.removeProperty('--rx')
      tilted.style.removeProperty('--ry')
    }
    tilted = next

    if (next !== null) {
      const rect = next.getBoundingClientRect()
      const x = (event.clientX - rect.left) / rect.width - 0.5
      const y = (event.clientY - rect.top) / rect.height - 0.5
      next.style.setProperty('--rx', `${(-y * 6).toFixed(2)}deg`)
      next.style.setProperty('--ry', `${(x * 8).toFixed(2)}deg`)
    }
  }

  function onPointerMove(event: PointerEvent) {
    if (event.pointerType !== 'mouse') return
    pending = event
    if (frame === 0) frame = requestAnimationFrame(spotlight)
  }

  function onPointerDown(event: PointerEvent) {
    if (prefersReducedMotion()) return

    const button = (event.target as Element | null)?.closest?.('.btn, .icon-btn')
    if (!(button instanceof HTMLElement)) return
    if (button.matches(':disabled, [aria-disabled="true"]')) return

    const rect = button.getBoundingClientRect()
    const size = Math.max(rect.width, rect.height) * 2.2
    const ripple = document.createElement('span')
    ripple.className = 'ripple'
    ripple.style.width = `${size}px`
    ripple.style.height = `${size}px`
    ripple.style.left = `${event.clientX - rect.left - size / 2}px`
    ripple.style.top = `${event.clientY - rect.top - size / 2}px`
    ripple.addEventListener('animationend', () => ripple.remove(), { once: true })
    button.appendChild(ripple)
  }

  function onClick(event: MouseEvent) {
    const secret = (event.target as Element | null)?.closest?.('.secret')
    if (!(secret instanceof HTMLElement)) return

    const text = secret.textContent?.trim() ?? ''
    if (text === '' || navigator.clipboard === undefined) return

    navigator.clipboard.writeText(text).then(
      () => toast('Copied to clipboard', { body: 'Store it somewhere safe — it will not be shown again.' }),
      () => toast('Could not copy', { body: 'Select the text and copy it by hand.', tone: 'error' }),
    )
  }

  document.addEventListener('pointermove', onPointerMove, { passive: true })
  document.addEventListener('pointerdown', onPointerDown, { passive: true })
  document.addEventListener('click', onClick)

  return () => {
    document.removeEventListener('pointermove', onPointerMove)
    document.removeEventListener('pointerdown', onPointerDown)
    document.removeEventListener('click', onClick)
    if (frame !== 0) cancelAnimationFrame(frame)
  }
}

/**
 * A small burst of lime leaves from an element — for finishing something.
 * Decoration only: nothing waits on it, and it does not happen at all for
 * anybody who has asked for less motion.
 */
export function burst(origin: Element) {
  if (prefersReducedMotion()) return

  const rect = origin.getBoundingClientRect()
  const layer = document.createElement('div')
  layer.className = 'burst'
  layer.style.left = `${rect.left + rect.width / 2}px`
  layer.style.top = `${rect.top + rect.height / 2}px`

  for (let index = 0; index < 14; index++) {
    const leaf = document.createElement('span')
    const angle = (index / 14) * Math.PI * 2 + Math.random() * 0.4
    const distance = 40 + Math.random() * 46
    leaf.style.setProperty('--x', `${Math.cos(angle) * distance}px`)
    leaf.style.setProperty('--y', `${Math.sin(angle) * distance - 20}px`)
    leaf.style.setProperty('--r', `${Math.random() * 540 - 270}deg`)
    leaf.style.animationDelay = `${Math.random() * 60}ms`
    layer.appendChild(leaf)
  }

  document.body.appendChild(layer)
  window.setTimeout(() => layer.remove(), 1100)
}
