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

describe("a client's Shopify store", () => {
  const shopify = (overrides: Partial<NonNullable<Website['shopify']>> = {}): NonNullable<Website['shopify']> => ({
    configured: true,
    status: null,
    shop_domain: null,
    shop_name: null,
    currency: null,
    currency_matches: false,
    checkout_available: false,
    last_error: null,
    ...overrides,
  })

  it('says what is missing before a store can be connected', () => {
    renderDetails(site({ shopify: shopify({ configured: false }) }))

    const section = screen.getByRole('region', { name: 'Shopify checkout' })
    expect(within(section).getByText(/SHOPIFY_CLIENT_ID and SHOPIFY_CLIENT_SECRET/)).toBeInTheDocument()
    expect(within(section).queryByRole('button', { name: 'Connect Shopify' })).not.toBeInTheDocument()
  })

  it('opens the install link for the store, or copies it', async () => {
    const user = userEvent.setup()
    const server = renderDetails(site({ shopify: shopify() }))
    server.on('POST platform/websites/web_1/shopify/install-link', { body: { url: 'https://harbour-homes.myshopify.com/admin/oauth/authorize?x=1' } })
    // Opened at the click, as browsers that block a tab opened later require,
    // then sent to Shopify once the link is made.
    const tab = { location: { href: '' }, opener: {} as unknown, close: vi.fn() }
    const open = vi.spyOn(window, 'open').mockReturnValue(tab as unknown as Window)
    const writeText = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined)

    const section = screen.getByRole('region', { name: 'Shopify checkout' })
    await user.type(within(section).getByLabelText("Store's Shopify address"), 'harbour-homes.myshopify.com')
    await user.click(within(section).getByRole('button', { name: 'Connect Shopify' }))

    expect(open).toHaveBeenCalledWith('', '_blank')
    await waitFor(() => expect(tab.location.href).toBe('https://harbour-homes.myshopify.com/admin/oauth/authorize?x=1'))
    expect(tab.opener).toBeNull()
    expect(server.callsTo('POST', 'platform/websites/web_1/shopify/install-link')[0]!.body).toEqual({ shop: 'harbour-homes.myshopify.com' })

    await user.click(within(section).getByRole('button', { name: 'Copy install link' }))
    await waitFor(() => expect(writeText).toHaveBeenCalledWith('https://harbour-homes.myshopify.com/admin/oauth/authorize?x=1'))
  })

  it('shows a connected store, and disconnects it', async () => {
    const user = userEvent.setup()
    const server = renderDetails(site({ shopify: shopify({ status: 'connected', shop_name: 'Harbour Homes', shop_domain: 'harbour-homes.myshopify.com', currency: 'EUR', currency_matches: true, checkout_available: true }) }))
    server.on('DELETE platform/websites/web_1/shopify', { body: { data: site({ shopify: shopify({ status: 'disconnected' }) }) } })

    const section = screen.getByRole('region', { name: 'Shopify checkout' })
    expect(within(section).getByText('Connected to Harbour Homes (harbour-homes.myshopify.com) · EUR')).toBeInTheDocument()
    expect(within(section).getByText(/Guests pay on the store's own checkout/)).toBeInTheDocument()

    await user.click(within(section).getByRole('button', { name: 'Disconnect' }))

    await waitFor(() => expect(server.callsTo('DELETE', 'platform/websites/web_1/shopify')).toHaveLength(1))
  })

  it('says why a connected store takes no bookings', () => {
    renderDetails(site({ shopify: shopify({ status: 'connected', shop_name: 'Harbour Homes', shop_domain: 'harbour-homes.myshopify.com', currency: 'USD', currency_matches: false }) }))

    expect(screen.getByText(/This store sells in USD, and the homes are priced in another currency/)).toBeInTheDocument()
  })

  it('says when a store stopped working', () => {
    renderDetails(site({ shopify: shopify({ status: 'broken', shop_domain: 'harbour-homes.myshopify.com', last_error: 'The app was removed from the store.' }) }))

    expect(screen.getByText(/The store's connection stopped working: The app was removed from the store\./)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Connect Shopify' })).toBeInTheDocument()
  })
})
