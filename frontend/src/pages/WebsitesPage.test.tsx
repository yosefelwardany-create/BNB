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

const OPTIONS = [
  { listing_id: 'lst_1', title: 'Sea View Flat', organization_id: 'org_2', organization_name: 'Harbour Homes', city: 'Lisbon' },
  { listing_id: 'lst_2', title: 'Old Barn', organization_id: 'org_3', organization_name: 'Hill Cottages', city: 'Sintra' },
]

function renderWebsites(sites = [site()]) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions: [], is_platform_admin: true }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    'GET platform/websites': { body: { data: sites } },
    'GET platform/website-listing-options': { body: { data: OPTIONS } },
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

  it('chooses, orders and features properties', async () => {
    const user = userEvent.setup()
    const server = renderWebsites()
    server.on('PUT platform/websites/web_1/listings', { body: { data: site() } })

    await user.click(await screen.findByRole('button', { name: 'Edit Stay With Us' }))

    const panel = await screen.findByRole('region', { name: 'Properties on this site' })
    await user.click(within(panel).getByRole('checkbox', { name: 'Show Sea View Flat' }))
    await user.click(within(panel).getByRole('checkbox', { name: 'Show Old Barn' }))
    await user.click(within(panel).getByRole('button', { name: 'Move Old Barn up' }))
    await user.click(within(panel).getByRole('checkbox', { name: 'Feature Old Barn' }))
    await user.click(within(panel).getByRole('button', { name: 'Save properties' }))

    const [call] = server.callsTo('PUT', 'platform/websites/web_1/listings')
    expect(call?.body).toEqual({ listings: [
      { listing_id: 'lst_2', is_featured: true },
      { listing_id: 'lst_1', is_featured: false },
    ] })
  })

  it('publishes, and says why it cannot', async () => {
    const user = userEvent.setup()
    const server = renderWebsites()
    let attempts = 0
    server.on('POST platform/websites/web_1/publish', () => {
      attempts += 1

      return attempts === 1
        ? { status: 422, body: { message: 'Add at least one property before publishing.' } }
        : { body: { data: site({ status: 'live' }) } }
    })

    await user.click(await screen.findByRole('button', { name: 'Edit Stay With Us' }))
    await user.click(screen.getByRole('button', { name: 'Publish' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Add at least one property before publishing.')

    await user.click(screen.getByRole('button', { name: 'Publish' }))
    expect(server.callsTo('POST', 'platform/websites/web_1/publish')).toHaveLength(2)
  })
})
