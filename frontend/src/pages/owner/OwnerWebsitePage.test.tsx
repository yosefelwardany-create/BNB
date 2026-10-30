import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import type { Website } from '@/api/types'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'
import { OwnerWebsitePage } from './OwnerWebsitePage'

/**
 * A client's own sites, in the client portal: what each is, whether it is
 * online, and the way into its editor.
 */
function site(overrides: Partial<Website> = {}): Website {
  return {
    id: 'web_1', slug: 'harbour', name: 'Harbour stays', kind: 'client', organization_id: 'org_1', organization_name: 'Harbour Homes',
    status: 'live',
    settings: { contact_email: 'stay@harbour.test', contact_phone: null, address_line: null, instagram_url: null, facebook_url: null, logo_url: null, hero_url: null },
    hold_minutes: 30, request_hours: 24, listings: [], public_url: 'https://habitat.test/sites/harbour', published_at: null, created_at: null,
    unpublished_changes: true,
    ...overrides,
  }
}

function renderPage(sites: Website[], preview = false) {
  stubApi({
    'GET auth/me': { body: session({ permissions: [] }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    'GET portal/owner/websites': { body: { data: sites } },
  })

  renderWithProviders(<OwnerWebsitePage preview={preview} />, { route: '/website' })
}

describe("the client's website", () => {
  it("lists the account's sites with Edit site", async () => {
    renderPage([site(), site({ id: 'web_2', name: 'Harbour rooms', status: 'draft', unpublished_changes: false, public_url: 'https://habitat.test/sites/rooms' })])

    expect(await screen.findByRole('heading', { name: 'Website' })).toBeInTheDocument()

    const first = await screen.findByRole('article', { name: 'Harbour stays' })
    expect(within(first).getByText('Online')).toBeInTheDocument()
    expect(within(first).getByText('Changes not published yet')).toBeInTheDocument()
    expect(within(first).getByRole('link', { name: 'https://habitat.test/sites/harbour' })).toHaveAttribute('href', 'https://habitat.test/sites/harbour')
    expect(within(first).getByRole('link', { name: 'Edit Harbour stays' })).toHaveAttribute('href', '/website/web_1/edit')

    const second = screen.getByRole('article', { name: 'Harbour rooms' })
    expect(within(second).getByText('Not online yet')).toBeInTheDocument()
  })

  it('says when the account has no site yet', async () => {
    renderPage([])

    expect(await screen.findByText('No website yet')).toBeInTheDocument()
  })

  it("in the client view, leaves editing to the platform's Websites screen", async () => {
    renderPage([site()], true)

    const card = await screen.findByRole('article', { name: 'Harbour stays' })
    expect(within(card).queryByRole('link', { name: 'Edit Harbour stays' })).not.toBeInTheDocument()
    expect(within(card).getByText(/Edit it from the Websites screen/)).toBeInTheDocument()
  })
})
