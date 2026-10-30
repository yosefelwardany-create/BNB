import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { WebsitesPage } from '@/pages/WebsitesPage'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { page, stubApi } from '@/test/server'

/**
 * Booking websites, as the platform owner runs them: the brand site across
 * every client account and any client's own site.
 */
function site(overrides: Record<string, unknown> = {}) {
  return {
    id: 'web_1',
    slug: 'stay-with-us',
    name: 'Stay With Us',
    kind: 'brand',
    organization_id: null,
    organization_name: null,
    status: 'draft',
    settings: {
      heading: null, tagline: null, primary_colour: null, accent_colour: null,
      contact_email: null, contact_phone: null, address_line: null, instagram_url: null, facebook_url: null,
      logo_url: null, hero_url: null,
    },
    hold_minutes: 30,
    request_hours: 24,
    listings: [],
    public_url: 'https://habitat.test/sites/stay-with-us',
    published_at: null,
    created_at: '2026-10-29T09:00:00+00:00',
    ...overrides,
  }
}

function option(overrides: Record<string, unknown> = {}) {
  return {
    listing_id: 'lst_1', title: 'Sea View Flat', organization_id: 'org_2', organization_name: 'Harbour Homes', city: 'Lisbon',
    ready: true, needs: [], can_publish: false, property_active: true,
    ...overrides,
  }
}

const OPTIONS = [
  option(),
  option({ listing_id: 'lst_2', title: 'Old Barn', organization_id: 'org_3', organization_name: 'Hill Cottages', city: 'Sintra' }),
]

function renderWebsites(sites = [site()], options: unknown[] = OPTIONS) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions: [], is_platform_admin: true }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    'GET platform/websites': { body: { data: sites } },
    'GET platform/website-listing-options': { body: { data: options } },
    'GET platform/organizations': {
      body: page([{ id: 'org_2', name: 'Harbour Homes' }, { id: 'org_3', name: 'Hill Cottages' }]),
    },
  })

  renderWithProviders(<WebsitesPage />)

  return server
}

describe('booking websites', () => {
  it('lists sites with their status and address', async () => {
    renderWebsites([
      site(),
      site({ id: 'web_2', name: 'Harbour Homes', slug: 'harbour', kind: 'client', organization_id: 'org_2', organization_name: 'Harbour Homes', status: 'live', public_url: 'https://habitat.test/sites/harbour' }),
    ])

    const brand = (await screen.findByText('Stay With Us')).closest('tr') as HTMLElement
    expect(within(brand).getByText('Draft')).toBeInTheDocument()
    expect(within(brand).getByText('Brand site')).toBeInTheDocument()

    const client = screen.getAllByText('Harbour Homes')[0]?.closest('tr') as HTMLElement
    expect(within(client).getByText('Live')).toBeInTheDocument()
    expect(within(client).getByRole('link', { name: /sites\/harbour/ })).toHaveAttribute('href', 'https://habitat.test/sites/harbour')
  })

  it('creates a client site', async () => {
    const user = userEvent.setup()
    const server = renderWebsites([])
    server.on('POST platform/websites', { status: 201, body: { data: site({ id: 'web_9', kind: 'client', organization_id: 'org_2', name: 'Harbour Homes', slug: 'harbour' }) } })

    await user.click(await screen.findByRole('button', { name: 'New website' }))
    await user.selectOptions(screen.getByLabelText(/Whose site/), 'client')
    await user.selectOptions(await screen.findByLabelText('Client'), 'org_2')
    await user.type(screen.getByLabelText(/^Name/), 'Harbour Homes')
    await user.type(screen.getByLabelText(/^Address/), 'harbour')
    await user.click(screen.getByRole('button', { name: 'Create' }))

    const [call] = server.callsTo('POST', 'platform/websites')
    expect(call?.body).toEqual({ kind: 'client', organization_id: 'org_2', name: 'Harbour Homes', slug: 'harbour' })
  })

  it('opens the page builder from the list', async () => {
    renderWebsites()

    expect(await screen.findByRole('link', { name: 'Edit Stay With Us in the page builder' })).toHaveAttribute('href', '/websites/web_1/edit')
  })

  it('goes live, and says why it cannot', async () => {
    const user = userEvent.setup()
    const server = renderWebsites()
    let attempts = 0
    server.on('POST platform/websites/web_1/publish', () => {
      attempts += 1

      return attempts === 1
        ? { status: 422, body: { message: 'Add at least one property before publishing.' } }
        : { body: { data: site({ status: 'live' }) } }
    })

    await user.click(await screen.findByRole('button', { name: 'Settings for Stay With Us' }))
    await user.click(screen.getByRole('button', { name: 'Go live' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Add at least one property before publishing.')

    await user.click(screen.getByRole('button', { name: 'Go live' }))
    expect(server.callsTo('POST', 'platform/websites/web_1/publish')).toHaveLength(2)
  })

  it('shows what the site still needs before it takes bookings online', async () => {
    const user = userEvent.setup()
    renderWebsites([site({ readiness: [
      { key: 'properties', ok: true, message: '2 properties are shown.', blocks_publishing: true },
      { key: 'payments', ok: false, message: 'Guests are asked to get in touch until Stripe is set up. Missing: STRIPE_SECRET_KEY.', blocks_publishing: false },
      { key: 'email', ok: false, message: 'Guest and host emails are only written to the log (MAIL_MAILER=log). Set up an email provider.', blocks_publishing: false },
      { key: 'images', ok: true, message: 'Uploaded logos and photos are kept in R2.', blocks_publishing: false },
    ] })])

    await user.click(await screen.findByRole('button', { name: 'Settings for Stay With Us' }))

    const list = await screen.findByRole('list', { name: 'Ready to take bookings' })
    expect(within(list).getAllByRole('listitem')).toHaveLength(4)
    expect(within(list).getByText(/Missing: STRIPE_SECRET_KEY/)).toBeInTheDocument()
    expect(within(list).getAllByText('Needs attention')).toHaveLength(2)
    expect(within(list).getAllByText('Ready')).toHaveLength(2)
  })
})
