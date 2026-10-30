import { describe, expect, it, vi } from 'vitest'
import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { EditorState, SiteContent } from '@/api/types'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi, type StubbedCall } from '@/test/server'
import { SiteEditor } from './SiteEditor'

/**
 * The editor and its preview in step: the page says what was done on it,
 * the editor keeps the document and answers with blocks rendered afresh.
 */
const BASE = 'platform/websites/web_1/editor'
const ORIGIN = 'https://habitat.test'
const HERO = 'bl_hero00000000'
const TEXT = 'bl_text00000000'

function content(): SiteContent {
  return {
    schema: 1,
    theme: 'coastal',
    style: { primary: '#1f4e5f', accent: '#e07a5f', fonts: 'fraunces-inter', buttons: 'rounded' },
    pages: [{
      id: 'pg_home00000000', kind: 'home', title: 'Home', slug: '', in_menu: true,
      blocks: [
        { id: HERO, type: 'hero', background: 'plain', fields: { heading: 'Sea & sun' } },
        { id: TEXT, type: 'text', background: 'plain', fields: { heading: 'About us' } },
      ],
    }],
  }
}

function state(): EditorState {
  return {
    website: { id: 'web_1', name: 'Stay With Us', slug: 'brand', kind: 'brand', status: 'live', public_url: `${ORIGIN}/sites/brand` },
    content: content(),
    version: 0,
    published_at: null,
    unpublished_changes: false,
    preview_url: `${ORIGIN}/sites/brand/preview?signature=abc`,
    definitions: [
      { type: 'hero', label: 'Big photo + search', icon: 'image', description: '', fields: [{ name: 'heading', kind: 'text', label: 'Heading', default: '', max: 120 }] },
      { type: 'text', label: 'Text', icon: 'type', description: '', fields: [{ name: 'heading', kind: 'text', label: 'Heading', default: '', max: 120 }] },
    ],
    themes: [{ key: 'coastal', label: 'Coastal' }, { key: 'bold', label: 'Bold' }],
    font_pairs: [{ key: 'fraunces-inter', label: 'Fraunces and Inter' }],
    buttons: [{ key: 'rounded', label: 'Rounded' }],
    images: [], photos: [], homes: [],
  }
}

function renderEditor() {
  let version = 0
  const server = stubApi({
    'GET auth/me': { body: session({ permissions: [], is_platform_admin: true }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    [`GET ${BASE}`]: { body: { data: state() } },
    [`GET ${BASE}/preview`]: { body: { data: { url: state().preview_url } } },
    [`PUT ${BASE}/draft`]: (call) => {
      version += 1

      return { body: { data: { version, unpublished_changes: true, content: (call.body as { content: SiteContent }).content } } }
    },
    [`POST ${BASE}/render`]: (call) => {
      const { block } = call.body as { block: string }

      return { body: { data: { html: `<section data-block-id="${block}">rendered</section>` } } }
    },
  })

  renderWithProviders(<SiteEditor apiBase={BASE} sitePath="platform/websites/web_1" backTo="/websites" saveDelay={0} who="platform" />)

  return server
}

const frame = () => screen.getByTitle<HTMLIFrameElement>('Preview of Home')

/** The window of the page in the preview frame. */
function page(): Window {
  const view = frame().contentWindow
  if (view === null) throw new Error('The preview frame has no window.')

  return view
}

/** What the editor has told the page, read off its frame. */
function listen() {
  return vi.spyOn(page(), 'postMessage').mockImplementation(() => {})
}

const told = (spy: ReturnType<typeof listen>, type: string) =>
  spy.mock.calls.map(([message]) => message as { source: string; type: string; [key: string]: unknown }).filter((message) => message.type === type)

/** As the overlay in the frame would say it. */
function fromPage(data: Record<string, unknown>, origin = ORIGIN) {
  act(() => {
    window.dispatchEvent(new MessageEvent('message', { data: { source: 'habitat-overlay', ...data }, origin, source: page() }))
  })
}

const lastDraft = (server: ReturnType<typeof stubApi>) =>
  (server.callsTo('PUT', `${BASE}/draft`).at(-1)?.body as { content: SiteContent } | undefined)?.content

const blockTypes = (server: ReturnType<typeof stubApi>) => lastDraft(server)?.pages[0]!.blocks.map((block) => block.type)

const renders = (server: ReturnType<typeof stubApi>) => server.callsTo('POST', `${BASE}/render`).map((call: StubbedCall) => (call.body as { block: string }).block)

describe('the page builder and its page', () => {
  it('types on the page into the draft, without rendering again or reloading', async () => {
    const server = renderEditor()
    await screen.findByRole('region', { name: 'Blocks on Home' })
    const before = frame()

    fromPage({ type: 'ready' })
    fromPage({ type: 'text', block: HERO, field: 'heading', value: 'Sea, sun and you' })

    await waitFor(() => expect(lastDraft(server)?.pages[0]!.blocks[0]!.fields.heading).toBe('Sea, sun and you'))
    await screen.findByText('All changes saved')
    expect(renders(server)).toEqual([])
    expect(frame()).toBe(before)

    // The settings panel shows the words typed on the page.
    await userEvent.setup().click(screen.getByRole('button', { name: 'Edit Big photo + search' }))
    expect(within(screen.getByRole('region', { name: 'Big photo + search settings' })).getByLabelText('Heading')).toHaveValue('Sea, sun and you')
  })

  it('renders a block changed in the panel again, and swaps it in place', async () => {
    const user = userEvent.setup()
    const server = renderEditor()
    await screen.findByRole('region', { name: 'Blocks on Home' })
    const before = frame()
    const spy = listen()

    await user.click(screen.getByRole('button', { name: 'Edit Big photo + search' }))
    await user.type(within(screen.getByRole('region', { name: 'Big photo + search settings' })).getByLabelText('Heading'), '!')

    await waitFor(() => expect(told(spy, 'replace').at(-1)).toMatchObject({ source: 'habitat-editor', block: HERO, html: `<section data-block-id="${HERO}">rendered</section>` }))
    expect(spy).toHaveBeenCalledWith(expect.anything(), ORIGIN)
    expect(renders(server).at(-1)).toBe(HERO)
    expect((server.callsTo('POST', `${BASE}/render`).at(-1)?.body as { content: SiteContent }).content.pages[0]!.blocks[0]!.fields.heading).toBe('Sea & sun!')
    await screen.findByText('All changes saved')
    expect(frame()).toBe(before)
  })

  it('selects on the page what is selected in the panel, and back', async () => {
    const user = userEvent.setup()
    renderEditor()
    await screen.findByRole('region', { name: 'Blocks on Home' })
    const spy = listen()

    await user.click(screen.getByRole('button', { name: 'Edit Text' }))
    await waitFor(() => expect(told(spy, 'select').at(-1)).toMatchObject({ block: TEXT }))

    fromPage({ type: 'select', block: HERO })
    expect(await screen.findByRole('region', { name: 'Big photo + search settings' })).toBeInTheDocument()
  })

  it('opens the block library where "+" was pressed on the page', async () => {
    const user = userEvent.setup()
    const server = renderEditor()
    await screen.findByRole('region', { name: 'Blocks on Home' })

    fromPage({ type: 'insert-at', index: 1 })
    const library = await screen.findByRole('dialog', { name: 'Add a block' })
    await user.click(within(library).getByRole('button', { name: /^Text/ }))

    await waitFor(() => expect(blockTypes(server)).toEqual(['hero', 'text', 'text']))
    expect(lastDraft(server)?.pages[0]!.blocks[2]!.id).toBe(TEXT)
  })

  it('moves, duplicates and removes blocks as the page asks, and undoes from it', async () => {
    const user = userEvent.setup()
    const server = renderEditor()
    await screen.findByRole('region', { name: 'Blocks on Home' })
    const spy = listen()

    fromPage({ type: 'move', block: HERO, to: 1 })
    await waitFor(() => expect(lastDraft(server)?.pages[0]!.blocks.map((block) => block.id)).toEqual([TEXT, HERO]))
    expect(told(spy, 'order').at(-1)).toMatchObject({ blocks: [TEXT, HERO] })

    fromPage({ type: 'undo' })
    await waitFor(() => expect(lastDraft(server)?.pages[0]!.blocks.map((block) => block.id)).toEqual([HERO, TEXT]))

    fromPage({ type: 'duplicate', block: TEXT })
    await waitFor(() => expect(blockTypes(server)).toEqual(['hero', 'text', 'text']))

    fromPage({ type: 'remove', block: HERO })
    const ask = await screen.findByRole('dialog', { name: 'Remove this block?' })
    await user.click(within(ask).getByRole('button', { name: 'Yes, remove' }))
    await waitFor(() => expect(blockTypes(server)).toEqual(['text', 'text']))
  })

  it('reloads the page for a new theme, once it is saved', async () => {
    const user = userEvent.setup()
    const server = renderEditor()
    await screen.findByRole('region', { name: 'Blocks on Home' })
    const before = frame()

    await user.click(screen.getByRole('tab', { name: 'Theme' }))
    await user.click(screen.getByRole('radio', { name: /Bold/ }))

    await waitFor(() => expect(lastDraft(server)?.theme).toBe('bold'))
    await waitFor(() => expect(frame()).not.toBe(before))
    expect(renders(server)).toEqual([])
  })

  it('takes no message from anywhere but its own page', async () => {
    const server = renderEditor()
    await screen.findByRole('region', { name: 'Blocks on Home' })

    fromPage({ type: 'move', block: HERO, to: 1 }, 'https://elsewhere.test')
    act(() => {
      window.dispatchEvent(new MessageEvent('message', { data: { source: 'habitat-overlay', type: 'move', block: HERO, to: 1 }, origin: ORIGIN, source: window }))
    })

    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(server.callsTo('PUT', `${BASE}/draft`)).toHaveLength(0)
  })

  it('asks again for a block whose render failed', async () => {
    const user = userEvent.setup()
    const server = renderEditor()
    let answered = 0
    server.on(`POST ${BASE}/render`, (call) => {
      answered += 1
      if (answered === 1) return { status: 503, body: { message: 'Service unavailable.' } }

      return { body: { data: { html: `<section data-block-id="${(call.body as { block: string }).block}">rendered</section>` } } }
    })
    await screen.findByRole('region', { name: 'Blocks on Home' })
    const spy = listen()

    await user.click(screen.getByRole('button', { name: 'Edit Big photo + search' }))
    await user.type(within(screen.getByRole('region', { name: 'Big photo + search settings' })).getByLabelText('Heading'), '!')

    await waitFor(() => expect(told(spy, 'replace').at(-1)).toMatchObject({ block: HERO }), { timeout: 4000 })
    expect(renders(server).filter((block) => block === HERO).length).toBeGreaterThanOrEqual(2)
  })

  it('brings a page that finished loading after a save up to the draft', async () => {
    const user = userEvent.setup()
    const server = renderEditor()
    await screen.findByRole('region', { name: 'Blocks on Home' })

    await user.click(screen.getByRole('button', { name: 'Edit Big photo + search' }))
    await user.type(within(screen.getByRole('region', { name: 'Big photo + search settings' })).getByLabelText('Heading'), '!')
    await waitFor(() => expect(renders(server)).toEqual([HERO]))
    await screen.findByText('All changes saved')

    // The page loaded what was saved when it was asked for, before the change.
    fromPage({ type: 'ready' })

    await waitFor(() => expect(renders(server)).toEqual([HERO, HERO]))
  })

  it('drops renders still on their way when the changes are discarded', async () => {
    const user = userEvent.setup()
    const server = renderEditor()
    let answer = () => {}
    server.on(`POST ${BASE}/render`, (call) => new Promise((resolve) => {
      answer = () => resolve({ body: { data: { html: `<section data-block-id="${(call.body as { block: string }).block}">discarded words</section>` } } })
    }))
    server.on(`POST ${BASE}/discard`, { body: { data: { ...state(), version: 2 } } })
    await screen.findByRole('region', { name: 'Blocks on Home' })

    await user.click(screen.getByRole('button', { name: 'Edit Big photo + search' }))
    await user.type(within(screen.getByRole('region', { name: 'Big photo + search settings' })).getByLabelText('Heading'), '!')
    await waitFor(() => expect(renders(server)).toEqual([HERO]))
    await screen.findByText('All changes saved')

    await user.click(screen.getByRole('button', { name: 'Discard changes' }))
    await user.click(within(await screen.findByRole('dialog', { name: 'Discard your changes?' })).getByRole('button', { name: 'Yes, discard' }))
    await screen.findByText(/Your changes were discarded/)
    const spy = listen()

    answer()
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(told(spy, 'replace')).toEqual([])
  })

  it('keeps a file dropped outside a photo field from opening in place of the editor', async () => {
    renderEditor()
    await screen.findByRole('region', { name: 'Blocks on Home' })

    for (const type of ['dragover', 'drop']) {
      const event = new Event(type, { bubbles: true, cancelable: true })
      Object.defineProperty(event, 'dataTransfer', { value: { types: ['Files'], files: [] } })
      screen.getByRole('region', { name: 'Blocks on Home' }).dispatchEvent(event)
      expect(event.defaultPrevented).toBe(true)
    }
  })
})
