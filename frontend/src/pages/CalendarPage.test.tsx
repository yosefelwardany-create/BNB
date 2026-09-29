import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { CalendarPage } from '@/pages/CalendarPage'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * A booking that exists shows up here, with a name on it.
 *
 * The calendar used to ask the server for published listings only, while the
 * booking engine sold draft ones — so a property added by hand had a paid stay
 * against it and no row at all. What got reported was the simple version of that:
 * there is a reservation and the calendar does not show it.
 *
 * The two things worth pinning are that the row appears whatever the listing's
 * status, and that the guest's name lands on the nights they are actually there.
 */
function day(date: string, overrides: Record<string, unknown> = {}) {
  return {
    date,
    available: true,
    total_units: 1,
    sold_units: 0,
    blocked_units: 0,
    remaining_units: 1,
    occupancy_rate: 0,
    manually_blocked: false,
    minimum_nights: 1,
    maximum_nights: null,
    closed_to_arrival: false,
    closed_to_departure: false,
    rate_override: null,
    note: null,
    reservation_ids: [],
    block_ids: [],
    ...overrides,
  }
}

/** Nine days from today, so the fixture lines up with whatever today is. */
function dates(): string[] {
  const list: string[] = []
  const from = new Date()

  for (let index = 0; index < 9; index++) {
    const date = new Date(from)
    date.setDate(date.getDate() + index)
    list.push(date.toISOString().slice(0, 10))
  }

  return list
}

function renderCalendar({
  listingStatus = 'draft',
  listingId = 'lst_1',
  reservationListingId = 'lst_1',
}: {
  listingStatus?: string
  listingId?: string
  reservationListingId?: string | null
} = {}) {
  const all = dates()
  const soldFrom = all[2]!
  const soldTo = all[5]!

  const server = stubApi({
    'GET auth/me': { body: session({ permissions: ['*'] }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    'GET properties': { body: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } },
    'GET calendar': {
      body: {
        from: all[0],
        to: all[8],
        listings: [
          {
            listing_id: listingId,
            listing_name: 'Whole flat',
            listing_status: listingStatus,
            property_id: 'prp_1',
            property_name: 'Alfama Terrace',
            timezone: 'Europe/Lisbon',
            currency: 'EUR',
            inventory_scope: 'property',
            days: all.map((date) =>
              date >= soldFrom && date < soldTo
                ? day(date, { available: false, sold_units: 1, occupancy_rate: 100, reservation_ids: ['res_1'] })
                : day(date),
            ),
            summary: { nights: 9, sold: 3, blocked: 0, occupancy_rate: 33 },
          },
        ],
        reservations: [
          {
            id: 'res_1',
            confirmation_code: 'HB-000001',
            property_id: 'prp_1',
            listing_id: reservationListingId,
            unit_id: null,
            status: 'confirmed',
            status_colour: 'emerald',
            guest_name: 'Ana Silva',
            check_in_date: soldFrom,
            check_out_date: soldTo,
            nights: 3,
            guests: 2,
            source: 'direct',
            balance_due: 0,
            currency: 'EUR',
          },
        ],
        blocks: [],
      },
    },
  })

  renderWithProviders(<CalendarPage />)

  return server
}

describe('the calendar', () => {
  it('shows a row for a listing that is only a draft', async () => {
    renderCalendar({ listingStatus: 'draft' })

    // The row exists at all, which is the whole of the original complaint.
    expect(await screen.findByText('Whole flat')).toBeInTheDocument()

    // And says it is not on sale, because a grid that looked the same either way
    // would imply the flat was live to guests when it is not.
    expect(screen.getByText(/not on sale · draft/)).toBeInTheDocument()
  })

  it('does not label a published listing as anything', async () => {
    renderCalendar({ listingStatus: 'published' })

    expect(await screen.findByText('Whole flat')).toBeInTheDocument()
    expect(screen.queryByText(/not on sale/)).not.toBeInTheDocument()
  })

  it('puts the guest’s name on the nights they are there', async () => {
    renderCalendar()

    const cells = await screen.findAllByTitle(/Ana Silva/)

    expect(cells).toHaveLength(3)
  })

  it('still names the guest when the booking carries no listing', async () => {
    // Reachable because the column is nullable. The fallback to the property was
    // written and could never fire, since rows are keyed by listing — a booking
    // like this showed as sold with nobody's name against it.
    renderCalendar({ reservationListingId: null })

    expect(await screen.findAllByTitle(/Ana Silva/)).toHaveLength(3)
  })
})
