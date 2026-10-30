import { describe, expect, it } from 'vitest'
import type { BlockDefinition, SiteContent } from '@/api/types'
import {
  addPage,
  createHistory,
  duplicateBlock,
  insertBlock,
  moveBlock,
  movePage,
  push,
  redo,
  removeBlock,
  removePage,
  undo,
  updateBlock,
  updatePage,
} from './document'

const DEFINITIONS: BlockDefinition[] = [
  {
    type: 'hero', label: 'Big photo + search', icon: 'image', description: '',
    fields: [
      { name: 'heading', kind: 'text', label: 'Heading', default: 'Find your place to stay', max: 120 },
      { name: 'show_search', kind: 'toggle', label: 'Show the search bar', default: true },
    ],
  },
  { type: 'text', label: 'Text', icon: 'type', description: '', fields: [{ name: 'heading', kind: 'text', label: 'Heading', default: '', max: 120 }] },
]

function doc(): SiteContent {
  return {
    schema: 1,
    theme: 'coastal',
    style: { primary: '#1f4e5f', accent: '#e07a5f', fonts: 'fraunces-inter', buttons: 'rounded' },
    pages: [{
      id: 'pg_home00000000', kind: 'home', title: 'Home', slug: '', in_menu: true,
      blocks: [
        { id: 'bl_a00000000000', type: 'text', background: 'plain', fields: { heading: 'A' } },
        { id: 'bl_b00000000000', type: 'text', background: 'plain', fields: { heading: 'B' } },
        { id: 'bl_c00000000000', type: 'text', background: 'plain', fields: { heading: 'C' } },
      ],
    }],
  }
}

const headings = (content: SiteContent) => content.pages[0]!.blocks.map((block) => block.fields.heading)

describe('the site document', () => {
  it('inserts a block with its defaults where it is asked for', () => {
    const next = insertBlock(doc(), 'pg_home00000000', 'hero', 1, DEFINITIONS)
    const block = next.pages[0]!.blocks[1]!

    expect(block.type).toBe('hero')
    expect(block.fields).toEqual({ heading: 'Find your place to stay', show_search: true })
    expect(block.background).toBe('plain')
    expect(block.id).toMatch(/^bl_[a-z0-9]{12}$/)
    expect(next.pages[0]!.blocks).toHaveLength(4)
  })

  it('moves a block and keeps every other', () => {
    expect(headings(moveBlock(doc(), 'pg_home00000000', 0, 2))).toEqual(['B', 'C', 'A'])
  })

  it('duplicates a block under a new id, just after it', () => {
    const next = duplicateBlock(doc(), 'pg_home00000000', 'bl_a00000000000')

    expect(headings(next)).toEqual(['A', 'A', 'B', 'C'])
    expect(next.pages[0]!.blocks[1]!.id).not.toBe('bl_a00000000000')
  })

  it('removes and updates blocks without touching the original document', () => {
    const original = doc()
    const removed = removeBlock(original, 'pg_home00000000', 'bl_b00000000000')
    const updated = updateBlock(removed, 'pg_home00000000', 'bl_c00000000000', { fields: { heading: 'See' } })

    expect(headings(updated)).toEqual(['A', 'See'])
    expect(headings(original)).toEqual(['A', 'B', 'C'])
  })

  it('adds pages with addresses of their own, and never removes Home', () => {
    let next = addPage(doc(), 'About us')
    next = addPage(next, 'About us')

    expect(next.pages.map((page) => page.slug)).toEqual(['', 'about-us', 'about-us-2'])
    expect(next.pages[1]!.kind).toBe('page')
    expect(next.pages[1]!.id).toMatch(/^pg_[a-z0-9]{12}$/)

    expect(removePage(next, 'pg_home00000000').pages).toHaveLength(3)
    expect(removePage(next, next.pages[2]!.id).pages).toHaveLength(2)
  })

  it('keeps Home first when pages are reordered', () => {
    const three = addPage(addPage(doc(), 'About'), 'FAQ')

    expect(movePage(three, 2, 1).pages.map((page) => page.title)).toEqual(['Home', 'FAQ', 'About'])
    expect(movePage(three, 1, 0).pages.map((page) => page.title)).toEqual(['Home', 'About', 'FAQ'])
  })

  it('renames a page and hides it from the menu, keeping its address', () => {
    const about = addPage(doc(), 'About')
    const renamed = updatePage(about, about.pages[1]!.id, { title: 'Our story', in_menu: false })
    expect(renamed.pages[1]).toMatchObject({ title: 'Our story', in_menu: false, slug: 'about' })
  })

  it('undoes and redoes, keeping fifty steps', () => {
    let history = createHistory(doc())
    history = push(history, moveBlock(history.present, 'pg_home00000000', 0, 2))
    history = push(history, removeBlock(history.present, 'pg_home00000000', 'bl_b00000000000'))

    expect(headings(history.present)).toEqual(['C', 'A'])
    history = undo(history)
    expect(headings(history.present)).toEqual(['B', 'C', 'A'])
    history = redo(history)
    expect(headings(history.present)).toEqual(['C', 'A'])

    // A new change after an undo drops what could have been redone.
    history = push(undo(history), doc())
    expect(history.future).toEqual([])

    for (let i = 0; i < 60; i++) history = push(history, updateBlock(history.present, 'pg_home00000000', 'bl_a00000000000', { fields: { heading: String(i) } }))
    expect(history.past).toHaveLength(50)
  })
})
