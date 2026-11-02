import { describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { Website } from '@/api/types'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'
import { SiteDetailsPanel } from './SiteDetailsPanel'

/**
 * Where a site's pieces may be shown, and the tags that place them: the
 * platform's to set, in the site's details. A client never sees it.
 */
const SCRIPT = '<script src="https://habitat.test/sites/embed.js" defer></script>'

function site(overrides: Partial<Website> = {}): Website {
  return {
    id: 'web_1',
    slug: 'harbour',
    name: 'Harbour stays',
    kind: 'client',
    organization_id: 'org_2',
    organization_name: 'Harbour Homes',
    status: 'live',
    settings: { contact_email: null, contact_phone: null, address_line: null, instagram_url: null, facebook_url: null, logo_url: null, hero_url: null },
    hold_minutes: 30,
    request_hours: 24,
    listings: [],
    public_url: 'https://habitat.test/sites/harbour',
    published_at: null,
    created_at: '2026-10-29T09:00:00+00:00',
    embed_origins: [],
    embed: {
      script: SCRIPT,
      search: '<div data-habitat-site="harbour" data-habitat-piece="search"></div>',
      homes: '<div data-habitat-site="harbour" data-habitat-piece="homes"></div>',
      book: [{ listing_id: 'lst_1', title: 'Harbour Loft', tag: '<div data-habitat-site="harbour" data-habitat-piece="book" data-habitat-home="harbour-loft"></div>' }],
    },
    ...overrides,
  }
}

function renderDetails(website: Website, who: 'platform' | 'client' = 'platform') {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions: [], is_platform_admin: who === 'platform' }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
  })

  renderWithProviders(
    <SiteDetailsPanel
      website={website}
      who={who}
      sitePath={who === 'client' ? 'portal/owner/websites/web_1' : undefined}
      onSaved={() => {}}
    />,
  )

  return server
}

describe('a site on other websites', () => {
  it('saves the store addresses one per line', async () => {
    const user = userEvent.setup()
    const server = renderDetails(site())
    server.on('PATCH platform/websites/web_1', { body: { data: site({ embed_origins: ['https://shop.example.com', 'https://www.example.com'] }) } })

    const section = screen.getByRole('region', { name: 'On other websites' })
    await user.type(within(section).getByLabelText('Store addresses (one per line)'), ' https://shop.example.com \n\nhttps://www.example.com')
    await user.click(within(section).getByRole('button', { name: 'Save addresses' }))

    await waitFor(() => expect(server.callsTo('PATCH', 'platform/websites/web_1')).toHaveLength(1))
    expect(server.callsTo('PATCH', 'platform/websites/web_1')[0]!.body).toEqual({
      embed_origins: ['https://shop.example.com', 'https://www.example.com'],
    })
    expect(await within(section).findByText('Saved.')).toBeInTheDocument()
  })

  it('shows why a store address was refused', async () => {
    const user = userEvent.setup()
    const server = renderDetails(site())
    server.on('PATCH platform/websites/web_1', {
      status: 422,
      body: { message: 'Invalid', errors: { 'embed_origins.1': ['Use an address like https://www.example.com, with nothing after it.'] } },
    })

    const section = screen.getByRole('region', { name: 'On other websites' })
    await user.type(within(section).getByLabelText('Store addresses (one per line)'), 'https://shop.example.com\nshop.example.com/pages')
    await user.click(within(section).getByRole('button', { name: 'Save addresses' }))

    expect(await within(section).findByText(/shop\.example\.com\/pages: Use an address like https:\/\/www\.example\.com, with nothing after it\./)).toBeInTheDocument()
  })

  it('copies a snippet', async () => {
    const user = userEvent.setup()
    renderDetails(site())
    const writeText = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined)

    const section = screen.getByRole('region', { name: 'On other websites' })
    expect(within(section).getByText(SCRIPT)).toBeInTheDocument()
    expect(within(section).getByText('Booking box: Harbour Loft')).toBeInTheDocument()

    await user.click(within(section).getByRole('button', { name: 'Copy the script' }))

    expect(writeText).toHaveBeenCalledWith(SCRIPT)
    expect(await within(section).findByRole('button', { name: 'Copied the script' })).toBeInTheDocument()
  })

  it('a client sees no store addresses or snippets', () => {
    renderDetails(site(), 'client')

    expect(screen.queryByRole('region', { name: 'On other websites' })).not.toBeInTheDocument()
    expect(screen.queryByText(SCRIPT)).not.toBeInTheDocument()
  })
})
