import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SCRIPT from '../../../public/sites/embed.js?raw'

/**
 * The script a client's store loads once (public/sites/embed.js), run on a
 * store page with tags for the pieces. What the pieces' frames say is sent
 * as a frame on the platform's address would send it.
 */
const BASE = 'https://habitat.test'
const STORE = 'https://shop.example.com'

const PAGE = `
  <script src="${BASE}/sites/embed.js" defer></script>
  <div id="search" data-habitat-site="harbour" data-habitat-piece="search"></div>
  <div id="grid" data-habitat-site="harbour" data-habitat-piece="homes"></div>
  <div id="grid2" data-habitat-site="harbour" data-habitat-piece="homes"></div>
  <div id="box" data-habitat-site="harbour" data-habitat-piece="book" data-habitat-home="harbour-loft"></div>`

let assign: ReturnType<typeof vi.fn>
let listening: [EventTarget, string, EventListenerOrEventListenerObject, boolean | AddEventListenerOptions | undefined][] = []

function start(page = PAGE, search = '?checkin=2027-06-01&checkout=2027-06-04&guests=2') {
  document.body.innerHTML = page
  document.body.style.overflow = ''
  assign = vi.fn()
  vi.stubGlobal('location', { ...window.location, href: `${STORE}/pages/stays${search}`, origin: STORE, search, assign })
  listening = []
  for (const target of [window, document] as EventTarget[]) {
    const add = target.addEventListener.bind(target)
    vi.spyOn(target, 'addEventListener').mockImplementation((type, listener, options) => {
      if (listener !== null) listening.push([target, type, listener, options])
      add(type, listener, options)
    })
  }
  // The test's jsdom runs no <script>, so the file is run as one would be.
  // eslint-disable-next-line @typescript-eslint/no-implied-eval
  const run = new Function(SCRIPT) as () => void
  run()
}

afterEach(() => {
  for (const [target, type, listener, options] of listening) target.removeEventListener(type, listener, options)
  document.body.innerHTML = ''
  vi.unstubAllGlobals()
})

const frameIn = (id: string) => document.querySelector<HTMLIFrameElement>(`#${id} iframe`)!
const popup = () => document.querySelector<HTMLElement>('.habitat-popup')
const popupFrame = () => popup()?.querySelector('iframe') ?? null

/** As one of the platform's frames would say it. */
function say(frame: HTMLIFrameElement, data: Record<string, unknown>, origin = BASE) {
  window.dispatchEvent(new MessageEvent('message', { data: { source: 'habitat-embed', ...data }, origin, source: frame.contentWindow }))
}

describe('the snippet on a client store page', () => {
  beforeEach(() => start())

  it("turns each tag into a frame of its piece, with the page's dates", () => {
    const dates = '?checkin=2027-06-01&checkout=2027-06-04&guests=2'
    expect(frameIn('search').src).toBe(`${BASE}/sites/harbour/embed/search${dates}`)
    expect(frameIn('grid').src).toBe(`${BASE}/sites/harbour/embed/homes${dates}`)
    expect(frameIn('box').src).toBe(`${BASE}/sites/harbour/embed/book/harbour-loft${dates}`)

    expect(frameIn('search').title).toBe('Search stays')
    expect(frameIn('grid').title).toBe('Homes')
    expect(frameIn('box').title).toBe('Book this home')
    expect(frameIn('box').getAttribute('allow')).toBe('payment')
  })

  it('sizes a frame to the height its page reports', () => {
    say(frameIn('grid'), { type: 'height', height: 812.4 })
    expect(frameIn('grid').style.height).toBe('813px')
  })

  it("keeps each frame's messages to that frame", () => {
    say(frameIn('grid'), { type: 'height', height: 500 })
    say(frameIn('grid2'), { type: 'height', height: 900 })

    expect(frameIn('grid').style.height).toBe('500px')
    expect(frameIn('grid2').style.height).toBe('900px')
  })

  it('ignores messages from another origin, or from no frame of its own', () => {
    say(frameIn('grid'), { type: 'height', height: 700 }, 'https://evil.test')
    expect(frameIn('grid').style.height).not.toBe('700px')

    window.dispatchEvent(new MessageEvent('message', {
      data: { source: 'habitat-embed', type: 'open', url: `${BASE}/sites/harbour/embed/stay/harbour-loft` },
      origin: BASE,
      source: window,
    }))
    expect(popup()).toBeNull()
  })

  it('opens the pop-up, and closes it with its button, with Esc, and when asked', () => {
    const url = `${BASE}/sites/harbour/embed/stay/harbour-loft?checkin=2027-06-01`

    say(frameIn('grid'), { type: 'open', url })
    expect(popupFrame()!.src).toBe(url)
    expect(popupFrame()!.getAttribute('allow')).toBe('payment')
    expect(document.body.style.overflow).toBe('hidden')

    popup()!.querySelector<HTMLButtonElement>('button[aria-label="Close"]')!.click()
    expect(popup()).toBeNull()
    expect(document.body.style.overflow).toBe('')

    say(frameIn('grid'), { type: 'open', url })
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(popup()).toBeNull()

    say(frameIn('grid'), { type: 'open', url })
    say(popupFrame()!, { type: 'close' })
    expect(popup()).toBeNull()
  })

  it('opens only pages of the same site on the platform', () => {
    say(frameIn('grid'), { type: 'open', url: 'https://evil.test/sites/harbour/embed/stay/x' })
    say(frameIn('grid'), { type: 'open', url: `${BASE}/sites/another/embed/stay/x` })
    say(frameIn('grid'), { type: 'open', url: `${BASE}/app/` })

    expect(popup()).toBeNull()
  })

  it('follows go only to an allowed https address', () => {
    say(frameIn('grid'), { type: 'ready', allowed: [STORE] })

    say(frameIn('grid'), { type: 'go', url: 'https://evil.test/products/loft' })
    say(frameIn('grid'), { type: 'go', url: 'http://shop.example.com/products/loft' })
    say(frameIn('grid'), { type: 'go', url: 'javascript:alert(1)' })
    expect(assign).not.toHaveBeenCalled()

    say(frameIn('grid'), { type: 'go', url: `${STORE}/products/loft?checkin=2027-06-01` })
    expect(assign).toHaveBeenCalledWith(`${STORE}/products/loft?checkin=2027-06-01`)
  })

  it('search reloads every grid with the dates', () => {
    say(frameIn('search'), { type: 'search', checkin: '2027-07-01', checkout: '2027-07-05', guests: '3' })

    const dates = '?checkin=2027-07-01&checkout=2027-07-05&guests=3'
    expect(frameIn('grid').src).toBe(`${BASE}/sites/harbour/embed/homes${dates}`)
    expect(frameIn('grid2').src).toBe(`${BASE}/sites/harbour/embed/homes${dates}`)
    expect(popup()).toBeNull()
  })
})

describe('a store page with a search bar and no grid', () => {
  beforeEach(() => start(`
    <script src="${BASE}/sites/embed.js" defer></script>
    <div id="search" data-habitat-site="harbour" data-habitat-piece="search"></div>`, ''))

  it('opens the results in the pop-up', () => {
    expect(frameIn('search').src).toBe(`${BASE}/sites/harbour/embed/search`)

    say(frameIn('search'), { type: 'search', checkin: '2027-07-01', checkout: '2027-07-05', guests: '' })

    expect(popupFrame()!.src).toBe(`${BASE}/sites/harbour/embed/results?checkin=2027-07-01&checkout=2027-07-05`)
  })
})
