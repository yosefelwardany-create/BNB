import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import { App } from '@/App'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * What a client sees when they sign in.
 *
 * Three things are being protected here.
 *
 * **They land on their own screens**, four of them, and none of the
 * management navigation. There is no Stays screen: who is staying is the
 * management company's business with the guest.
 *
 * **Money is labelled.** What the properties earned, the management
 * commission, and the revenue after it — in that order, per currency, with
 * the server's own sentence saying that the last one is a revenue figure and
 * not a payment. Anything uncertain is shown and marked as not final rather
 * than hidden.
 *
 * **No guest identity reaches the screen.** The calendar shows sold nights,
 * and says why there are no names.
 */
const FINANCIALS = {
  period: { from: '2026-01-01', to: '2026-10-06' },
  commission: { rate: 10, basis: 'accommodation revenue per stay night', effective_from: '2026-01-01' },
  properties: [
    {
      property_id: 'prp_1',
      property_name: 'Light Green Room',
      property_status: 'active',
      currency: 'CAD',
      ownership_percentage: 100,
      nights_sold: 10,
      nights_available: 31,
      reservations_count: 3,
      revenue_before_commission: { amount: 100000, currency: 'CAD', formatted: '1000.00' },
      commission_rate: 10,
      commission: { amount: 10000, currency: 'CAD', formatted: '100.00' },
      revenue_after_commission: { amount: 90000, currency: 'CAD', formatted: '900.00' },
      commission_explanation: '10% of 1000.00 CAD',
      flags: [],
      is_final: true,
    },
  ],
  totals_by_currency: [
    {
      currency: 'CAD',
      properties: 1,
      nights_sold: 10,
      revenue_before_commission: { amount: 100000, currency: 'CAD', formatted: '1000.00' },
      commission: { amount: 10000, currency: 'CAD', formatted: '100.00' },
      revenue_after_commission: { amount: 90000, currency: 'CAD', formatted: '900.00' },
      is_final: true,
    },
  ],
  has_incomplete_data: false,
  explanation: {
    revenue_before_commission: 'What your properties earned: the accommodation revenue of each night in the period.',
    commission: 'The management commission of 10% of commissionable revenue, deducted by the management company.',
    revenue_after_commission:
      'Your revenue after the management commission has been deducted. This is a revenue figure, not a payment: it does not show money received or paid out.',
  },
}

const SUMMARY = {
  owner: { id: 'own_1', display_name: 'Marta Silva', payout_currency: 'CAD' },
  period: { from: '2026-01-01', to: '2026-10-06' },
  properties: [
    {
      property_id: 'prp_1',
      property_name: 'Light Green Room',
      ownership_percentage: 100,
      nights_sold: 10,
      nights_available: 31,
      occupancy_rate: 32.3,
      accommodation_revenue: { amount: 100000, currency: 'CAD', formatted: '1000.00' },
      adr: { amount: 10000, currency: 'CAD', formatted: '100.00' },
    },
  ],
  totals: {
    nights_sold: 10,
    occupancy_rate: 32.3,
    accommodation_revenue: { amount: 100000, currency: 'CAD', formatted: '1000.00' },
    adr: { amount: 10000, currency: 'CAD', formatted: '100.00' },
  },
  statements: [],
  payouts: [],
  balance: {
    currency: 'CAD',
    as_at: null,
    closing_balance: { amount: 0, currency: 'CAD', formatted: '0.00' },
    awaiting_payout: { amount: 0, currency: 'CAD', formatted: '0.00' },
    is_in_deficit: false,
  },
}

function calendarFor(from: string) {
  const month = from.slice(0, 7)

  return {
    from,
    to: `${month}-28`,
    listings: [
      {
        listing_id: 'lst_1',
        listing_name: 'Light Green Room',
        listing_status: 'published',
        property_id: 'prp_1',
        property_name: 'Light Green Room',
        timezone: 'America/Toronto',
        currency: 'CAD',
        days: [
          { date: `${month}-03`, available: false, sold_units: 1, blocked_units: 0, total_units: 1 },
          { date: `${month}-04`, available: false, sold_units: 0, blocked_units: 1, total_units: 1 },
          { date: `${month}-05`, available: true, sold_units: 0, blocked_units: 0, total_units: 1 },
        ],
      },
    ],
    reservations: [
      {
        id: 'res_1',
        property_id: 'prp_1',
        listing_id: 'lst_1',
        status: 'confirmed',
        check_in_date: `${month}-03`,
        check_out_date: `${month}-04`,
        nights: 1,
        guests: 2,
        source: 'airbnb',
      },
    ],
    blocks: [],
  }
}

const PROPERTY = {
  id: 'prp_1',
  name: 'Light Green Room',
  display_name: 'Light Green Room',
  property_type: 'apartment',
  property_type_label: 'Apartment',
  status: 'active',
  address: { line_1: '12 Rue Verte', line_2: null, city: 'Montréal', state: 'QC', postal_code: null, country_code: 'CA' },
  timezone: 'America/Toronto',
  currency: 'CAD',
  capacity: { bedrooms: 1, bathrooms: 1, beds: 1, max_occupancy: 2 },
  content: { summary: 'A quiet room', description: null, house_rules: null },
  arrival: { check_in_time: '15:00', check_out_time: '11:00' },
  pricing: {
    base_rate: { amount: 10000, currency: 'CAD', formatted: '100.00' },
    cleaning_fee: { amount: 0, currency: 'CAD', formatted: '0.00' },
    minimum_nights: 1,
  },
  listing: { channel_url: 'https://airbnb.example/rooms/1', channel_status: 'listed' },
  photos: [],
}

function clientSession() {
  const base = session()

  return session({
    permissions: [],
    membership: {
      ...base.membership!,
      default_portal: 'owner',
      restricted_to_properties: true,
      roles: [{ id: 'rol_c', slug: 'owner', name: 'Client' }],
    },
  })
}

function renderClient(routes: Record<string, unknown> = {}, route = '/') {
  const server = stubApi({
    'GET auth/me': { body: clientSession() },
    'GET portal/owner/financials': { body: { data: FINANCIALS } },
    'GET portal/owner/summary': { body: { data: SUMMARY } },
    'GET portal/owner/statements': { body: { data: [] } },
    'GET portal/owner/payouts': { body: { data: [] } },
    'GET portal/owner/properties': { body: { data: [PROPERTY] } },
    'GET portal/owner/properties/prp_1': { body: { data: PROPERTY } },
    'GET portal/owner/calendar': (call) => ({ body: calendarFor(call.query.get('from') ?? '2026-10-01') }),
    ...routes,
  })

  renderWithProviders(<App />, { route })

  return server
}

describe('where a client lands', () => {
  it('shows the client portal, not the management interface', async () => {
    renderClient()

    expect(await screen.findByRole('heading', { name: 'Overview' })).toBeInTheDocument()

    // Their own four screens.
    expect(screen.getByRole('link', { name: /Properties/ })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Calendar/ })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Money/ })).toBeInTheDocument()

    // No Stays screen, by decision.
    expect(screen.queryByRole('link', { name: /Stays/ })).not.toBeInTheDocument()

    // And none of the management navigation.
    expect(screen.queryByRole('link', { name: /Channels/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Agents/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Operations/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Accounts/ })).not.toBeInTheDocument()
  })

  it('sends a client who types a management URL back to their overview', async () => {
    renderClient({}, '/channels')

    expect(await screen.findByRole('heading', { name: 'Overview' })).toBeInTheDocument()
  })

  it('leaves staff on the management interface', async () => {
    stubApi({
      'GET auth/me': { body: session({ permissions: ['*'] }) },
    })

    renderWithProviders(<App />)

    // The portal branch must key on the membership's portal and nothing else.
    expect(await screen.findByRole('link', { name: /Properties/ })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Channels/ })).toBeInTheDocument()
  })
})

describe('what the overview says about money', () => {
  it('shows earned, commission and after-commission as 1000 / 100 / 900, labelled', async () => {
    renderClient()

    const headline = (await screen.findByRole('heading', { name: 'Your revenue in CAD' })).closest(
      'section',
    ) as HTMLElement

    const earned = within(headline).getByText('The properties earned').closest('.stack') as HTMLElement
    expect(within(earned).getByText('1000.00')).toBeInTheDocument()

    const commission = within(headline).getByText('Management commission (10%)').closest('.stack') as HTMLElement
    expect(within(commission).getByText('100.00')).toBeInTheDocument()

    const after = within(headline).getByText('Revenue after commission').closest('.stack') as HTMLElement
    expect(within(after).getByText('900.00')).toBeInTheDocument()
  })

  it('says, in the server’s words, that it is revenue and not a payment', async () => {
    renderClient()

    expect(await screen.findByText(/This is a revenue figure, not a payment/)).toBeInTheDocument()
  })

  it('shows an uncertain figure and marks it as not final rather than hiding it', async () => {
    renderClient({
      'GET portal/owner/financials': {
        body: {
          data: {
            ...FINANCIALS,
            has_incomplete_data: true,
            properties: [
              {
                ...FINANCIALS.properties[0],
                is_final: false,
                flags: [
                  { code: 'unresolved_refunds', count: 1, message: 'One stay has a refund that is still being resolved.' },
                ],
              },
            ],
            totals_by_currency: [{ ...FINANCIALS.totals_by_currency[0], is_final: false }],
          },
        },
      },
    })

    expect(await screen.findByRole('status')).toHaveTextContent(/not final/i)
    // The figure is still there — flagged, not zeroed.
    expect(screen.getAllByText('900.00').length).toBeGreaterThan(0)
  })

  it('does not multiply an occupancy percentage by a hundred again', async () => {
    renderClient()

    const trading = (await screen.findByRole('heading', { name: 'Trading' })).closest('section') as HTMLElement

    expect(within(trading).getByText('32%')).toBeInTheDocument()
    expect(within(trading).queryByText('3230%')).not.toBeInTheDocument()
  })
})

describe('the calendar', () => {
  it('draws sold and closed nights from the portal calendar, with no guest names', async () => {
    renderClient({}, '/calendar')

    await screen.findByRole('heading', { name: 'Calendar' })

    expect((await screen.findAllByTitle(/· Sold$/)).length).toBe(1)
    expect(screen.getAllByTitle(/· Closed$/).length).toBe(1)

    // Said, not left as a gap.
    expect(screen.getByText(/Guest names and contact details are not shown/)).toBeInTheDocument()
    expect(document.body.textContent).not.toMatch(/guest_name|@/)
  })
})

describe('the properties screen', () => {
  it('lists the property with its details and offers nothing to edit', async () => {
    renderClient({}, '/properties')

    expect(await screen.findByRole('heading', { name: 'Properties' })).toBeInTheDocument()
    expect(await screen.findByText('Light Green Room')).toBeInTheDocument()

    expect(screen.queryByRole('button', { name: /New property|Edit|Delete|Connect/ })).not.toBeInTheDocument()
  })

  it('shows one property’s details without anything operational', async () => {
    renderClient({}, '/properties/prp_1')

    expect(await screen.findByRole('heading', { name: 'Light Green Room' })).toBeInTheDocument()
    expect(screen.getByText('Apartment')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /View on the channel/ })).toBeInTheDocument()

    expect(document.body.textContent).not.toMatch(/Hostex|agent|access code|Wi-Fi|token/i)
  })
})

describe('the money screen', () => {
  it('renders a payout destination as words and offers the statement PDF', async () => {
    renderClient(
      {
        'GET portal/owner/statements': {
          body: {
            data: [
              {
                id: 'stm_1',
                reference: 'ST-0001',
                period_start: '2026-09-01',
                period_end: '2026-09-30',
                currency: 'CAD',
                net_due: { amount: 90000, currency: 'CAD', formatted: '900.00' },
                payout_amount: { amount: 90000, currency: 'CAD', formatted: '900.00' },
                closing_balance: { amount: 0, currency: 'CAD', formatted: '0.00' },
                status: 'paid',
                sent_at: '2026-10-01T09:00:00+00:00',
                paid_at: '2026-10-03T09:00:00+00:00',
              },
            ],
          },
        },
        'GET portal/owner/payouts': {
          body: {
            data: [
              {
                id: 'pay_1',
                reference: 'PO-0001',
                amount: { amount: 90000, currency: 'CAD', formatted: '900.00' },
                status: 'paid',
                method: 'bank_transfer',
                scheduled_for: null,
                paid_at: '2026-10-03T09:00:00+00:00',
                destination: { bank: 'Desjardins', account: '•••• 4321' },
              },
            ],
          },
        },
      },
      '/money',
    )

    expect(await screen.findByText('Desjardins · •••• 4321')).toBeInTheDocument()
    expect(document.body.textContent).not.toContain('[object Object]')

    expect(screen.getByRole('button', { name: /PDF/ })).toBeInTheDocument()
  })
})
