import type { SiteBlock, SiteContent } from '@/api/types'
import { setField } from './document'

export interface PreviewSync {
  /** What surrounds the blocks changed: the page is loaded again once saved. */
  reload: boolean
  /** Blocks of the page to render afresh and swap in place. */
  render: string[]
  /** The page's blocks in their new order, when blocks came, went or moved. */
  order: string[] | null
}

/** A field typed on the page itself, which already shows the new words. */
export interface Typed {
  block: string
  field: string
}

const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b)

/**
 * What the preview of one page needs after the document changed from
 * `before` to `after`.
 *
 * The theme, the style and the pages themselves (their names, addresses, the
 * menu, their order) are drawn around every block, so a change to any of them
 * reloads the page. Anything else is done in place: a block that changed is
 * rendered again — unless the only change is the words just typed into it on
 * the page — and the blocks are put in their new order.
 */
export function previewSync(before: SiteContent, after: SiteContent, pageId: string, typed?: Typed): PreviewSync {
  const around = (doc: SiteContent) => [doc.theme, doc.style, doc.pages.map(({ blocks: _blocks, ...page }) => page)]
  if (!same(around(before), around(after))) return { reload: true, render: [], order: null }

  const was = before.pages.find((page) => page.id === pageId)?.blocks ?? []
  const now = after.pages.find((page) => page.id === pageId)?.blocks ?? []
  const old = new Map(was.map((block) => [block.id, block]))

  const render = now
    .filter((block) => {
      const previous = old.get(block.id)
      if (previous === undefined) return true
      if (same(previous, block)) return false

      return !(typed !== undefined && typed.block === block.id && onlyTyped(previous, block, typed.field))
    })
    .map((block) => block.id)

  const ids = now.map((block) => block.id)

  return { reload: false, render, order: same(ids, was.map((block) => block.id)) ? null : ids }
}

/** Whether the block differs from what it was only in the one field typed. */
function onlyTyped(previous: SiteBlock, block: SiteBlock, field: string): boolean {
  const typedValue = field.split('.').reduce<unknown>(
    (value, name) => (typeof value === 'object' && value !== null ? (value as Record<string, unknown>)[name] : undefined),
    block.fields,
  )

  return same({ ...previous, fields: setField(previous.fields, field, typedValue) }, block)
}
