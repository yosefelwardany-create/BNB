/*
 * A booking site's pieces on a client's own store.
 *
 * The store loads this once, and places tags where the pieces go:
 *
 *   <script src="https://…/sites/embed.js" defer></script>
 *   <div data-habitat-site="harbour" data-habitat-piece="search"></div>
 *   <div data-habitat-site="harbour" data-habitat-piece="homes"></div>
 *   <div data-habitat-site="harbour" data-habitat-piece="book" data-habitat-home="harbour-loft"></div>
 *
 * Each tag becomes a frame of a page on the platform, drawn in the site's
 * theme. The frames say what they need by postMessage: their height, a page
 * to open in the pop-up over the store, a page of the store to go to, the
 * dates of a search. This script listens only to its own frames, and only
 * from the platform's address; the store page is never read or changed
 * beyond the tags and the pop-up.
 */
;(function () {
  'use strict'

  var SOURCE = 'habitat-embed'
  var SITE = /^[a-z0-9]+(?:-[a-z0-9]+)*$/
  var HOME = /^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/
  var TITLES = { search: 'Search stays', homes: 'Homes', book: 'Book this home' }

  var script = document.currentScript || document.querySelector('script[src$="/sites/embed.js"], script[src*="/sites/embed.js?"]')
  if (!script) return

  var base
  try {
    base = new URL(script.getAttribute('src'), window.location.href).origin
  } catch (e) {
    return
  }

  // Every frame this script made: the pieces, and the pop-up while it is open.
  var frames = []
  var popup = null
  var overflow = ''

  // ------------------------------------------------------------ Addresses

  /** The dates and guests a store page's own address carries, to pass on. */
  function datesOf(source) {
    var out = new URLSearchParams()
    ;['checkin', 'checkout'].forEach(function (name) {
      var value = source[name]
      if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) out.set(name, value)
    })
    if (typeof source.guests === 'string' && /^\d{1,2}$/.test(source.guests)) out.set('guests', source.guests)
    return out
  }

  function pageDates() {
    var params = new URLSearchParams(window.location.search)
    return datesOf({ checkin: params.get('checkin'), checkout: params.get('checkout'), guests: params.get('guests') })
  }

  function pieceUrl(site, piece, home, dates) {
    var query = dates.toString()
    return base + '/sites/' + site + '/embed/' + piece + (home ? '/' + home : '') + (query ? '?' + query : '')
  }

  function parse(url) {
    try {
      return new URL(url)
    } catch (e) {
      return null
    }
  }

  /** A page of this site's pieces on the platform: the only kind the pop-up shows. */
  function isSitePage(url, site) {
    var parsed = parse(url)
    return parsed !== null && parsed.origin === base && parsed.pathname.indexOf('/sites/' + site + '/embed/') === 0
  }

  /** A page of the store itself, by the addresses the site lists for it. */
  function isStorePage(url, allowed) {
    var parsed = parse(url)
    return parsed !== null && parsed.protocol === 'https:' && allowed.indexOf(parsed.origin) !== -1
  }

  // ------------------------------------------------------------ Frames

  function frame(src, title) {
    var element = document.createElement('iframe')
    element.src = src
    element.title = title
    element.setAttribute('allow', 'payment')
    element.style.border = '0'
    element.style.width = '100%'
    element.style.display = 'block'
    element.style.overflow = 'hidden'
    return element
  }

  function place(tag) {
    var site = tag.getAttribute('data-habitat-site') || ''
    var piece = tag.getAttribute('data-habitat-piece') || ''
    var home = tag.getAttribute('data-habitat-home') || ''

    if (!SITE.test(site) || !TITLES.hasOwnProperty(piece)) return
    if (piece === 'book' && !HOME.test(home)) return

    var element = frame(pieceUrl(site, piece, piece === 'book' ? home : '', pageDates()), TITLES[piece])
    element.style.height = piece === 'search' ? '120px' : '480px'
    tag.innerHTML = ''
    tag.appendChild(element)
    frames.push({ element: element, site: site, piece: piece, allowed: [], popup: false })
  }

  function recordFor(source) {
    var all = popup ? frames.concat([popup.record]) : frames
    for (var i = 0; i < all.length; i++) {
      if (all[i].element.contentWindow === source) return all[i]
    }
    return null
  }

  // ------------------------------------------------------------ The pop-up

  function openPopup(site, url) {
    closePopup()

    var overlay = document.createElement('div')
    overlay.className = 'habitat-popup'
    overlay.setAttribute('role', 'dialog')
    overlay.setAttribute('aria-modal', 'true')
    overlay.setAttribute('aria-label', 'Stay details')
    overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box'

    var panel = document.createElement('div')
    panel.style.cssText = 'position:relative;width:100%;max-width:1100px;height:100%;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.35)'

    var close = document.createElement('button')
    close.type = 'button'
    close.setAttribute('aria-label', 'Close')
    close.textContent = '×'
    close.style.cssText = 'position:absolute;top:8px;right:8px;z-index:1;width:36px;height:36px;border:0;border-radius:50%;background:#fff;color:#111;font:24px/36px sans-serif;cursor:pointer;box-shadow:0 1px 4px rgba(0,0,0,.25)'

    var element = frame(url, 'Stay details')
    element.style.height = '100%'

    panel.appendChild(element)
    panel.appendChild(close)
    overlay.appendChild(panel)
    document.body.appendChild(overlay)

    overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    close.addEventListener('click', closePopup)
    overlay.addEventListener('click', function (event) {
      if (event.target === overlay) closePopup()
    })

    popup = { overlay: overlay, record: { element: element, site: site, piece: 'popup', allowed: [], popup: true } }
    close.focus()
  }

  function closePopup() {
    if (popup === null) return
    popup.overlay.remove()
    popup = null
    document.body.style.overflow = overflow
  }

  // ------------------------------------------------------------ Messages

  function search(site, data) {
    var dates = datesOf(data)
    var grids = frames.filter(function (record) {
      return record.site === site && record.piece === 'homes'
    })

    if (grids.length === 0) {
      openPopup(site, pieceUrl(site, 'results', '', dates))
      return
    }

    grids.forEach(function (record) {
      record.element.src = pieceUrl(site, 'homes', '', dates)
    })
  }

  window.addEventListener('message', function (event) {
    var data = event.data
    if (event.origin !== base || !data || typeof data !== 'object' || data.source !== SOURCE) return

    var from = recordFor(event.source)
    if (from === null) return

    if (data.type === 'ready') {
      from.allowed = Array.isArray(data.allowed) ? data.allowed.filter(function (origin) { return typeof origin === 'string' }) : []
    } else if (data.type === 'height') {
      if (!from.popup && typeof data.height === 'number' && isFinite(data.height)) {
        from.element.style.height = Math.ceil(Math.min(Math.max(data.height, 0), 20000)) + 'px'
      }
    } else if (data.type === 'open') {
      if (typeof data.url === 'string' && isSitePage(data.url, from.site)) openPopup(from.site, data.url)
    } else if (data.type === 'go') {
      if (typeof data.url === 'string' && isStorePage(data.url, from.allowed)) window.location.assign(data.url)
    } else if (data.type === 'search') {
      search(from.site, data)
    } else if (data.type === 'close') {
      if (from.popup) closePopup()
    }
  })

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closePopup()
  })

  Array.prototype.forEach.call(document.querySelectorAll('[data-habitat-site][data-habitat-piece]'), place)
})()
