import type { Money, SourceMoney } from '@/api/types'

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

export function formatSourceMoney(money: SourceMoney | null | undefined): string {
  if (!money) return 'Unavailable'
  if (!money.currency || money.amount === null) return `${money.formatted} (currency unavailable)`
  return `${formatMoney({ amount: money.amount, currency: money.currency, formatted: money.formatted })} ${money.currency}`
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

export function dateInTimezone(date: Date, timezone: string): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(date)
  return ['year', 'month', 'day'].map((type) => parts.find((part) => part.type === type)?.value).join('-')
}

export function addCalendarDays(date: string, days: number): string {
  const next = new Date(`${date}T12:00:00Z`)
  next.setUTCDate(next.getUTCDate() + days)
  return toDateInput(next)
}

export function addDays(date: Date, days: number): Date {
  const next = new Date(date)
  next.setDate(next.getDate() + days)
  return next
}

/** Initials for the avatar: first letter of the first and last word. */
export function initials(name: string | undefined): string {
  const words = (name ?? '').trim().split(/\s+/).filter(Boolean)
  if (words.length === 0) return '?'
  const first = words[0]?.[0] ?? ''
  const last = words.length > 1 ? (words[words.length - 1]?.[0] ?? '') : ''
  return (first + last).toUpperCase()
}

/** A timestamp with its time, or a dash: for audit rows and last sign-ins. */
export function formatDateTime(value: string | null | undefined, locale = 'en-GB'): string {
  if (!value) return '—'

  return new Intl.DateTimeFormat(locale, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value))
}
