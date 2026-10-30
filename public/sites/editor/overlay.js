/*
 * The page builder's hand on the page.
 *
 * Loaded only into the editor's preview of a draft, never into a guest's
 * page. It outlines blocks, lets their plain words be typed in place, and
 * moves them; the editor, in the window around this frame, keeps the
 * document and answers with blocks rendered afresh. The two speak by
 * postMessage, each only to the other, on this one origin.
 *
 * The overlay never changes the document itself: it says what was asked and
 * shows what the editor sends back.
 */
;(function () {
  'use strict'

  var FROM = 'habitat-overlay'
  var TO = 'habitat-editor'
  var origin = window.location.origin
  var editor = window.parent

  function send(type, data) {
    editor.postMessage(Object.assign({ source: FROM, type: type }, data || {}), origin)
  }

  function blocks() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-block-id]'))
  }

  function blockOf(node) {
    return node instanceof Element ? node.closest('[data-block-id]') : null
  }

  function find(id) {
    return blocks().filter(function (node) { return node.dataset.blockId === id })[0] || null
  }

  function indexOf(id) {
    return blocks().map(function (node) { return node.dataset.blockId }).indexOf(id)
  }

  function button(label, className, text) {
    var element = document.createElement('button')
    element.type = 'button'
    element.className = className
    element.setAttribute('aria-label', label)
    element.title = label
    element.textContent = text
    return element
  }

  document.documentElement.classList.add('hx-editing')

  // ------------------------------------------------------------ The toolbar

  // One toolbar and two "+" buttons, moved to whichever block is under the
  // pointer: nothing is added between the blocks, so the page keeps the
  // layout guests see.
  var toolbar = document.createElement('div')
  toolbar.className = 'hx-toolbar'
  toolbar.setAttribute('role', 'toolbar')
  toolbar.setAttribute('aria-label', 'Block')
  var handle = button('Drag to move', 'hx-handle', '⋮⋮')
  var duplicate = button('Duplicate block', 'hx-tool', '⧉')
  var remove = button('Remove block', 'hx-tool', '✕')
  toolbar.append(handle, duplicate, remove)

  var above = button('Add a block above', 'hx-add hx-add--above', '+')
  var below = button('Add a block below', 'hx-add hx-add--below', '+')

  var tools = [toolbar, above, below]
  tools.forEach(function (tool) {
    tool.hidden = true
    document.body.appendChild(tool)
  })

  var current = null

  function place(node) {
    current = node
    var id = node ? node.dataset.blockId : ''
    toolbar.dataset.block = id
    tools.forEach(function (tool) { tool.hidden = node === null })
    if (node === null) return

    var box = node.getBoundingClientRect()
    var top = box.top + window.scrollY
    toolbar.style.top = top + 8 + 'px'
    toolbar.style.left = box.right + window.scrollX - 8 + 'px'
    above.style.top = top + 'px'
    below.style.top = top + box.height + 'px'
    above.style.left = below.style.left = box.left + window.scrollX + box.width / 2 + 'px'
  }

  document.addEventListener('mouseover', function (event) {
    if (dragging !== null || tools.some(function (tool) { return tool.contains(event.target) })) return
    var node = blockOf(event.target)
    if (node !== null && node !== current) place(node)
  })

  duplicate.addEventListener('click', function () {
    if (current) send('duplicate', { block: current.dataset.blockId })
  })
  remove.addEventListener('click', function () {
    if (current) send('remove', { block: current.dataset.blockId })
  })
  above.addEventListener('click', function () {
    if (current) send('insert-at', { index: indexOf(current.dataset.blockId) })
  })
  below.addEventListener('click', function () {
    if (current) send('insert-at', { index: indexOf(current.dataset.blockId) + 1 })
  })

  // ------------------------------------------------------------ Selecting

  function select(id) {
    blocks().forEach(function (node) { node.classList.toggle('hx-selected', node.dataset.blockId === id) })
  }

  // Links and forms go nowhere while editing: a click on a button's words
  // is to change them, not to follow it.
  document.addEventListener('click', function (event) {
    if (tools.some(function (tool) { return tool.contains(event.target) })) return
    // A question still opens as guests open it; it is not edited here.
    if (event.target instanceof Element && event.target.closest('a, button')) event.preventDefault()

    var node = blockOf(event.target)
    if (node === null) return
    select(node.dataset.blockId)
    send('select', { block: node.dataset.blockId })
  }, true)

  document.addEventListener('submit', function (event) {
    event.preventDefault()
  }, true)

  // ------------------------------------------------------------ Typing

  function textOf(field) {
    var text = typeof field.innerText === 'string' ? field.innerText : field.textContent || ''
    return field.hasAttribute('data-multiline') ? text.replace(/\n$/, '') : text.replace(/\s*\n\s*/g, ' ')
  }

  function bind(root) {
    Array.prototype.forEach.call(root.querySelectorAll('[data-field]'), function (field) {
      field.setAttribute('contenteditable', 'plaintext-only')
      // A browser that cannot type plain text only types formatted text,
      // which the paste handler and textOf bring back to words.
      if (typeof field.contentEditable === 'string' && field.contentEditable !== 'plaintext-only') {
        field.setAttribute('contenteditable', 'true')
      }
      field.spellcheck = true
    })
  }

  document.addEventListener('input', function (event) {
    var field = event.target instanceof Element ? event.target.closest('[data-field]') : null
    var node = blockOf(field)
    if (field === null || node === null) return

    var value = textOf(field)
    // Emptied: nothing left of a browser's stray <br> or space, so the field
    // is empty to the draft and shows its placeholder again.
    if (value.trim() === '') {
      value = ''
      if (field.innerHTML !== '') field.innerHTML = ''
    }

    send('text', { block: node.dataset.blockId, field: field.dataset.field, value: value })
  })

  document.addEventListener('paste', function (event) {
    var field = event.target instanceof Element ? event.target.closest('[data-field]') : null
    if (field === null || !event.clipboardData) return

    event.preventDefault()
    document.execCommand('insertText', false, event.clipboardData.getData('text/plain'))
  })

  document.addEventListener('keydown', function (event) {
    var field = event.target instanceof Element ? event.target.closest('[data-field]') : null

    if (field !== null && event.key === 'Enter' && !field.hasAttribute('data-multiline')) {
      event.preventDefault()
      field.blur()
      return
    }

    if (dragging !== null && event.key === 'Escape') {
      stopDragging()
      return
    }

    // Undo and redo belong to the editor; inside a field, to the field.
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z' && field === null) {
      event.preventDefault()
      send(event.shiftKey ? 'redo' : 'undo')
    }
  })

  // A file dropped anywhere but a photo field would open in place of the
  // editor; on the page it is simply refused.
  function carriesFiles(event) {
    return !!event.dataTransfer && Array.prototype.indexOf.call(event.dataTransfer.types || [], 'Files') !== -1
  }

  document.addEventListener('dragover', function (event) {
    if (carriesFiles(event)) event.preventDefault()
  })
  document.addEventListener('drop', function (event) {
    if (carriesFiles(event)) event.preventDefault()
  })

  // ------------------------------------------------------------ Dragging

  var dragging = null
  var line = null

  // Where a block dropped at this height would go: the gap before the first
  // block whose middle is below the pointer.
  function gapAt(y) {
    var all = blocks()
    for (var i = 0; i < all.length; i++) {
      var box = all[i].getBoundingClientRect()
      if (y < box.top + box.height / 2) return i
    }
    return all.length
  }

  function stopDragging() {
    dragging = null
    if (line !== null) line.remove()
    line = null
  }

  handle.addEventListener('pointerdown', function (event) {
    if (current === null) return
    event.preventDefault()
    dragging = { block: current.dataset.blockId, gap: indexOf(current.dataset.blockId) }
  })

  document.addEventListener('pointermove', function (event) {
    if (dragging === null) return

    dragging.gap = gapAt(event.clientY)
    if (line === null) {
      line = document.createElement('div')
      line.className = 'hx-drop'
      document.body.appendChild(line)
    }

    var all = blocks()
    var edge = dragging.gap < all.length
      ? all[dragging.gap].getBoundingClientRect().top
      : all[all.length - 1].getBoundingClientRect().bottom
    line.style.top = edge + window.scrollY + 'px'
  })

  document.addEventListener('pointerup', function () {
    if (dragging === null) return

    var from = indexOf(dragging.block)
    var to = dragging.gap > from ? dragging.gap - 1 : dragging.gap
    var id = dragging.block
    stopDragging()
    if (to !== from) send('move', { block: id, to: to })
  })

  // ------------------------------------------------------------ The editor

  window.addEventListener('message', function (event) {
    var data = event.data
    if (event.source !== editor || event.origin !== origin || !data || data.source !== TO) return

    if (data.type === 'replace' && typeof data.block === 'string' && typeof data.html === 'string') {
      var old = find(data.block)
      // Not under the hands of someone typing in it: taken in once they leave.
      if (old !== null && old.contains(document.activeElement)) {
        waiting[data.block] = data.html
        return
      }
      swap(data.block, data.html)
    } else if (data.type === 'order' && Array.isArray(data.blocks)) {
      var keep = data.blocks.map(find)
      blocks().forEach(function (node) {
        if (keep.indexOf(node) === -1) node.remove()
      })
      var parent = container()
      keep.forEach(function (node) {
        if (node !== null) parent.appendChild(node)
      })
      removeEmptyNote()
      if (current !== null && !current.isConnected) place(null)
    } else if (data.type === 'select') {
      var chosen = typeof data.block === 'string' ? find(data.block) : null
      // Chosen on the page itself, it is where the pointer already is.
      var already = chosen !== null && chosen.classList.contains('hx-selected')
      select(typeof data.block === 'string' ? data.block : null)
      if (chosen !== null && !already && typeof chosen.scrollIntoView === 'function') chosen.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
    }
  })

  // New HTML for blocks that were being typed in, by block.
  var waiting = {}

  function swap(id, html) {
    var template = document.createElement('template')
    template.innerHTML = html.trim()
    var fresh = template.content.firstElementChild
    if (fresh === null) return

    var old = find(id)
    if (old !== null) {
      if (old.classList.contains('hx-selected')) fresh.classList.add('hx-selected')
      old.replaceWith(fresh)
    } else {
      container().appendChild(fresh)
      removeEmptyNote()
    }
    bind(fresh)
    if (current === old) place(fresh)
  }

  document.addEventListener('focusout', function (event) {
    var node = blockOf(event.target)
    if (node === null || !(node.dataset.blockId in waiting)) return
    if (event.relatedTarget instanceof Node && node.contains(event.relatedTarget)) return

    var id = node.dataset.blockId
    var html = waiting[id]
    delete waiting[id]

    // The words typed while it waited are in the draft already; the page
    // keeps showing them rather than the older ones the HTML was made from.
    var typed = {}
    Array.prototype.forEach.call(node.querySelectorAll('[data-field]'), function (field) {
      if (field === event.target) typed[field.dataset.field] = field.innerHTML
    })
    swap(id, html)
    var fresh = find(id)
    if (fresh === null) return
    Object.keys(typed).forEach(function (name) {
      var field = fresh.querySelector('[data-field="' + name.replace(/"/g, '') + '"]')
      if (field !== null) field.innerHTML = typed[name]
    })
  })

  function removeEmptyNote() {
    if (blocks().length === 0) return
    Array.prototype.forEach.call(document.querySelectorAll('[data-empty-note]'), function (note) { note.remove() })
  }

  // Where the page's blocks live.
  function container() {
    var first = blocks()[0]
    return first ? first.parentElement : document.querySelector('main') || document.body
  }

  bind(document)
  send('ready')
})()
