import type { BlockDefinition, SiteBlock, SiteContent, SitePage } from '@/api/types'

/*
 * The site's document as the page builder changes it.
 *
 * Every function returns a new document and leaves the one it was given
 * alone, so the undo history can keep each step as it was. The server checks
 * the whole document again on every save; these only keep it in shape.
 */

/** A new id for a page (`pg_`) or a block (`bl_`), as the server expects them. */
export function newId(prefix: 'pg_' | 'bl_'): string {
  const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789'
  let id = prefix

  for (let i = 0; i < 12; i++) id += alphabet[Math.floor(Math.random() * alphabet.length)]

  return id
}

function withPage(doc: SiteContent, pageId: string, change: (page: SitePage) => SitePage): SiteContent {
  return { ...doc, pages: doc.pages.map((page) => (page.id === pageId ? change(page) : page)) }
}

function move<T>(items: T[], from: number, to: number): T[] {
  if (from === to || from < 0 || from >= items.length) return items

  const next = [...items]
  const [item] = next.splice(from, 1)
  next.splice(Math.max(0, Math.min(to, next.length)), 0, item as T)

  return next
}

/** A block of a type at its defaults, placed at `index` on the page. */
export function insertBlock(doc: SiteContent, pageId: string, type: string, index: number, definitions: BlockDefinition[]): SiteContent {
  const definition = definitions.find((candidate) => candidate.type === type)
  if (definition === undefined) return doc

  const block: SiteBlock = {
    id: newId('bl_'),
    type,
    background: 'plain',
    fields: Object.fromEntries(definition.fields.map((field) => [field.name, structuredClone(field.default)])),
  }

  return withPage(doc, pageId, (page) => {
    const blocks = [...page.blocks]
    blocks.splice(Math.max(0, Math.min(index, blocks.length)), 0, block)

    return { ...page, blocks }
  })
}

export function moveBlock(doc: SiteContent, pageId: string, from: number, to: number): SiteContent {
  return withPage(doc, pageId, (page) => ({ ...page, blocks: move(page.blocks, from, to) }))
}

export function duplicateBlock(doc: SiteContent, pageId: string, blockId: string): SiteContent {
  return withPage(doc, pageId, (page) => {
    const index = page.blocks.findIndex((block) => block.id === blockId)
    const original = page.blocks[index]
    if (original === undefined) return page

    const blocks = [...page.blocks]
    blocks.splice(index + 1, 0, { ...structuredClone(original), id: newId('bl_') })

    return { ...page, blocks }
  })
}

export function removeBlock(doc: SiteContent, pageId: string, blockId: string): SiteContent {
  return withPage(doc, pageId, (page) => ({ ...page, blocks: page.blocks.filter((block) => block.id !== blockId) }))
}

/** Change a block's background or some of its fields, keeping the rest. */
export function updateBlock(
  doc: SiteContent,
  pageId: string,
  blockId: string,
  patch: { background?: SiteBlock['background']; fields?: Record<string, unknown> },
): SiteContent {
  return withPage(doc, pageId, (page) => ({
    ...page,
    blocks: page.blocks.map((block) =>
      block.id === blockId
        ? { ...block, background: patch.background ?? block.background, fields: { ...block.fields, ...patch.fields } }
        : block,
    ),
  }))
}

/** A page's address from its name: lowercase words joined by hyphens. */
export function slugify(title: string): string {
  const slug = title
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 50)

  return slug === '' ? 'page' : slug
}

/** Addresses every site already uses for its own pages. */
const RESERVED = ['search', 'stay', 'checkout', 'booking', 'api', 'photos', 'media', 'images', 'preview']

/** A new page, its address made from its name and kept unique. */
export function addPage(doc: SiteContent, title: string): SiteContent {
  const taken = new Set(doc.pages.map((page) => page.slug))
  const base = slugify(title)
  let slug = RESERVED.includes(base) ? `${base}-page` : base

  for (let n = 2; taken.has(slug); n++) slug = `${base}-${n}`

  const page: SitePage = { id: newId('pg_'), kind: 'page', title: title.trim() || 'New page', slug, in_menu: true, blocks: [] }

  return { ...doc, pages: [...doc.pages, page] }
}

/** Reorder pages; Home stays first whatever is asked. */
export function movePage(doc: SiteContent, from: number, to: number): SiteContent {
  if (from === 0) return doc

  return { ...doc, pages: move(doc.pages, from, Math.max(1, to)) }
}

export function updatePage(doc: SiteContent, pageId: string, patch: Partial<Pick<SitePage, 'title' | 'slug' | 'in_menu'>>): SiteContent {
  return withPage(doc, pageId, (page) => ({ ...page, ...patch }))
}

/** Remove a page — never Home. */
export function removePage(doc: SiteContent, pageId: string): SiteContent {
  return { ...doc, pages: doc.pages.filter((page) => page.kind === 'home' || page.id !== pageId) }
}

// ---------------------------------------------------------------- History

const LIMIT = 50

export interface History {
  past: SiteContent[]
  present: SiteContent
  future: SiteContent[]
}

export function createHistory(doc: SiteContent): History {
  return { past: [], present: doc, future: [] }
}

/** A new step. What could have been redone is gone. */
export function push(history: History, doc: SiteContent): History {
  if (doc === history.present) return history

  return { past: [...history.past, history.present].slice(-LIMIT), present: doc, future: [] }
}

export function undo(history: History): History {
  const previous = history.past[history.past.length - 1]
  if (previous === undefined) return history

  return { past: history.past.slice(0, -1), present: previous, future: [history.present, ...history.future] }
}

export function redo(history: History): History {
  const [next, ...rest] = history.future
  if (next === undefined) return history

  return { past: [...history.past, history.present].slice(-LIMIT), present: next, future: rest }
}
