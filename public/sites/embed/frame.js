/*
 * Inside a site's page shown in a frame on a client's store.
 *
 * Tells the store page around it (public/sites/embed.js) what it needs: its
 * height, a page to open in the pop-up, a page of the store to go to, the
 * dates of a search, and to close the pop-up. Says it only to the store's
 * own addresses, and says nothing about the guest.
 *
 * A page opened on its own, outside a frame, works as an ordinary page.
 */
;(function () {
  'use strict'

  var SOURCE = 'habitat-embed'
  var root = document.documentElement
  var mode = root.getAttribute('data-embed')
  var store = window.parent

  if (!mode || store === window) return

  var allowed = []
  try {
    var listed = JSON.parse(root.getAttribute('data-allowed') || '[]')
    allowed = Array.isArray(listed) ? listed.filter(function (origin) { return typeof origin === 'string' }) : []
  } catch (e) {
    allowed = []
  }

  function send(type, data) {
    var message = Object.assign({ source: SOURCE, type: type }, data || {})
    // Delivered only if the page around the frame is one of the store's.
    allowed.forEach(function (origin) {
      store.postMessage(message, origin)
    })
  }

  // ------------------------------------------------------------ Height

  var last = -1

  function measure() {
    var height = Math.ceil(document.body ? document.body.getBoundingClientRect().height : 0)
    if (height === last) return
    last = height
    send('height', { height: height })
  }

  send('ready', { allowed: allowed })
  measure()
  window.addEventListener('load', measure)
  if (typeof ResizeObserver === 'function' && document.body) new ResizeObserver(measure).observe(document.body)

  // ------------------------------------------------------------ Links

  function isPopupPage(link) {
    return link.origin === window.location.origin && link.pathname.indexOf('/embed/') !== -1
  }

  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return

    var link = event.target instanceof Element ? event.target.closest('a[href]') : null
    if (link === null) return

    if (link.hasAttribute('data-habitat-go')) {
      event.preventDefault()
      send('go', { url: link.href })
    } else if (link.hasAttribute('data-habitat-open') || (mode === 'piece' && isPopupPage(link))) {
      // A piece is small: a home's page, and Book in a booking box, open in
      // the pop-up over the store. In the pop-up, links simply go on.
      event.preventDefault()
      send('open', { url: link.href })
    }
  })

  // ------------------------------------------------------------ Search

  document.addEventListener('submit', function (event) {
    var form = event.target
    if (mode !== 'piece' || !(form instanceof HTMLFormElement) || !form.hasAttribute('data-habitat-search')) return

    event.preventDefault()
    var value = function (name) {
      return form.elements[name] ? String(form.elements[name].value || '') : ''
    }
    send('search', { checkin: value('checkin'), checkout: value('checkout'), guests: value('guests') })
  })

  // ------------------------------------------------------------ Closing

  document.addEventListener('keydown', function (event) {
    if (mode === 'popup' && event.key === 'Escape') send('close')
  })
})()
