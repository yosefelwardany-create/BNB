import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { CalendarPage } from '@/pages/CalendarPage'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'
import { addCalendarDays, toDateInput } from '@/lib/format'

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
    list.push(addCalendarDays(toDateInput(from), index))
  }

  return list
}

function renderCalendar({
  listingStatus = 'draft',
  listingId = 'lst_1',
  reservationListingId = 'lst_1',
  organizationTimezone = 'UTC',
  sourceBlocked = false,
}: {
  listingStatus?: string
  listingId?: string
  reservationListingId?: string | null
  organizationTimezone?: string
  sourceBlocked?: boolean
} = {}) {
  const all = dates()
  const soldFrom = all[2]!
  const soldTo = all[5]!

  const server = stubApi({
    'GET auth/me': { body: session({ permissions: ['*'], organization: { ...session().organization!, timezone: organizationTimezone } }) },
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
                : day(date, sourceBlocked && date === all[6] ? { available: false, blocked_units: 1, remaining_units: 0, source_available: false, source_synced_at: '2026-10-03T00:00:00Z' } : {}),
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
  it('shows imported unavailable nights separately from named bookings', async () => {
    renderCalendar({ sourceBlocked: true })
    const blocked = await screen.findByTitle(/Unavailable \(booked or blocked\)/)
    expect(blocked).toHaveTextContent('×')
    expect(blocked).toHaveClass('calendar__day--blocked')
    expect(screen.getAllByTitle(/Guest: Ana Silva/)).toHaveLength(3)
  })
  it('Today returns to the organization day when UTC is still yesterday', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-02T23:30:00Z'))
    try {
      renderCalendar({ organizationTimezone: 'Africa/Cairo' })
      await screen.findByText('Whole flat')
      const from = screen.getByLabelText('From')
      fireEvent.change(from, { target: { value: '2026-09-01' } })
      await userEvent.click(screen.getByRole('button', { name: 'Today' }))
      expect(from).toHaveValue('2026-10-03')
    } finally {
      vi.useRealTimers()
    }
  })

  it('keeps each date and occupied night aligned across daylight saving changes', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-03-07T15:00:00Z'))
    try {
      renderCalendar()
      const occupied = await screen.findAllByTitle(/Guest: Ana Silva/)
      expect(occupied.map((cell) => cell.title.slice(0, 10))).toEqual(['2026-03-09', '2026-03-10', '2026-03-11'])
      const headings = screen.getAllByRole('columnheader').slice(1, 4)
      expect(headings.map((heading) => heading.querySelector('.strong')?.textContent)).toEqual(['7', '8', '9'])
    } finally {
      vi.useRealTimers()
    }
  })

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

/**
 * Unblocking dates.
 *
 * `DELETE calendar/blocks/{id}` has always existed and nothing in the interface
 * called it, so blocking dates was a one-way door: the nights stayed shut, every
 * booking across them came back "already booked or blocked", and there was no way
 * to see which block was doing it. What got reported was the short version —
 * still blocked dates.
 */
describe('blocked dates', () => {
  function withBlock(overrides: Record<string, unknown> = {}) {
    const all = dates()

    return {
      id: 'blk_1',
      property_id: 'prp_1',
      unit_id: null,
      kind: 'maintenance',
      label: 'Boiler replaced',
      start_date: all[2]!,
      end_date: all[5]!,
      nights: 3,
      ...overrides,
    }
  }

  function renderWithBlocks(permissions: string[] = ['*'], blocks = [withBlock()]) {
    const all = dates()
    const server = stubApi({
      'GET auth/me': { body: session({ permissions }) },
      'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
      'GET properties': { body: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } },
      'GET calendar': {
        body: {
          from: all[0],
          to: all[8],
          listings: [
            {
              listing_id: 'lst_1',
              listing_name: 'Whole flat',
              listing_status: 'published',
              property_id: 'prp_1',
              property_name: 'Alfama Terrace',
              timezone: 'Europe/Lisbon',
              currency: 'EUR',
              inventory_scope: 'property',
              days: all.map((date) => day(date)),
              summary: { nights: 9, sold: 0, blocked: 3, occupancy_rate: 0 },
            },
          ],
          reservations: [],
          blocks,
        },
      },
    })

    renderWithProviders(<CalendarPage />)

    return server
  }

  it('names the block that shut the dates, and which property', async () => {
    renderWithBlocks()

    // A shaded cell does not say which block shut it, and the block payload
    // carries only a property id — not something to ask anybody to recognise.
    expect(await screen.findByText('Boiler replaced')).toBeInTheDocument()
    expect(screen.getByText(/Blocked dates in this range/)).toBeInTheDocument()
    expect(screen.getAllByText('Alfama Terrace').length).toBeGreaterThan(0)
  })

  it('unblocks them', async () => {
    const server = renderWithBlocks()
    server.on('DELETE calendar/blocks/blk_1', { body: { message: 'Block removed.' } })

    await userEvent.click(await screen.findByRole('button', { name: /Unblock/ }))

    expect(server.callsTo('DELETE', 'calendar/blocks/blk_1')).toHaveLength(1)
  })

  it('says nothing when there is nothing blocked', async () => {
    renderWithBlocks(['*'], [])

    expect(await screen.findByText('Whole flat')).toBeInTheDocument()
    expect(screen.queryByText(/Blocked dates in this range/)).not.toBeInTheDocument()
  })

  it('shows the block but not the button to somebody who may only look', async () => {
    renderWithBlocks(['calendar.view'])

    expect(await screen.findByText('Boiler replaced')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Unblock/ })).not.toBeInTheDocument()
  })
})
