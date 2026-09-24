import { describe, expect, it } from 'vitest'
import { initTheme, setThemePreference, toggleTheme } from '@/lib/theme'

/**
 * Light and dark.
 *
 * The theme is an attribute on the document root that every colour in the
 * stylesheet keys off, and a preference remembered per browser.
 */
describe('theme', () => {
  it('starts from the system, which here has no preference, so light', () => {
    initTheme()

    expect(document.documentElement.dataset.theme).toBe('light')
  })

  it('applies and remembers an explicit choice', () => {
    setThemePreference('dark')

    expect(document.documentElement.dataset.theme).toBe('dark')
    expect(localStorage.getItem('habitat.theme')).toBe('dark')
  })

  it('toggles between the two', () => {
    setThemePreference('light')
    toggleTheme()

    expect(document.documentElement.dataset.theme).toBe('dark')

    toggleTheme()

    expect(document.documentElement.dataset.theme).toBe('light')
  })

  it('restores the remembered choice on the next visit', () => {
    localStorage.setItem('habitat.theme', 'dark')

    initTheme()

    expect(document.documentElement.dataset.theme).toBe('dark')
  })
})
