import { describe, expect, it } from 'vitest'
import type { SiteContent } from '@/api/types'
import { moveBlock, removeBlock, updateBlock, updatePage } from './document'
import { previewSync } from './previewSync'

/**
 * What the preview needs after a change: blocks re-rendered in place, a new
 * order, or a reload when what changed is around the blocks.
 */
const HOME = 'pg_home00000000'
const ABOUT = 'pg_about0000000'

function doc(): SiteContent {
  return {
    schema: 1,
    theme: 'coastal',
    style: { primary: '#1f4e5f', accent: '#e07a5f', fonts: 'fraunces-inter', buttons: 'rounded' },
    pages: [
      {
        id: HOME, kind: 'home', title: 'Home', slug: '', in_menu: true,
        blocks: [
          { id: 'bl_hero00000000', type: 'hero', background: 'plain', fields: { heading: 'Sea', tagline: '' } },
          { id: 'bl_text00000000', type: 'text', background: 'plain', fields: { heading: 'About', body: '' } },
          { id: 'bl_cta000000000', type: 'cta', background: 'brand', fields: { text: 'Book', button: { label: 'Go', kind: 'search', target: '' } } },
        ],
      },
      {
        id: ABOUT, kind: 'page', title: 'About', slug: 'about', in_menu: true,
        blocks: [{ id: 'bl_story0000000', type: 'text', background: 'plain', fields: { heading: 'Our story', body: '' } }],
      },
    ],
  }
}

const NOTHING = { reload: false, render: [], order: null }

describe('previewSync', () => {
  it('leaves alone a field just typed on the page', () => {
    const before = doc()
    const after = updateBlock(before, HOME, 'bl_hero00000000', { fields: { heading: 'Sea and sun' } })

    expect(previewSync(before, after, HOME, { block: 'bl_hero00000000', field: 'heading' })).toEqual(NOTHING)
  })

  it('leaves alone a button label just typed on the page', () => {
    const before = doc()
    const after = updateBlock(before, HOME, 'bl_cta000000000', { fields: { button: { label: 'Book now', kind: 'search', target: '' } } })

    expect(previewSync(before, after, HOME, { block: 'bl_cta000000000', field: 'button.label' })).toEqual(NOTHING)
  })

  it('re-renders a block changed any other way', () => {
    const before = doc()
    const heading = updateBlock(before, HOME, 'bl_hero00000000', { fields: { heading: 'Sea and sun' } })
    expect(previewSync(before, heading, HOME)).toEqual({ reload: false, render: ['bl_hero00000000'], order: null })

    // Typed on the page, and another field of it changed too.
    const both = updateBlock(heading, HOME, 'bl_hero00000000', { background: 'tint' })
    expect(previewSync(before, both, HOME, { block: 'bl_hero00000000', field: 'heading' }).render).toEqual(['bl_hero00000000'])
  })

  it('orders blocks moved or removed, and renders one added', () => {
    const before = doc()

    expect(previewSync(before, moveBlock(before, HOME, 0, 2), HOME))
      .toEqual({ reload: false, render: [], order: ['bl_text00000000', 'bl_cta000000000', 'bl_hero00000000'] })

    expect(previewSync(before, removeBlock(before, HOME, 'bl_text00000000'), HOME))
      .toEqual({ reload: false, render: [], order: ['bl_hero00000000', 'bl_cta000000000'] })

    const added = structuredClone(before)
    added.pages[0]!.blocks.splice(1, 0, { id: 'bl_new000000000', type: 'spacer', background: 'plain', fields: {} })
    expect(previewSync(before, added, HOME))
      .toEqual({ reload: false, render: ['bl_new000000000'], order: ['bl_hero00000000', 'bl_new000000000', 'bl_text00000000', 'bl_cta000000000'] })
  })

  it('reloads for what is around the blocks: theme, style, pages and the menu', () => {
    const before = doc()

    expect(previewSync(before, { ...before, theme: 'bold' }, HOME).reload).toBe(true)
    expect(previewSync(before, { ...before, style: { ...before.style, primary: '#000000' } }, HOME).reload).toBe(true)
    expect(previewSync(before, updatePage(before, ABOUT, { title: 'Our story' }), HOME).reload).toBe(true)
    expect(previewSync(before, updatePage(before, ABOUT, { in_menu: false }), HOME).reload).toBe(true)
    expect(previewSync(before, { ...before, pages: [before.pages[0]!] }, HOME).reload).toBe(true)
  })

  it('does nothing for a change on another page', () => {
    const before = doc()

    expect(previewSync(before, updateBlock(before, ABOUT, 'bl_story0000000', { fields: { heading: 'Since 1990' } }), HOME)).toEqual(NOTHING)
  })
})
