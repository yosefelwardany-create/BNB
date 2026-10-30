import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SCRIPT from '../../../../public/sites/editor/overlay.js?raw'

/**
 * The script the editor's preview loads, run on a page of two blocks. What it
 * says to the editor is read off postMessage; what the editor says to it is
 * sent as the editor would.
 */
type Sent = { source: string; type: string; [key: string]: unknown }

const PAGE = `
  <main>
    <section class="block block--hero" data-block-id="bl_hero">
      <h1 data-field="heading">Sea &amp; sun</h1>
      <p data-field="tagline" data-multiline data-placeholder="Add a tagline"></p>
      <a class="button" href="/sites/brand/search" data-field="button.label">See homes</a>
    </section>
    <section class="block block--text" data-block-id="bl_text">
      <h2 data-field="heading">About us</h2>
      <form action="/sites/brand/search"><button type="submit">Search</button></form>
    </section>
  </main>`

let sent: Sent[] = []
let post: ReturnType<typeof vi.spyOn>

// The script runs once in a real preview; here once per test, on one window.
// Each run's listeners are taken off after it, so runs never hear each other.
let listening: [EventTarget, string, EventListenerOrEventListenerObject, boolean | AddEventListenerOptions | undefined][] = []

function start() {
  document.body.innerHTML = PAGE
  sent = []
  listening = []
  for (const target of [window, document] as EventTarget[]) {
    const add = target.addEventListener.bind(target)
    vi.spyOn(target, 'addEventListener').mockImplementation((type, listener, options) => {
      if (listener !== null) listening.push([target, type, listener, options])
      add(type, listener, options)
    })
  }
  post = vi.spyOn(window, 'postMessage').mockImplementation((message: unknown) => {
    sent.push(message as Sent)
  })
  // The test's jsdom runs no <script>, so the file is run as one would be.
  // eslint-disable-next-line @typescript-eslint/no-implied-eval
  const run = new Function(SCRIPT) as () => void
  run()
}

/** What the overlay has said, by type. */
const said = (type: string) => sent.filter((message) => message.type === type)

/** As the editor around the frame would say it. */
function tell(data: Record<string, unknown>, origin = window.location.origin) {
  window.dispatchEvent(new MessageEvent('message', { data: { source: 'habitat-editor', ...data }, origin, source: window }))
}

const block = (id: string) => document.querySelector<HTMLElement>(`[data-block-id="${id}"]`)!
const field = (id: string, name: string) => block(id).querySelector<HTMLElement>(`[data-field="${name}"]`)!
const toolbar = () => document.querySelector<HTMLElement>('[role="toolbar"][aria-label="Block"]')!
const button = (label: string) => document.querySelector<HTMLButtonElement>(`button[aria-label="${label}"]`)!

function hover(id: string) {
  block(id).dispatchEvent(new MouseEvent('mouseover', { bubbles: true }))
}

beforeEach(start)
afterEach(() => {
  for (const [target, type, listener, options] of listening) target.removeEventListener(type, listener, options)
  document.body.innerHTML = ''
  document.documentElement.className = ''
})

describe('the overlay in the editor preview', () => {
  it('says it is ready, to its own origin', () => {
    expect(said('ready')).toHaveLength(1)
    expect(post).toHaveBeenCalledWith(expect.objectContaining({ source: 'habitat-overlay', type: 'ready' }), window.location.origin)
  })

  it('selects a block that is clicked, and keeps links and forms from leaving the page', () => {
    const click = new MouseEvent('click', { bubbles: true, cancelable: true })
    field('bl_hero', 'button.label').dispatchEvent(click)

    expect(click.defaultPrevented).toBe(true)
    expect(said('select').at(-1)).toMatchObject({ block: 'bl_hero' })
    expect(block('bl_hero').classList.contains('hx-selected')).toBe(true)

    const submit = new Event('submit', { bubbles: true, cancelable: true })
    block('bl_text').querySelector('form')!.dispatchEvent(submit)
    expect(submit.defaultPrevented).toBe(true)
  })

  it('sends what is typed into a field, as plain text', () => {
    const heading = field('bl_hero', 'heading')
    expect(heading.getAttribute('contenteditable')).toMatch(/plaintext-only|true/)

    heading.innerHTML = 'Sea, sun <b>and</b> you'
    heading.dispatchEvent(new Event('input', { bubbles: true }))

    expect(said('text').at(-1)).toMatchObject({ block: 'bl_hero', field: 'heading', value: 'Sea, sun and you' })
  })

  it('ends a one-line field on Enter, and adds a line in a multi-line one', () => {
    const oneLine = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })
    field('bl_hero', 'heading').dispatchEvent(oneLine)
    expect(oneLine.defaultPrevented).toBe(true)

    const multiLine = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })
    field('bl_hero', 'tagline').dispatchEvent(multiLine)
    expect(multiLine.defaultPrevented).toBe(false)
  })

  it('offers a new block above and below the block under the pointer', () => {
    hover('bl_text')
    button('Add a block below').click()
    expect(said('insert-at').at(-1)).toMatchObject({ index: 2 })

    hover('bl_hero')
    button('Add a block above').click()
    expect(said('insert-at').at(-1)).toMatchObject({ index: 0 })
  })

  it('duplicates and removes from the block toolbar', () => {
    hover('bl_text')
    expect(toolbar().dataset.block).toBe('bl_text')

    button('Duplicate block').click()
    button('Remove block').click()

    expect(said('duplicate').at(-1)).toMatchObject({ block: 'bl_text' })
    expect(said('remove').at(-1)).toMatchObject({ block: 'bl_text' })
  })

  it('moves a block with the handle, and Escape cancels', () => {
    // Two blocks, each 100 px tall, one above the other.
    block('bl_hero').getBoundingClientRect = () => new DOMRect(0, 0, 800, 100)
    block('bl_text').getBoundingClientRect = () => new DOMRect(0, 100, 800, 100)

    hover('bl_hero')
    button('Drag to move').dispatchEvent(new MouseEvent('pointerdown', { bubbles: true, clientY: 20 }))
    document.dispatchEvent(new MouseEvent('pointermove', { bubbles: true, clientY: 190 }))
    expect(document.querySelector('.hx-drop')).not.toBeNull()
    document.dispatchEvent(new MouseEvent('pointerup', { bubbles: true, clientY: 190 }))

    expect(said('move').at(-1)).toMatchObject({ block: 'bl_hero', to: 1 })
    expect(document.querySelector('.hx-drop')).toBeNull()

    sent = []
    button('Drag to move').dispatchEvent(new MouseEvent('pointerdown', { bubbles: true, clientY: 20 }))
    document.dispatchEvent(new MouseEvent('pointermove', { bubbles: true, clientY: 190 }))
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    document.dispatchEvent(new MouseEvent('pointerup', { bubbles: true, clientY: 190 }))

    expect(said('move')).toHaveLength(0)
    expect(document.querySelector('.hx-drop')).toBeNull()
  })

  it('sends undo and redo from the keyboard, outside a field', () => {
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'z', ctrlKey: true, bubbles: true, cancelable: true }))
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Z', ctrlKey: true, shiftKey: true, bubbles: true, cancelable: true }))
    field('bl_hero', 'heading').dispatchEvent(new KeyboardEvent('keydown', { key: 'z', ctrlKey: true, bubbles: true, cancelable: true }))

    expect(sent.map((message) => message.type).filter((type) => type === 'undo' || type === 'redo')).toEqual(['undo', 'redo'])
  })

  it('puts a block’s new HTML in place, and orders blocks as it is told', () => {
    tell({ type: 'replace', block: 'bl_text', html: '<section class="block block--text" data-block-id="bl_text"><h2 data-field="heading">Our story</h2></section>' })
    expect(field('bl_text', 'heading').textContent).toBe('Our story')
    expect(field('bl_text', 'heading').getAttribute('contenteditable')).toMatch(/plaintext-only|true/)

    tell({ type: 'replace', block: 'bl_new', html: '<section class="block block--cta" data-block-id="bl_new"><p data-field="text">Book now</p></section>' })
    tell({ type: 'order', blocks: ['bl_new', 'bl_hero'] })

    const ids = [...document.querySelectorAll<HTMLElement>('[data-block-id]')].map((node) => node.dataset.blockId)
    expect(ids).toEqual(['bl_new', 'bl_hero'])

    tell({ type: 'select', block: 'bl_hero' })
    expect(block('bl_hero').classList.contains('hx-selected')).toBe(true)
  })

  it('ignores messages from anywhere but its editor', () => {
    tell({ type: 'order', blocks: [] }, 'https://elsewhere.test')
    window.dispatchEvent(new MessageEvent('message', { data: { type: 'order', blocks: [] }, origin: window.location.origin, source: window }))

    expect(document.querySelectorAll('[data-block-id]')).toHaveLength(2)
  })
})
