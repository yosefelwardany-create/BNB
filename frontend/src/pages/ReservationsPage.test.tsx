import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ReservationsPage } from '@/pages/ReservationsPage'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { page, stubApi } from '@/test/server'

/**
 * Taking a booking by hand.
 *
 * The case that matters most here is the least glamorous one: recording business
 * that already exists. Anybody arriving from another system has months of
 * finished stays and guests in the building today, and the availability engine
 * refuses an arrival in the past — correctly, for a sale. So the form has to be
 * able to say "this already happened", and it has to send when the booking was
 * actually taken, or every lead-time figure is drawn from the day it was typed in.
 */
function listing() {
  return {
    id: 'lst_1',
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
  }
}

function renderReservations(permissions: string[] = ['*'], rows: Record<string, unknown>[] = []) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    'GET reservations': { body: page(rows) },
    'GET listings': { body: page([listing()]) },
  })

  renderWithProviders(<ReservationsPage />)

  return server
}

describe('entering a booking by hand', () => {
  it('records a stay that has already started', async () => {
    const server = renderReservations()
    server.on('POST reservations', { status: 201, body: { data: { id: 'res_1' } } })

    await userEvent.click(await screen.findByRole('button', { name: /New booking/ }))

    await userEvent.selectOptions(screen.getByLabelText(/Listing/), 'lst_1')
    await userEvent.type(screen.getByLabelText(/Check in/), '2026-08-20')
    await userEvent.type(screen.getByLabelText(/Check out/), '2026-08-25')
    await userEvent.type(screen.getByLabelText(/Guest first name/), 'Ana')
    await userEvent.click(screen.getByLabelText(/This stay has already started/))
    await userEvent.type(screen.getByLabelText('Booked on'), '2026-07-02')

    await userEvent.click(screen.getByRole('button', { name: 'Create booking' }))

    const [sent] = server.callsTo('POST', 'reservations')

    expect(sent?.body).toMatchObject({
      listing_id: 'lst_1',
      check_in: '2026-08-20',
      check_out: '2026-08-25',
      records_existing_stay: true,
      booked_at: '2026-07-02',
    })
  })

  it('does not claim a stay has started when nobody said so', async () => {
    const server = renderReservations()
    server.on('POST reservations', { status: 201, body: { data: { id: 'res_1' } } })

    await userEvent.click(await screen.findByRole('button', { name: /New booking/ }))
    await userEvent.selectOptions(screen.getByLabelText(/Listing/), 'lst_1')
    await userEvent.type(screen.getByLabelText(/Check in/), '2026-12-20')
    await userEvent.type(screen.getByLabelText(/Check out/), '2026-12-23')
    await userEvent.type(screen.getByLabelText(/Guest first name/), 'Ana')
    await userEvent.click(screen.getByRole('button', { name: 'Create booking' }))

    expect(server.callsTo('POST', 'reservations')[0]?.body).toMatchObject({
      records_existing_stay: false,
    })
  })

  it('records a cancelled booking with the channel’s own code', async () => {
    const server = renderReservations()
    server.on('POST reservations', { status: 201, body: { data: { id: 'res_1' } } })

    await userEvent.click(await screen.findByRole('button', { name: /New booking/ }))
    await userEvent.selectOptions(screen.getByLabelText(/Listing/), 'lst_1')
    await userEvent.type(screen.getByLabelText(/Check in/), '2026-12-20')
    await userEvent.type(screen.getByLabelText(/Check out/), '2026-12-23')
    await userEvent.type(screen.getByLabelText(/Guest first name/), 'Ana')
    // Scoped: the page's own status filter carries the same label.
    const dialog = screen.getByRole('dialog', { name: 'New booking' })
    await userEvent.selectOptions(within(dialog).getByLabelText('Status'), 'cancelled')
    await userEvent.type(within(dialog).getByLabelText(/confirmation code/), 'HMX5F8DWAE')

    await userEvent.click(screen.getByRole('button', { name: 'Create booking' }))

    // Both were missing: a cancelled booking holds no nights, so it is how a
    // cancelled stay and the one that replaced it both reach the record, and the
    // code is what a payout query is settled with.
    expect(server.callsTo('POST', 'reservations')[0]?.body).toMatchObject({
      status: 'cancelled',
      external_confirmation_code: 'HMX5F8DWAE',
    })
  })

  it('keeps the guest’s words apart from the internal notes', async () => {
    const server = renderReservations()
    server.on('POST reservations', { status: 201, body: { data: { id: 'res_1' } } })

    await userEvent.click(await screen.findByRole('button', { name: /New booking/ }))
    await userEvent.selectOptions(screen.getByLabelText(/Listing/), 'lst_1')
    await userEvent.type(screen.getByLabelText(/Check in/), '2026-12-20')
    await userEvent.type(screen.getByLabelText(/Check out/), '2026-12-23')
    await userEvent.type(screen.getByLabelText(/Guest first name/), 'Ana')
    await userEvent.type(screen.getByLabelText(/What the guest said/), 'Arriving late')
    await userEvent.type(screen.getByLabelText('Internal notes'), 'Airbnb paid 420.00')

    await userEvent.click(screen.getByRole('button', { name: 'Create booking' }))

    expect(server.callsTo('POST', 'reservations')[0]?.body).toMatchObject({
      guest_notes: 'Arriving late',
      internal_notes: 'Airbnb paid 420.00',
    })
  })

  it('puts the engine’s refusal in front of the person', async () => {
    const server = renderReservations()
    server.on('POST reservations', {
      status: 409,
      body: {
        message:
          'That arrival date has passed. Tick "this stay has already started" to record a booking that is under way or finished.',
        errors: {},
      },
    })

    await userEvent.click(await screen.findByRole('button', { name: /New booking/ }))
    await userEvent.selectOptions(screen.getByLabelText(/Listing/), 'lst_1')
    await userEvent.type(screen.getByLabelText(/Check in/), '2026-08-20')
    await userEvent.type(screen.getByLabelText(/Check out/), '2026-08-25')
    await userEvent.type(screen.getByLabelText(/Guest first name/), 'Ana')
    await userEvent.click(screen.getByRole('button', { name: 'Create booking' }))

    // The refusal names the tickbox, so the message and the control it refers to
    // are on the same screen — which is also why this has to be scoped to the
    // alert: "already started" now appears twice, in the error and on the label.
    expect(await screen.findByRole('alert')).toHaveTextContent(/already started/)
  })
})


describe('a booking imported from Hostex', () => {
  it('shows the Airbnb reference and actual guest without claiming an unknown balance is paid', async () => {
    renderReservations(['*'], [{
      id: 'res-source', confirmation_code: 'HB000001', display_reference: 'HMTEST1234', reference_label: 'Airbnb confirmation',
      source: 'hostex', guest: { display_name: 'Example Visitor' }, status_label: 'Confirmed', status_colour: 'green',
      stay: { check_in_date: '2026-11-01', check_out_date: '2026-11-04', nights: 3 }, guests: { total: 2 },
      financials: { grand_total: { amount: 70525, currency: 'CAD', formatted: '705.25' }, balance_due: null },
    }])
    expect(await screen.findByText('HMTEST1234')).toBeInTheDocument()
    expect(screen.getByText('Airbnb confirmation')).toBeInTheDocument()
    expect(screen.getByText('Example Visitor')).toBeInTheDocument()
    expect(screen.queryByText('HB000001')).not.toBeInTheDocument()
    expect(screen.queryByText('Paid', { exact: true })).not.toBeInTheDocument()
    expect(screen.getByText('Unavailable')).toBeInTheDocument()
  })
})

describe('a request to book from a booking website', () => {
  function requested(overrides: Record<string, unknown> = {}) {
    return {
      id: 'res-request', confirmation_code: 'HB000009', source: 'direct',
      guest: { display_name: 'Ana Costa' }, status: 'tentative', status_label: 'Tentative', status_colour: 'amber',
      stay: { check_in_date: '2026-12-01', check_out_date: '2026-12-03', nights: 2 }, guests: { total: 2 },
      financials: { grand_total: { amount: 42000, currency: 'EUR', formatted: '420.00' }, balance_due: { amount: 42000, currency: 'EUR', formatted: '420.00' } },
      website_request: { status: 'requested', mode: 'request', answer_by: '2026-10-30T15:00:00+00:00' },
      ...overrides,
    }
  }

  it('shows the request with its deadline and accepts it', async () => {
    const server = renderReservations(['*'], [requested()])
    server.on('POST reservations/res-request/website-request/accept', { body: { data: { id: 'res-request' } } })

    expect(await screen.findByText(/Website request/)).toBeInTheDocument()
    expect(screen.getByText(/Answer by/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Accept request from Ana Costa' }))

    expect(server.callsTo('POST', 'reservations/res-request/website-request/accept')).toHaveLength(1)
  })

  it('asks before declining, and sends the reason', async () => {
    const server = renderReservations(['*'], [requested()])
    server.on('POST reservations/res-request/website-request/decline', { body: { data: { id: 'res-request' } } })

    await userEvent.click(await screen.findByRole('button', { name: 'Decline request from Ana Costa' }))
    expect(server.callsTo('POST', 'reservations/res-request/website-request/decline')).toHaveLength(0)

    await userEvent.type(screen.getByLabelText('Reason for the guest (optional)'), 'Booked for a family wedding')
    await userEvent.click(screen.getByRole('button', { name: 'Yes, decline' }))

    expect(server.callsTo('POST', 'reservations/res-request/website-request/decline')[0]?.body).toMatchObject({
      reason: 'Booked for a family wedding',
    })
  })

  it('offers no answer to someone who cannot change bookings', async () => {
    renderReservations(['reservations.view'], [requested()])

    expect(await screen.findByText(/Website request/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Accept request/ })).not.toBeInTheDocument()
  })

  it('says nothing about a request once it is answered', async () => {
    renderReservations(['*'], [requested({ website_request: { status: 'confirmed', mode: 'request', answer_by: null } })])

    expect(await screen.findByText('Ana Costa')).toBeInTheDocument()
    expect(screen.queryByText(/Website request/)).not.toBeInTheDocument()
  })
})
