import { afterEach, describe, expect, it, vi } from 'vitest'
import SCRIPT from '../../../public/sites/embed/frame.js?raw'

/**
 * The script inside each framed page (public/sites/embed/frame.js), run on a
 * piece or a pop-up page. What it says to the store page around it is read
 * off that page's postMessage.
 */
const STORE = 'https://shop.example.com'
type Sent = { source: string; type: string; [key: string]: unknown }

let sent: { message: Sent; origin: string }[] = []
let listening: [EventTarget, string, EventListenerOrEventListenerObject, boolean | AddEventListenerOptions | undefined][] = []

function start(mode: 'piece' | 'popup', body: string) {
  document.documentElement.setAttribute('data-embed', mode)
  document.documentElement.setAttribute('data-allowed', JSON.stringify([STORE]))
  document.body.innerHTML = body
  sent = []
  listening = []
  const store = { postMessage: vi.fn((message: Sent, origin: string) => sent.push({ message, origin })) }
  vi.spyOn(window, 'parent', 'get').mockReturnValue(store as unknown as Window)
  for (const target of [window, document] as EventTarget[]) {
    const add = target.addEventListener.bind(target)
    vi.spyOn(target, 'addEventListener').mockImplementation((type, listener, options) => {
      if (listener !== null) listening.push([target, type, listener, options])
      add(type, listener, options)
    })
  }
  // eslint-disable-next-line @typescript-eslint/no-implied-eval
  const run = new Function(SCRIPT) as () => void
  run()
}

afterEach(() => {
  for (const [target, type, listener, options] of listening) target.removeEventListener(type, listener, options)
  document.body.innerHTML = ''
  document.documentElement.removeAttribute('data-embed')
  document.documentElement.removeAttribute('data-allowed')
})

const said = (type: string) => sent.filter(({ message }) => message.type === type)

function click(element: Element) {
  const event = new MouseEvent('click', { bubbles: true, cancelable: true })
  element.dispatchEvent(event)
  return event
}

describe('a framed page', () => {
  it('says it is ready, with the allowed addresses, and reports its height, to the store only', () => {
    start('piece', '<main><p>Homes</p></main>')

    expect(said('ready')).toEqual([{ message: { source: 'habitat-embed', type: 'ready', allowed: [STORE] }, origin: STORE }])
    expect(said('height')).not.toHaveLength(0)
    expect(typeof said('height')[0]!.message.height).toBe('number')
  })

  it('a card asks the store page to open the home in the pop-up', () => {
    start('piece', '<a href="https://habitat.test/sites/harbour/embed/stay/loft" data-habitat-open>Loft</a>')

    const event = click(document.querySelector('a')!)

    expect(event.defaultPrevented).toBe(true)
    expect(said('open').at(-1)!.message).toMatchObject({ url: 'https://habitat.test/sites/harbour/embed/stay/loft' })
  })

  it('a card for a home with a store page asks to go there', () => {
    start('popup', `<a href="${STORE}/products/loft" data-habitat-go>Loft</a>`)

    const event = click(document.querySelector('a')!)

    expect(event.defaultPrevented).toBe(true)
    expect(said('go').at(-1)!.message).toMatchObject({ url: `${STORE}/products/loft` })
  })

  it('Book in a booking box asks for checkout in the pop-up', () => {
    start('piece', `<a class="button" href="${window.location.origin}/sites/harbour/embed/checkout/01J0000000000000000000000">Book</a>`)

    const event = click(document.querySelector('a')!)

    expect(event.defaultPrevented).toBe(true)
    expect(said('open').at(-1)!.message).toMatchObject({ url: `${window.location.origin}/sites/harbour/embed/checkout/01J0000000000000000000000` })
  })

  it('in the pop-up, a link to the next page simply goes there', () => {
    start('popup', `<a href="${window.location.origin}/sites/harbour/embed/checkout/01J0000000000000000000000">Book</a>`)

    const event = click(document.querySelector('a')!)

    expect(event.defaultPrevented).toBe(false)
    expect(said('open')).toHaveLength(0)
  })

  it('the search bar sends its dates instead of loading', () => {
    start('piece', `
      <form data-habitat-search action="/sites/harbour/embed/results">
        <input name="checkin" value="2027-07-01"><input name="checkout" value="2027-07-05"><input name="guests" value="3">
      </form>`)

    const submit = new Event('submit', { bubbles: true, cancelable: true })
    document.querySelector('form')!.dispatchEvent(submit)

    expect(submit.defaultPrevented).toBe(true)
    expect(said('search').at(-1)!.message).toMatchObject({ checkin: '2027-07-01', checkout: '2027-07-05', guests: '3' })
  })

  it('Esc on a pop-up page asks to close', () => {
    start('popup', '<main></main>')

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))

    expect(said('close')).toHaveLength(1)
  })

  it('Esc in a field of the checkout leaves the pop-up open', () => {
    start('popup', '<form><input name="first_name"><textarea name="message"></textarea><select name="x"></select></form>')

    for (const field of ['input', 'textarea', 'select']) {
      document.querySelector(field)!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    }

    expect(said('close')).toHaveLength(0)
  })
})
