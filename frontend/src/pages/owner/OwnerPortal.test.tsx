import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import { App } from '@/App'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * What a property owner sees when they sign in.
 *
 * Two things are being protected here.
 *
 * **They land on their own screens.** The server has always recorded which
 * portal a person belongs to and returned it at sign-in; nothing read it, so an
 * owner would have landed on the management dashboard — every tile about
 * properties they do not manage, most of them refusing.
 *
 * **Earnings are labelled as the property's, never theirs.** The portal's
 * revenue figures are gross: the management fee and the channel's commission
 * come off them and only the statement itemises that. An owner reading a big
 * number beside their own name will believe it is their money and be short by
 * thousands when the payout lands. This is the test that notices if somebody
 * later "tidies" that wording away.
 */
const SUMMARY = {
  owner: { id: 'own_1', display_name: 'Marta Silva', payout_currency: 'CAD' },
  period: { from: '2026-01-01', to: '2026-10-06' },
  properties: [
    {
      property_id: 'prp_1',
      property_name: 'Light Green Room',
      ownership_percentage: 100,
      nights_sold: 210,
      nights_available: 279,
      occupancy_rate: 0.7527,
      accommodation_revenue: { amount: 1240000, currency: 'CAD', formatted: '12400.00' },
      adr: { amount: 5900, currency: 'CAD', formatted: '59.00' },
    },
  ],
  totals: {
    nights_sold: 210,
    occupancy_rate: 0.7527,
    accommodation_revenue: { amount: 1240000, currency: 'CAD', formatted: '12400.00' },
    adr: { amount: 5900, currency: 'CAD', formatted: '59.00' },
  },
  statements: [],
  payouts: [],
  balance: {
    currency: 'CAD',
    as_at: '2026-09-30',
    closing_balance: { amount: 930000, currency: 'CAD', formatted: '9300.00' },
    awaiting_payout: { amount: 0, currency: 'CAD', formatted: '0.00' },
    is_in_deficit: false,
  },
}

function ownerSession() {
  const base = session({ permissions: ['properties.view', 'calendar.view'] })

  return session({
    permissions: ['properties.view', 'calendar.view'],
    membership: { ...base.membership!, default_portal: 'owner', restricted_to_properties: true },
  })
}

function renderOwner(routes: Record<string, unknown> = {}, route = '/') {
  const server = stubApi({
    'GET auth/me': { body: ownerSession() },
    'GET portal/owner/summary': { body: { data: SUMMARY } },
    'GET portal/owner/statements': { body: { data: [] } },
    'GET portal/owner/payouts': { body: { data: [] } },
    'GET portal/owner/upcoming': { body: { data: [], meta: {} } },
    'GET calendar': { body: { data: [] } },
    ...routes,
  })

  renderWithProviders(<App />, { route })

  return server
}

describe('where an owner lands', () => {
  it('shows the owner portal, not the management interface', async () => {
    renderOwner()

    expect(await screen.findByRole('heading', { name: 'Overview' })).toBeInTheDocument()

    // Their own four screens.
    expect(screen.getByRole('link', { name: /Calendar/ })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Money/ })).toBeInTheDocument()

    /*
     * And none of the management navigation. An owner has no properties to
     * create, no channel to connect and no agent to configure; a sidebar full
     * of controls that all refuse is worse than one that offers only what works.
     */
    expect(screen.queryByRole('link', { name: /Channels/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Agents/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Operations/ })).not.toBeInTheDocument()
  })

  it('leaves staff on the management interface', async () => {
    stubApi({
      'GET auth/me': { body: session({ permissions: ['*'] }) },
      'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
      'GET dashboard/summary': { body: { data: {} } },
    })

    renderWithProviders(<App />)

    // The portal branch must key on the membership's portal and nothing else.
    expect(await screen.findByRole('link', { name: /Properties/ })).toBeInTheDocument()
  })
})

describe('what the overview says about money', () => {
  it('calls earnings the property’s, not the owner’s', async () => {
    renderOwner()

    expect(await screen.findByText('The properties earned')).toBeInTheDocument()
    expect(screen.getByText(/come off this/)).toBeInTheDocument()

    // The table column says it too — somebody reading only the rows still sees
    // whose money it is.
    expect(screen.getByRole('columnheader', { name: 'It earned' })).toBeInTheDocument()
  })

  it('takes what they are paid from the statement rather than working it out', async () => {
    renderOwner()

    const balance = await screen.findByText('Balance')
    const block = balance.closest('.stack') as HTMLElement

    expect(within(block).getByText('9300.00')).toBeInTheDocument()
    // Sourced, so a figure that disagrees with the statement is traceable
    // rather than mysterious.
    expect(within(block).getByText(/From your statement to 2026-09-30/)).toBeInTheDocument()
  })

  it('explains a deficit instead of showing a negative payout', async () => {
    renderOwner({
      'GET portal/owner/summary': {
        body: {
          data: {
            ...SUMMARY,
            balance: { ...SUMMARY.balance, is_in_deficit: true },
          },
        },
      },
    })

    expect(await screen.findByText('Carried forward against you')).toBeInTheDocument()
    expect(screen.getByText(/carried forward against your next statement/i)).toBeInTheDocument()
  })
})

describe('what the owner is not shown', () => {
  it('says why there are no guest names rather than leaving a gap', async () => {
    renderOwner({
      'GET portal/owner/upcoming': {
        body: {
          data: [
            {
              id: 'res_1',
              property_id: 'prp_1',
              check_in_date: '2026-10-10',
              check_out_date: '2026-10-14',
              nights: 4,
              guests: 2,
              source: 'airbnb',
              status: 'confirmed',
              accommodation_total: { amount: 24000, currency: 'CAD', formatted: '240.00' },
            },
          ],
          meta: {},
        },
      },
    }, '/stays')

    // An absence nobody explains reads as a bug, and somebody eventually
    // "fixes" it by adding the names back.
    expect(
      await screen.findByText(/Guest names and contact details are not shown/),
    ).toBeInTheDocument()

    // The stay is there, with a count and no name.
    expect(screen.getByText('2026-10-10')).toBeInTheDocument()
  })
})
