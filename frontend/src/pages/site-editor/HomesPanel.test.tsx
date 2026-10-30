import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { Website } from '@/api/types'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'
import { HomesPanel } from './HomesPanel'

/**
 * Which homes a site shows: chosen, ordered and featured, and the ones still
 * to be published, with what they need.
 */
function site(overrides: Partial<Website> = {}): Website {
  return {
    id: 'web_1',
    slug: 'stay-with-us',
    name: 'Stay With Us',
    kind: 'brand',
    organization_id: null,
    organization_name: null,
    status: 'draft',
    settings: { contact_email: null, contact_phone: null, address_line: null, instagram_url: null, facebook_url: null, logo_url: null, hero_url: null },
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

function renderClientHomes(options: unknown[]) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions: [] }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    'GET portal/owner/websites/web_1/listing-options': { body: { data: options } },
  })

  renderWithProviders(<HomesPanel website={site({ kind: 'client', organization_id: 'org_2', organization_name: 'Harbour Homes' })} sitePath="portal/owner/websites/web_1" who="client" onSaved={() => {}} />)

  return server
}

function renderHomes(sites: Website[] = [site()], options: unknown[] = OPTIONS) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions: [], is_platform_admin: true }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    'GET platform/website-listing-options': { body: { data: options } },
  })

  renderWithProviders(<HomesPanel website={sites[0]!} onSaved={() => {}} />)

  return server
}

describe('the homes on a site', () => {
  it('chooses, orders and features properties', async () => {
    const user = userEvent.setup()
    const server = renderHomes()
    server.on('PUT platform/websites/web_1/listings', { body: { data: site() } })

    const panel = await screen.findByRole('region', { name: 'Properties on this site' })
    await user.click(await within(panel).findByRole('checkbox', { name: 'Show Sea View Flat' }))
    await user.click(within(panel).getByRole('checkbox', { name: 'Show Old Barn' }))
    await user.click(within(panel).getByRole('button', { name: 'Move Old Barn up' }))
    await user.click(within(panel).getByRole('checkbox', { name: 'Feature Old Barn' }))
    await user.click(within(panel).getByRole('button', { name: 'Save homes' }))

    const [call] = server.callsTo('PUT', 'platform/websites/web_1/listings')
    expect(call?.body).toEqual({ listings: [
      { listing_id: 'lst_2', is_featured: true },
      { listing_id: 'lst_1', is_featured: false },
    ] })
  })

  it('drops a property that is no longer published instead of refusing every save', async () => {
    const user = userEvent.setup()
    const server = renderHomes([site({ listings: [
      { listing_id: 'lst_1', title: 'Sea View Flat', organization_id: 'org_2', organization_name: 'Harbour Homes', is_featured: false },
      { listing_id: 'lst_gone', title: 'Paused Cabin', organization_id: 'org_2', organization_name: 'Harbour Homes', is_featured: true },
    ] })])
    server.on('PUT platform/websites/web_1/listings', { body: { data: site() } })

    const panel = await screen.findByRole('region', { name: 'Properties on this site' })

    expect(await within(panel).findByText(/1 property is no longer published/)).toBeInTheDocument()
    await user.click(within(panel).getByRole('button', { name: 'Save homes' }))

    const [call] = server.callsTo('PUT', 'platform/websites/web_1/listings')
    expect(call?.body).toEqual({ listings: [{ listing_id: 'lst_1', is_featured: false }] })
  })

  it('shows a home still to be published, and publishes it from here', async () => {
    const user = userEvent.setup()
    const draft = option({ listing_id: 'lst_3', title: 'Garden Studio', ready: false, needs: ['Not published yet.'], can_publish: true })
    const server = renderHomes([site()], [...OPTIONS, draft])
    server.on('POST platform/website-listing-options/lst_3/publish', { body: { data: { ...draft, ready: true, needs: [], can_publish: false } } })

    const panel = await screen.findByRole('region', { name: 'Properties on this site' })

    expect(await within(panel).findByText('Garden Studio')).toBeInTheDocument()
    expect(within(panel).getByText('Not published yet.')).toBeInTheDocument()
    expect(within(panel).queryByRole('checkbox', { name: 'Show Garden Studio' })).not.toBeInTheDocument()

    await user.click(within(panel).getByRole('button', { name: 'Publish Garden Studio' }))

    expect(server.callsTo('POST', 'platform/website-listing-options/lst_3/publish')).toHaveLength(1)
  })

  it('activates the property too when that is all it lacks, and says so on the button', async () => {
    renderHomes([site()], [option({ listing_id: 'lst_3', title: 'Garden Studio', ready: false, needs: ['Not published yet.'], can_publish: true, property_active: false })])

    const panel = await screen.findByRole('region', { name: 'Properties on this site' })

    expect(await within(panel).findByRole('button', { name: 'Publish Garden Studio' })).toHaveTextContent('Activate and publish')
  })

  it('says what a home still needs when it cannot be published from here', async () => {
    renderHomes([site()], [option({
      listing_id: 'lst_4', title: 'Old Mill', ready: false, can_publish: false,
      needs: ['Not published yet.', 'At least one photo is required.'],
    })])

    const panel = await screen.findByRole('region', { name: 'Properties on this site' })

    expect(await within(panel).findByText(/At least one photo is required\./)).toBeInTheDocument()
    expect(within(panel).getByText(/Finish it on the Properties screen/)).toBeInTheDocument()
    expect(within(panel).queryByRole('button', { name: 'Publish Old Mill' })).not.toBeInTheDocument()
    expect(within(panel).queryByRole('checkbox', { name: 'Show Old Mill' })).not.toBeInTheDocument()
  })

  it('says to add a property when the account has none', async () => {
    renderHomes([site()], [])

    const panel = await screen.findByRole('region', { name: 'Properties on this site' })

    expect(await within(panel).findByText('No properties yet')).toBeInTheDocument()
  })

  it('a client sees what a home needs, and no Publish', async () => {
    const user = userEvent.setup()
    const server = renderClientHomes([
      option(),
      option({ listing_id: 'lst_3', title: 'Attic Room', ready: false, needs: ['Not published yet.'], can_publish: false }),
    ])
    server.on('PUT portal/owner/websites/web_1/listings', { body: { data: site({ kind: 'client' }) } })

    const panel = await screen.findByRole('region', { name: 'Properties on this site' })
    expect(await within(panel).findByText(/Not published yet\./)).toBeInTheDocument()
    expect(within(panel).getByText(/The management company puts it on sale/)).toBeInTheDocument()
    expect(within(panel).queryByRole('button', { name: /Publish/ })).not.toBeInTheDocument()

    await user.click(within(panel).getByRole('checkbox', { name: 'Show Sea View Flat' }))
    await user.click(within(panel).getByRole('button', { name: 'Save homes' }))

    expect(server.callsTo('PUT', 'portal/owner/websites/web_1/listings')).toHaveLength(1)
  })
})
