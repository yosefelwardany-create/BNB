import { describe, expect, it } from 'vitest'
import { useState } from 'react'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { storeAuth } from '@/api/client'
import type { EditorState, SiteContent, Website } from '@/api/types'
import { createQueryClient } from '@/lib/queryClient'
import { stubApi, type StubbedCall } from '@/test/server'
import { SiteEditor } from './SiteEditor'

/**
 * The page builder against the application's own cache settings, where a
 * screen left and opened again reads what the cache kept.
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
        { id: 'bl_hero00000000', type: 'hero', background: 'plain', fields: { heading: 'Sea & sun' } },
        { id: 'bl_text00000000', type: 'text', background: 'plain', fields: { heading: 'About us', body: '<div>Our story</div>' } },
      ],
    }],
  }
}

function state(): EditorState {
  return {
    website: { id: 'web_1', name: 'Stay With Us', slug: 'brand', kind: 'brand', status: 'live', public_url: 'https://habitat.test/sites/brand' },
    content: content(),
    version: 0,
    published_at: null,
    unpublished_changes: true,
    preview_url: 'https://habitat.test/sites/brand/preview?signature=abc',
    definitions: [
      { type: 'hero', label: 'Big photo + search', icon: 'image', description: '', fields: [{ name: 'heading', kind: 'text', label: 'Heading', default: '', max: 120 }] },
      {
        type: 'text', label: 'Text', icon: 'type', description: '', fields: [
          { name: 'heading', kind: 'text', label: 'Heading', default: '', max: 120 },
          { name: 'body', kind: 'rich_text', label: 'Words', default: '', max: 5000 },
        ],
      },
    ],
    themes: [], font_pairs: [], buttons: [], images: [], photos: [], homes: [],
  }
}

function site(overrides: Partial<Website> = {}): Website {
  return {
    id: 'web_1', slug: 'brand', name: 'Stay With Us', kind: 'brand', organization_id: null, organization_name: null, status: 'live',
    settings: { contact_email: 'old@stay.test', contact_phone: null, address_line: null, instagram_url: null, facebook_url: null, logo_url: null, hero_url: null },
    hold_minutes: 30, request_hours: 24, listings: [], public_url: 'https://habitat.test/sites/brand', published_at: null, created_at: null,
    ...overrides,
  }
}

/** A switch that takes the editor away and brings it back, as leaving the screen does. */
function Leaving({ saveDelay = 0 }: { saveDelay?: number }) {
  const [shown, setShown] = useState(true)

  return (
    <>
      <button type="button" onClick={() => setShown((value) => !value)}>Leave or return</button>
      {shown && <SiteEditor apiBase={BASE} sitePath="platform/websites/web_1" backTo="/websites" saveDelay={saveDelay} who="platform" />}
    </>
  )
}

function renderEditor({ saveDelay = 0, put, hold }: {
  saveDelay?: number
  put?: (call: StubbedCall) => { status?: number; body?: unknown }
  /** Drafts are answered only once this settles: a slow network. */
  hold?: Promise<void>
} = {}) {
  storeAuth({ token: 'test-token', organizationId: 'org_1' })
  let version = 0
  let draft = content()
  let current = site()
  const server = stubApi({
    [`GET ${BASE}`]: () => ({ body: { data: { ...state(), content: draft, version } } }),
    [`GET ${BASE}/preview`]: { body: { data: { url: state().preview_url } } },
    [`PUT ${BASE}/draft`]: put ?? (async (call) => {
      await hold
      const sent = (call.body as { version: number; content: SiteContent })
      if (sent.version !== version) return { status: 409, body: { message: 'Someone else changed this site since you opened it. Reload to see their changes.' } }
      version += 1
      draft = sent.content

      return { body: { data: { version, unpublished_changes: true, content: sent.content } } }
    }),
    'GET platform/websites/web_1': () => ({ body: { data: current } }),
    'PATCH platform/websites/web_1': (call) => {
      const body = call.body as { settings: Website['settings'] }
      current = site({ settings: { ...current.settings, ...body.settings } })

      return { body: { data: current } }
    },
    'GET platform/website-listing-options': {
      body: { data: [{ listing_id: 'lst_1', title: 'Sea View Flat', organization_id: 'org_2', organization_name: 'Harbour Homes', city: 'Lisbon', ready: true, needs: [], can_publish: false, property_active: true }] },
    },
    'PUT platform/websites/web_1/listings': (call) => {
      const body = call.body as { listings: { listing_id: string; is_featured: boolean }[] }
      current = site({ listings: body.listings.map((row) => ({ ...row, title: 'Sea View Flat', organization_id: 'org_2', organization_name: 'Harbour Homes' })) })

      return { body: { data: current } }
    },
  })

  render(
    <MemoryRouter>
      <QueryClientProvider client={createQueryClient()}>
        <Leaving saveDelay={saveDelay} />
      </QueryClientProvider>
    </MemoryRouter>,
  )

  return server
}

const lastDraft = (server: ReturnType<typeof stubApi>) =>
  (server.callsTo('PUT', `${BASE}/draft`).at(-1)?.body as { content: SiteContent; version: number } | undefined)

describe('the page builder, kept honest', () => {
  it('keeps saved site details when the tab is opened again', async () => {
    const user = userEvent.setup()
    renderEditor()

    await user.click(await screen.findByRole('tab', { name: 'Details' }))
    const email = await screen.findByLabelText('Contact email')
    await user.clear(email)
    await user.type(email, 'new@stay.test')
    await user.click(screen.getByRole('button', { name: 'Save details' }))
    await screen.findByText('Saved.')

    await user.click(screen.getByRole('tab', { name: 'Pages' }))
    await user.click(screen.getByRole('tab', { name: 'Details' }))

    expect(await screen.findByLabelText('Contact email')).toHaveValue('new@stay.test')
  })

  it('keeps saved homes when the tab is opened again', async () => {
    const user = userEvent.setup()
    renderEditor()

    await user.click(await screen.findByRole('tab', { name: 'Homes' }))
    await user.click(await screen.findByRole('checkbox', { name: 'Show Sea View Flat' }))
    await user.click(screen.getByRole('button', { name: 'Save homes' }))
    await screen.findByText('Saved.')

    await user.click(screen.getByRole('tab', { name: 'Pages' }))
    await user.click(screen.getByRole('tab', { name: 'Homes' }))

    expect(await screen.findByRole('checkbox', { name: 'Show Sea View Flat' })).toBeChecked()
  })

  it('opens on the latest draft when it is left and opened again', async () => {
    const user = userEvent.setup()
    const server = renderEditor()

    await user.click(await screen.findByRole('button', { name: 'Move Big photo + search down' }))
    await waitFor(() => expect(lastDraft(server)?.version).toBe(0))
    await screen.findByText('All changes saved')

    await user.click(screen.getByRole('button', { name: 'Leave or return' }))
    await user.click(screen.getByRole('button', { name: 'Leave or return' }))

    await user.click(await screen.findByRole('button', { name: 'Duplicate Text' }))
    await waitFor(() => expect(server.callsTo('PUT', `${BASE}/draft`)).toHaveLength(2))
    expect(lastDraft(server)?.version).toBe(1)
    expect(screen.queryByText(/Someone else changed this site/)).not.toBeInTheDocument()
  })

  it('saves a change still waiting when the editor is left', async () => {
    const user = userEvent.setup()
    const server = renderEditor({ saveDelay: 60_000 })

    await user.click(await screen.findByRole('button', { name: 'Move Big photo + search down' }))
    expect(server.callsTo('PUT', `${BASE}/draft`)).toHaveLength(0)

    await user.click(screen.getByRole('button', { name: 'Leave or return' }))

    await waitFor(() => expect(server.callsTo('PUT', `${BASE}/draft`)).toHaveLength(1))
    expect(lastDraft(server)?.content.pages[0]!.blocks.map((block) => block.type)).toEqual(['text', 'hero'])
  })

  it('does not publish over a save that was refused', async () => {
    const user = userEvent.setup()
    const server = renderEditor({
      saveDelay: 60_000,
      put: () => ({ status: 409, body: { message: 'Someone else changed this site since you opened it. Reload to see their changes.' } }),
    })
    server.on(`POST ${BASE}/publish`, { body: { data: state() } })

    await user.click(await screen.findByRole('button', { name: 'Move Big photo + search down' }))
    await user.click(screen.getByRole('button', { name: 'Publish' }))

    expect(await screen.findByText(/Someone else changed this site/)).toBeInTheDocument()
    expect(server.callsTo('POST', `${BASE}/publish`)).toHaveLength(0)
    expect(screen.queryByText('Published. Guests see your changes now.')).not.toBeInTheDocument()
  })

  it('sends the draft version it publishes', async () => {
    const user = userEvent.setup()
    const server = renderEditor()
    server.on(`POST ${BASE}/publish`, { body: { data: state() } })

    await user.click(await screen.findByRole('button', { name: 'Move Big photo + search down' }))
    await screen.findByText('All changes saved')
    await user.click(screen.getByRole('button', { name: 'Publish' }))

    await waitFor(() => expect(server.callsTo('POST', `${BASE}/publish`)).toHaveLength(1))
    expect(server.callsTo('POST', `${BASE}/publish`)[0]?.body).toEqual({ version: 1 })
  })

  it('points at the block a refused publish was about', async () => {
    const user = userEvent.setup()
    const server = renderEditor()
    server.on(`POST ${BASE}/publish`, {
      status: 422,
      body: { message: 'Choose a photo from this site.', errors: { 'pages.0.blocks.1.fields.heading': ['Choose a photo from this site.'] } },
    })

    await user.click(await screen.findByRole('button', { name: 'Publish' }))

    expect(await screen.findByRole('region', { name: 'Text settings' })).toBeInTheDocument()
  })

  it('shows formatted text as the server kept it, once a save comes back', async () => {
    const user = userEvent.setup()
    renderEditor({
      put: (call) => {
        const kept = structuredClone((call.body as { content: SiteContent }).content)
        kept.pages[0]!.blocks.find((block) => block.type === 'text')!.fields.body = '<p>Our story</p>'

        return { body: { data: { version: 1, unpublished_changes: true, content: kept } } }
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Move Big photo + search down' }))
    await screen.findByText('All changes saved')
    await user.click(screen.getByRole('button', { name: 'Edit Text' }))

    const words = within(screen.getByRole('region', { name: 'Text settings' })).getByRole('textbox', { name: 'Words' })
    expect(words.innerHTML).toBe('<p>Our story</p>')
  })

  it('keeps a space typed at the end of a heading, whatever the server trims', async () => {
    const user = userEvent.setup()
    renderEditor({
      saveDelay: 10,
      put: (call) => {
        const kept = structuredClone((call.body as { content: SiteContent }).content)
        for (const block of kept.pages[0]!.blocks) block.fields.heading = String(block.fields.heading).trim()

        return { body: { data: { version: 1, unpublished_changes: true, content: kept } } }
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Edit Big photo + search' }))
    const heading = within(screen.getByRole('region', { name: 'Big photo + search settings' })).getByLabelText('Heading')
    await user.type(heading, ' ')
    await screen.findByText('All changes saved')
    await user.type(heading, 'today')

    expect(heading).toHaveValue('Sea & sun today')
  })

  it('waits for a save still on its way before opening again', async () => {
    const user = userEvent.setup()
    let release = () => {}
    const server = renderEditor({ saveDelay: 50, hold: new Promise<void>((resolve) => { release = resolve }) })

    await user.click(await screen.findByRole('button', { name: 'Move Big photo + search down' }))
    await user.click(screen.getByRole('button', { name: 'Leave or return' }))
    await waitFor(() => expect(server.callsTo('PUT', `${BASE}/draft`)).toHaveLength(1))
    await user.click(screen.getByRole('button', { name: 'Leave or return' }))
    release()

    await user.click(await screen.findByRole('button', { name: 'Duplicate Text' }))
    await waitFor(() => expect(server.callsTo('PUT', `${BASE}/draft`)).toHaveLength(2))
    expect(lastDraft(server)?.version).toBe(1)
  })

  it('undoes a word typed, not one letter of it', async () => {
    const user = userEvent.setup()
    const server = renderEditor({ saveDelay: 50 })

    await user.click(await screen.findByRole('button', { name: 'Edit Big photo + search' }))
    const heading = within(screen.getByRole('region', { name: 'Big photo + search settings' })).getByLabelText('Heading')
    await user.type(heading, ' today')
    await waitFor(() => expect(lastDraft(server)?.content.pages[0]!.blocks[0]!.fields.heading).toBe('Sea & sun today'))

    await user.click(screen.getByRole('button', { name: 'Undo' }))

    await waitFor(() => expect(lastDraft(server)?.content.pages[0]!.blocks[0]!.fields.heading).toBe('Sea & sun'))
    expect(heading).toHaveValue('Sea & sun')
  })
})
