import type { Money } from '@/api/types'

/**
 * Formatting helpers.
 *
 * Money is formatted from the integer minor units the API sends, using the
 * currency it names. Nothing here parses a preformatted string back into a
 * number.
 */

export function formatMoney(money: Money | null | undefined, locale = 'en-GB'): string {
  if (!money) return '—'

  // Currencies differ in how many minor units they have; the API's decimal
  // string already accounts for that, so it is the input to formatting.
  const value = Number(money.formatted)

  if (Number.isNaN(value)) return `${money.formatted} ${money.currency}`

  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency: money.currency,
  }).format(value)
}

export function formatDate(value: string | null | undefined, locale = 'en-GB'): string {
  if (!value) return '—'

  return new Intl.DateTimeFormat(locale, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(new Date(value))
}

export function formatDateRange(from: string, to: string, locale = 'en-GB'): string {
  const start = new Date(from)
  const end = new Date(to)

  const sameMonth = start.getMonth() === end.getMonth() && start.getFullYear() === end.getFullYear()

  const startFormat = new Intl.DateTimeFormat(locale, {
    day: 'numeric',
    month: sameMonth ? undefined : 'short',
  }).format(start)

  const endFormat = new Intl.DateTimeFormat(locale, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(end)

  return `${startFormat} – ${endFormat}`
}

export function formatNumber(value: number, locale = 'en-GB'): string {
  return new Intl.NumberFormat(locale).format(value)
}

export function formatPercent(value: number, locale = 'en-GB'): string {
  return new Intl.NumberFormat(locale, {
    style: 'percent',
    maximumFractionDigits: 1,
  }).format(value / 100)
}

/** "in 3 days", "2 days ago" — used on arrival and departure lists. */
export function relativeDays(days: number, locale = 'en-GB'): string {
  const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' })

  return formatter.format(days, 'day')
}

export function toDateInput(date: Date): string {
  return date.toISOString().slice(0, 10)
}

export function addDays(date: Date, days: number): Date {
  const next = new Date(date)
  next.setDate(next.getDate() + days)
  return next
}
