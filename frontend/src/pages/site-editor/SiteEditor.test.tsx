import { describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { EditorState, SiteContent, Website } from '@/api/types'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi, type StubbedCall } from '@/test/server'
import { SiteEditor } from './SiteEditor'

/**
 * The page builder: pages and blocks on the left, the real site in the
 * middle, the selected block's settings on the right; every change saved as a
 * draft, which goes live on Publish.
 */
const BASE = 'platform/websites/web_1/editor'

function content(): SiteContent {
  return {
    schema: 1,
    theme: 'coastal',
    style: { primary: '#1f4e5f', accent: '#e07a5f', fonts: 'fraunces-inter', buttons: 'rounded' },
    pages: [{
      id: 'pg_home00000000', kind: 'home', title: 'Home', slug: '', in_menu: true,
      blocks: [
        { id: 'bl_hero00000000', type: 'hero', background: 'plain', fields: { heading: 'Sea & sun', show_search: true } },
        { id: 'bl_text00000000', type: 'text', background: 'plain', fields: { heading: 'About us' } },
      ],
    }],
  }
}

function state(overrides: Partial<EditorState> = {}): EditorState {
  return {
    website: { id: 'web_1', name: 'Stay With Us', slug: 'brand', kind: 'brand', status: 'live', public_url: 'https://habitat.test/sites/brand' },
    content: content(),
    version: 0,
    published_at: null,
    unpublished_changes: false,
    preview_url: 'https://habitat.test/sites/brand/preview?signature=abc',
    definitions: [
      {
        type: 'hero', label: 'Big photo + search', icon: 'image', description: 'A large photo with your heading.',
        fields: [
          { name: 'heading', kind: 'text', label: 'Heading', default: 'Find your place to stay', max: 120 },
          { name: 'show_search', kind: 'toggle', label: 'Show the search bar', default: true },
        ],
      },
      {
        type: 'text', label: 'Text', icon: 'type', description: 'A heading and a few paragraphs.',
        fields: [{ name: 'heading', kind: 'text', label: 'Heading', default: '', max: 120 }],
      },
    ],
    themes: [{ key: 'coastal', label: 'Coastal' }, { key: 'classic', label: 'Classic' }, { key: 'bold', label: 'Bold' }],
    font_pairs: [{ key: 'fraunces-inter', label: 'Fraunces and Inter' }],
    buttons: [{ key: 'rounded', label: 'Rounded' }, { key: 'pill', label: 'Pill' }, { key: 'square', label: 'Square' }],
    images: [],
    photos: [],
    homes: [],
    ...overrides,
  }
}

function renderEditor(initial = state()) {
  let version = initial.version
  const server = stubApi({
    'GET auth/me': { body: session({ permissions: [], is_platform_admin: true }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    [`GET ${BASE}`]: { body: { data: initial } },
    [`GET ${BASE}/preview`]: { body: { data: { url: initial.preview_url } } },
    [`PUT ${BASE}/draft`]: (call) => {
      version += 1

      return { body: { data: { version, unpublished_changes: true, content: (call.body as { content: SiteContent }).content } } }
    },
  })

  renderWithProviders(<SiteEditor apiBase={BASE} sitePath="platform/websites/web_1" backTo="/websites" saveDelay={0} who="platform" />)

  return server
}

const savedDraft = (call: StubbedCall | undefined) => (call?.body as { content: SiteContent; version: number } | undefined)

describe('the page builder', () => {
  it('shows the pages and blocks of the draft beside the real site', async () => {
    renderEditor()

    const pages = await screen.findByRole('region', { name: 'Pages' })
    expect(within(pages).getByRole('button', { name: 'Home' })).toBeInTheDocument()

    const blocks = screen.getByRole('region', { name: 'Blocks on Home' })
    expect(within(blocks).getByText('Big photo + search')).toBeInTheDocument()
    expect(within(blocks).getByText('Text')).toBeInTheDocument()

    expect(screen.getByTitle('Preview of Home')).toHaveAttribute('src', 'https://habitat.test/sites/brand/preview?signature=abc')
    expect(screen.getByText('All changes saved')).toBeInTheDocument()
  })

  it('adds a block from the library and saves the draft', async () => {
    const user = userEvent.setup()
    const server = renderEditor()

    await user.click(await screen.findByRole('button', { name: 'Add block' }))
    const library = screen.getByRole('dialog', { name: 'Add a block' })
    await user.click(within(library).getByRole('button', { name: /^Text/ }))

    await waitFor(() => expect(server.callsTo('PUT', `${BASE}/draft`)).toHaveLength(1))
    const body = savedDraft(server.callsTo('PUT', `${BASE}/draft`)[0])
    expect(body?.version).toBe(0)
    expect(body?.content.pages[0]!.blocks.map((block) => block.type)).toEqual(['hero', 'text', 'text'])
    expect(await screen.findByText('All changes saved')).toBeInTheDocument()
  })

  it('edits a block in the settings panel', async () => {
    const user = userEvent.setup()
    const server = renderEditor()

    await user.click(await screen.findByRole('button', { name: 'Edit Big photo + search' }))
    const settings = screen.getByRole('region', { name: 'Big photo + search settings' })
    const heading = within(settings).getByLabelText('Heading')
    await user.clear(heading)
    await user.type(heading, 'Homes by the sea')

    await waitFor(() => {
      const last = savedDraft(server.callsTo('PUT', `${BASE}/draft`).at(-1))
      expect(last?.content.pages[0]!.blocks[0]!.fields.heading).toBe('Homes by the sea')
    })
    expect(within(settings).getByText('16/120')).toBeInTheDocument()

    await user.click(within(settings).getByRole('switch', { name: 'Show the search bar' }))
    await waitFor(() => {
      const last = savedDraft(server.callsTo('PUT', `${BASE}/draft`).at(-1))
      expect(last?.content.pages[0]!.blocks[0]!.fields.show_search).toBe(false)
    })
  })

  it('moves a block down and back with undo', async () => {
    const user = userEvent.setup()
    const server = renderEditor()

    await user.click(await screen.findByRole('button', { name: 'Move Big photo + search down' }))
    await waitFor(() => {
      const last = savedDraft(server.callsTo('PUT', `${BASE}/draft`).at(-1))
      expect(last?.content.pages[0]!.blocks.map((block) => block.type)).toEqual(['text', 'hero'])
    })

    await user.click(screen.getByRole('button', { name: 'Undo' }))
    await waitFor(() => {
      const last = savedDraft(server.callsTo('PUT', `${BASE}/draft`).at(-1))
      expect(last?.content.pages[0]!.blocks.map((block) => block.type)).toEqual(['hero', 'text'])
    })
  })

  it('removes a block after asking, and duplicates one', async () => {
    const user = userEvent.setup()
    const server = renderEditor()

    await user.click(await screen.findByRole('button', { name: 'Duplicate Text' }))
    await waitFor(() => expect(savedDraft(server.callsTo('PUT', `${BASE}/draft`).at(-1))?.content.pages[0]!.blocks).toHaveLength(3))

    await user.click(screen.getAllByRole('button', { name: 'Remove Text' })[0]!)
    await user.click(screen.getByRole('button', { name: 'Yes, remove' }))
    await waitFor(() => expect(savedDraft(server.callsTo('PUT', `${BASE}/draft`).at(-1))?.content.pages[0]!.blocks).toHaveLength(2))
  })

  it('adds a page and opens it', async () => {
    const user = userEvent.setup()
    const server = renderEditor()

    await user.click(await screen.findByRole('button', { name: 'Add page' }))
    await user.type(screen.getByLabelText('Page name'), 'About us')
    await user.click(screen.getByRole('button', { name: 'Create page' }))

    await waitFor(() => {
      const pages = savedDraft(server.callsTo('PUT', `${BASE}/draft`).at(-1))?.content.pages
      expect(pages?.map((page) => page.slug)).toEqual(['', 'about-us'])
    })
    expect(screen.getByRole('region', { name: 'Blocks on About us' })).toBeInTheDocument()
  })

  it('says when someone else changed the site, and offers to reload', async () => {
    const user = userEvent.setup()
    const server = renderEditor()
    server.on(`PUT ${BASE}/draft`, { status: 409, body: { message: 'Someone else changed this site since you opened it. Reload to see their changes.' } })

    await user.click(await screen.findByRole('button', { name: 'Move Big photo + search down' }))

    expect(await screen.findByText('Someone else changed this site since you opened it. Reload to see their changes.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reload' })).toBeInTheDocument()
  })

  it('points at the block a refused save was about', async () => {
    const user = userEvent.setup()
    const server = renderEditor()
    server.on(`PUT ${BASE}/draft`, {
      status: 422,
      body: { message: 'Up to 120 characters.', errors: { 'pages.0.blocks.0.fields.heading': ['Up to 120 characters.'] } },
    })

    await user.click(await screen.findByRole('button', { name: 'Move Big photo + search down' }))

    expect(await screen.findByRole('region', { name: 'Text settings' })).toBeInTheDocument()
    expect(screen.getAllByText('Up to 120 characters.').length).toBeGreaterThan(0)
  })

  it('publishes the draft', async () => {
    const user = userEvent.setup()
    const server = renderEditor(state({ unpublished_changes: true }))
    server.on(`POST ${BASE}/publish`, { body: { data: state({ unpublished_changes: false, published_at: '2026-10-30T10:00:00+00:00' }) } })

    await user.click(await screen.findByRole('button', { name: 'Publish' }))

    await waitFor(() => expect(server.callsTo('POST', `${BASE}/publish`)).toHaveLength(1))
    expect(await screen.findByText('Published. Guests see your changes now.')).toBeInTheDocument()
  })

  it('asks before discarding the draft', async () => {
    const user = userEvent.setup()
    const server = renderEditor(state({ unpublished_changes: true }))
    server.on(`POST ${BASE}/discard`, { body: { data: state({ version: 3 }) } })

    await user.click(await screen.findByRole('button', { name: 'Discard changes' }))
    expect(server.callsTo('POST', `${BASE}/discard`)).toHaveLength(0)

    await user.click(screen.getByRole('button', { name: 'Yes, discard' }))
    await waitFor(() => expect(server.callsTo('POST', `${BASE}/discard`)).toHaveLength(1))
  })

  it('changes the theme and the colours', async () => {
    const user = userEvent.setup()
    const server = renderEditor()

    await user.click(await screen.findByRole('tab', { name: 'Theme' }))
    await user.click(screen.getByRole('radio', { name: /Bold/ }))

    await waitFor(() => expect(savedDraft(server.callsTo('PUT', `${BASE}/draft`).at(-1))?.content.theme).toBe('bold'))

    await user.click(screen.getByRole('radio', { name: 'Pill' }))
    await waitFor(() => expect(savedDraft(server.callsTo('PUT', `${BASE}/draft`).at(-1))?.content.style.buttons).toBe('pill'))
  })

  it('shows the page at phone width', async () => {
    const user = userEvent.setup()
    renderEditor()

    const size = await screen.findByRole('radiogroup', { name: 'Preview size' })
    await user.click(within(size).getByRole('radio', { name: 'Phone' }))
    expect(screen.getByTitle('Preview of Home').parentElement).toHaveStyle({ width: '390px', maxWidth: '100%' })

    await user.click(within(size).getByRole('radio', { name: 'Desktop' }))
    expect(screen.getByTitle('Preview of Home').parentElement).not.toHaveStyle({ width: '390px' })
  })

  it('asks before leaving with changes not saved', async () => {
    const user = userEvent.setup()
    const server = renderEditor()
    let answer = () => {}
    server.on(`PUT ${BASE}/draft`, () => new Promise((resolve) => {
      answer = () => resolve({ body: { data: { version: 1, unpublished_changes: true, content: content() } } })
    }))
    const leave = () => {
      const event = new Event('beforeunload', { cancelable: true })
      window.dispatchEvent(event)

      return event.defaultPrevented
    }

    await screen.findByText('All changes saved')
    expect(leave()).toBe(false)

    await user.click(screen.getByRole('button', { name: 'Move Big photo + search down' }))
    await screen.findByText('Saving…')
    expect(leave()).toBe(true)

    answer()
    await screen.findByText('All changes saved')
    expect(leave()).toBe(false)
  })

  it("a client edits through the portal's addresses, with their homes and details", async () => {
    const user = userEvent.setup()
    const PORTAL = 'portal/owner/websites/web_1'
    const client: Website = {
      id: 'web_1', slug: 'brand', name: 'Stay With Us', kind: 'client', organization_id: 'org_1', organization_name: 'Harbour Homes', status: 'live',
      settings: { contact_email: 'old@stay.test', contact_phone: null, address_line: null, instagram_url: null, facebook_url: null, logo_url: null, hero_url: null },
      hold_minutes: 30, request_hours: 24, listings: [], public_url: 'https://habitat.test/sites/brand', published_at: null, created_at: null,
    }
    const server = stubApi({
      'GET auth/me': { body: session({ permissions: [] }) },
      'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
      [`GET ${PORTAL}/editor`]: { body: { data: state() } },
      [`GET ${PORTAL}/editor/preview`]: { body: { data: { url: state().preview_url } } },
      [`PUT ${PORTAL}/editor/draft`]: (call) => ({ body: { data: { version: 1, unpublished_changes: true, content: (call.body as { content: SiteContent }).content } } }),
      [`GET ${PORTAL}`]: { body: { data: client } },
      [`PATCH ${PORTAL}`]: { body: { data: { ...client, settings: { ...client.settings, contact_email: 'new@stay.test' } } } },
      [`GET ${PORTAL}/listing-options`]: { body: { data: [] } },
    })
    renderWithProviders(<SiteEditor apiBase={`${PORTAL}/editor`} sitePath={PORTAL} backTo="/website" saveDelay={0} who="client" />)

    await user.click(await screen.findByRole('button', { name: 'Move Big photo + search down' }))
    await waitFor(() => expect(server.callsTo('PUT', `${PORTAL}/editor/draft`)).toHaveLength(1))
    expect(screen.getByRole('link', { name: 'Back to your website' })).toHaveAttribute('href', '/website')

    await user.click(screen.getByRole('tab', { name: 'Details' }))
    const email = await screen.findByLabelText('Contact email')
    await user.clear(email)
    await user.type(email, 'new@stay.test')
    await user.click(screen.getByRole('button', { name: 'Save details' }))
    await waitFor(() => expect(server.callsTo('PATCH', PORTAL)).toHaveLength(1))
    // A client's logo is a photo file, never an SVG.
    expect(screen.getByLabelText('Upload a logo')).toHaveAttribute('accept', 'image/png,image/jpeg,image/webp')

    await user.click(screen.getByRole('tab', { name: 'Homes' }))
    await waitFor(() => expect(server.callsTo('GET', `${PORTAL}/listing-options`)).toHaveLength(1))
  })

  it("tells a client what publishing does while their site is not online yet, and refreshes their list of sites", async () => {
    const user = userEvent.setup()
    const PORTAL = 'portal/owner/websites/web_1'
    const offline = state({ website: { ...state().website, status: 'draft' } })
    stubApi({
      'GET auth/me': { body: session({ permissions: [] }) },
      'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
      [`GET ${PORTAL}/editor`]: { body: { data: offline } },
      [`GET ${PORTAL}/editor/preview`]: { body: { data: { url: offline.preview_url } } },
      [`POST ${PORTAL}/editor/publish`]: { body: { data: offline } },
    })
    const rendered = renderWithProviders(<SiteEditor apiBase={`${PORTAL}/editor`} sitePath={PORTAL} backTo="/website" saveDelay={0} who="client" />)
    const client = rendered.client
    if (client === undefined) throw new Error('No query client was made.')
    const invalidated = vi.spyOn(client, 'invalidateQueries')

    await user.click(await screen.findByRole('button', { name: 'Publish' }))

    expect(await screen.findByText('Published. Guests will see it once the site is online.')).toBeInTheDocument()
    expect(invalidated).toHaveBeenCalledWith({ queryKey: ['owner-websites'] })
  })
})
