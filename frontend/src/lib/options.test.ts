import { describe, expect, it } from 'vitest'
import { listingOptions } from '@/lib/options'
import type { Listing } from '@/api/types'

/**
 * Labels a person can tell apart.
 *
 * This is the whole of the bug that made the booking form unusable: a listing's
 * guest-facing title falls back to its property's name, so every listing of one
 * property read identically. The picker showed the same entry several times and
 * the listings behind those repeats were, from the person's side, missing.
 *
 * The rule being pinned is narrow and absolute: no two options may ever read the
 * same. Everything else about the label is presentation.
 */
function listing(overrides: Partial<Listing> & { id: string }): Listing {
  return {
    property_id: 'prp_1',
    name: 'Alfama Terrace',
    status: 'draft',
    is_primary: true,
    is_bookable: false,
    inventory_scope: 'property',
    title: 'Alfama Terrace',
    currency: 'EUR',
    pricing: {
      base_rate: { amount: 14500, currency: 'EUR', formatted: '€145.00' },
      cleaning_fee: { amount: 0, currency: 'EUR', formatted: '€0.00' },
      minimum_nights: 1,
      maximum_nights: null,
    },
    overridden_fields: [],
    published_at: null,
    property: { id: 'prp_1', name: 'Alfama Terrace', status: 'active' },
    ...overrides,
  }
}

describe('the listing picker', () => {
  it('shows the property name for a property with one listing', () => {
    expect(listingOptions([listing({ id: 'lst_1' })])).toEqual([
      { value: 'lst_1', label: 'Alfama Terrace' },
    ])
  })

  it('names the listing too once a property has more than one', () => {
    const options = listingOptions([
      listing({ id: 'lst_1' }),
      listing({ id: 'lst_2', name: 'Garden room only' }),
    ])

    expect(options.map((option) => option.label)).toEqual([
      'Alfama Terrace',
      'Alfama Terrace · Garden room only',
    ])
  })

  it('never produces two options that read the same', () => {
    // The real shape of the complaint: several listings of one property, all
    // carrying the property's title, two of them named the same as each other.
    const options = listingOptions([
      listing({ id: 'lst_aaaaaa' }),
      listing({ id: 'lst_bbbbbb', name: 'Garden room only' }),
      listing({ id: 'lst_cccccc', name: 'Garden room only' }),
    ])

    const labels = options.map((option) => option.label)

    expect(new Set(labels).size).toBe(labels.length)
    expect(labels.some((label) => label.includes('cccccc'))).toBe(true)
  })

  it('keeps every listing, one option each', () => {
    const options = listingOptions([
      listing({ id: 'lst_1' }),
      listing({ id: 'lst_2', name: 'Garden room only' }),
      listing({
        id: 'lst_3',
        property_id: 'prp_2',
        name: 'Baixa Riverside',
        title: 'Baixa Riverside',
        property: { id: 'prp_2', name: 'Baixa Riverside', status: 'active' },
      }),
    ])

    expect(options).toHaveLength(3)
    expect(new Set(options.map((option) => option.value)).size).toBe(3)
  })

  it('marks a property that cannot be booked yet rather than dropping it', () => {
    const [option] = listingOptions([
      listing({
        id: 'lst_1',
        property: { id: 'prp_1', name: 'Alfama Terrace', status: 'draft' },
      }),
    ])

    // Hiding it would be the original bug in a new costume: visible in the
    // portfolio, absent here, no reason given. The booking would be refused for
    // exactly this reason, so the label says it first.
    expect(option?.label).toBe('Alfama Terrace — property is draft')
  })

  it('still labels a listing whose response did not carry its property', () => {
    const [option] = listingOptions([listing({ id: 'lst_1', property: undefined })])

    expect(option?.label).toBe('Alfama Terrace')
  })

  it('sorts by what is on screen', () => {
    const options = listingOptions([
      listing({
        id: 'lst_2',
        property_id: 'prp_2',
        title: 'Zebra House',
        name: 'Zebra House',
        property: { id: 'prp_2', name: 'Zebra House', status: 'active' },
      }),
      listing({ id: 'lst_1' }),
    ])

    expect(options.map((option) => option.label)).toEqual(['Alfama Terrace', 'Zebra House'])
  })
})
