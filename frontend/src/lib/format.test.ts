import { describe, expect, it } from 'vitest'
import {
  addDays,
  formatDate,
  formatDateRange,
  formatMoney,
  formatNumber,
  formatPercent,
  relativeDays,
  toDateInput,
} from '@/lib/format'

/**
 * Formatting.
 *
 * Small functions, but they are the last step before a number reaches a person
 * who will act on it, and the failure mode is silent: a figure that is wrong by
 * a factor of a hundred still looks like a figure.
 */
/** Every kind of space ICU might use, flattened to the ordinary one. */
function spaces(value: string): string {
  return value.replace(/[\u00a0\u202f\u2009]/g, ' ')
}

describe('formatMoney', () => {
  it('formats from the decimal string the API sends, in the currency it names', () => {
    expect(formatMoney({ amount: 45000, currency: 'EUR', formatted: '450.00' })).toBe('€450.00')
    expect(formatMoney({ amount: 45000, currency: 'GBP', formatted: '450.00' })).toBe('£450.00')
  })

  it('does not divide by a hundred itself', () => {
    // The whole contract: `amount` is minor units and `formatted` has already
    // accounted for however many the currency has. A frontend that divided
    // would be wrong for JPY, which has none, and for KWD, which has three.
    expect(formatMoney({ amount: 45000, currency: 'JPY', formatted: '45000' })).toBe('JP¥45,000')
    // Intl separates a currency code from the amount with a non-breaking
    // space, which is correct and invisible; normalised so the assertion is
    // about the digits rather than about which space ICU chose.
    expect(spaces(formatMoney({ amount: 45000, currency: 'KWD', formatted: '45.000' }))).toBe(
      'KWD 45.000',
    )
  })

  it('shows a dash rather than a zero for an absent amount', () => {
    // Zero and "we do not have this figure" are different facts, and a
    // financial screen that renders them identically is misleading.
    expect(formatMoney(null)).toBe('—')
    expect(formatMoney(undefined)).toBe('—')
    expect(formatMoney({ amount: 0, currency: 'EUR', formatted: '0.00' })).toBe('€0.00')
  })

  it('falls back to showing the raw value rather than NaN', () => {
    expect(formatMoney({ amount: 0, currency: 'EUR', formatted: 'n/a' })).toBe('n/a EUR')
  })
})

describe('formatDate', () => {
  it('formats a date', () => {
    expect(formatDate('2025-06-01T10:00:00+00:00')).toBe('1 Jun 2025')
  })

  it('shows a dash for nothing', () => {
    expect(formatDate(null)).toBe('—')
    expect(formatDate(undefined)).toBe('—')
  })
})

describe('formatDateRange', () => {
  it('drops the repeated month when a stay does not cross one', () => {
    expect(formatDateRange('2025-06-01', '2025-06-05')).toBe('1 – 5 Jun 2025')
  })

  it('keeps both months when it does', () => {
    expect(formatDateRange('2025-06-28', '2025-07-02')).toBe('28 Jun – 2 Jul 2025')
  })
})

describe('formatPercent', () => {
  it('treats the input as a percentage, not a fraction', () => {
    // The API sends occupancy as 67.4 meaning 67.4%. Reading it as a fraction
    // would render 6,740%.
    expect(formatPercent(67.4)).toBe('67.4%')
    expect(formatPercent(100)).toBe('100%')
    expect(formatPercent(0)).toBe('0%')
  })
})

describe('formatNumber', () => {
  it('groups thousands', () => {
    expect(formatNumber(1234567)).toBe('1,234,567')
  })
})

describe('relativeDays', () => {
  it('reads as a person would say it', () => {
    expect(relativeDays(3)).toBe('in 3 days')
    expect(relativeDays(-2)).toBe('2 days ago')
    expect(relativeDays(0)).toBe('today')
    expect(relativeDays(1)).toBe('tomorrow')
  })
})

describe('date inputs', () => {
  it('produces the value an <input type="date"> expects', () => {
    expect(toDateInput(new Date('2025-06-01T00:00:00Z'))).toBe('2025-06-01')
  })

  it('adds days without mutating the date it was given', () => {
    const original = new Date('2025-06-01T00:00:00Z')
    const later = addDays(original, 5)

    expect(toDateInput(later)).toBe('2025-06-06')
    expect(toDateInput(original)).toBe('2025-06-01')
  })

  it('rolls over a month boundary', () => {
    expect(toDateInput(addDays(new Date('2025-01-30T00:00:00Z'), 3))).toBe('2025-02-02')
  })
})
