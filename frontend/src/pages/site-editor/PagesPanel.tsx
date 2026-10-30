import { useState } from 'react'
import { Eye, EyeOff, FileText, House, Pencil, Plus, Trash2 } from 'lucide-react'
import type { SitePage } from '@/api/types'
import { ConfirmDialog, EditorDialog } from './EditorDialog'
import { SortableList, SortableRow } from './SortableList'

/**
 * The site's pages, in the order its menu shows them. Home is always first.
 */
export function PagesPanel({
  pages,
  selectedId,
  onSelect,
  onMove,
  onAdd,
  onRename,
  onToggleMenu,
  onRemove,
}: {
  pages: SitePage[]
  selectedId: string
  onSelect: (pageId: string) => void
  onMove: (from: number, to: number) => void
  onAdd: (title: string) => void
  onRename: (pageId: string, title: string) => void
  onToggleMenu: (pageId: string, inMenu: boolean) => void
  onRemove: (pageId: string) => void
}) {
  const [naming, setNaming] = useState<{ pageId: string | null; title: string } | null>(null)
  const [removing, setRemoving] = useState<SitePage | null>(null)

  return (
    <section className="editor-panel" aria-label="Pages">
      <div className="editor-panel__head">
        <h3>Pages</h3>
        <button type="button" className="btn btn--ghost btn--sm" onClick={() => setNaming({ pageId: null, title: '' })} aria-label="Add page">
          <Plus size={14} aria-hidden /> Page
        </button>
      </div>

      <SortableList ids={pages.map((page) => page.id)} onMove={(from, to) => onMove(from, Math.max(1, to))} label="Pages">
        {pages.map((page) => {
          const home = page.kind === 'home'
          const Icon = home ? House : FileText

          return (
            <SortableRow key={page.id} id={page.id} handleLabel={`Drag ${page.title}`} disabled={home} selected={page.id === selectedId}>
              <button type="button" className="sortable__main" onClick={() => onSelect(page.id)} aria-current={page.id === selectedId ? 'page' : undefined}>
                <Icon size={15} aria-hidden />
                <span className="sortable__text">
                  <span>{page.title}</span>
                  {!home && <span className="small faint">/{page.slug}{page.in_menu ? '' : ' · not in the menu'}</span>}
                </span>
              </button>
              {!home && (
                <span className="sortable__actions">
                  <button
                    type="button"
                    className="btn btn--ghost btn--icon"
                    aria-label={page.in_menu ? `Hide ${page.title} from the menu` : `Show ${page.title} in the menu`}
                    onClick={() => onToggleMenu(page.id, !page.in_menu)}
                  >
                    {page.in_menu ? <Eye size={14} aria-hidden /> : <EyeOff size={14} aria-hidden />}
                  </button>
                  <button type="button" className="btn btn--ghost btn--icon" aria-label={`Rename ${page.title}`} onClick={() => setNaming({ pageId: page.id, title: page.title })}>
                    <Pencil size={14} aria-hidden />
                  </button>
                  <button type="button" className="btn btn--ghost btn--icon" aria-label={`Delete ${page.title}`} onClick={() => setRemoving(page)}>
                    <Trash2 size={14} aria-hidden />
                  </button>
                </span>
              )}
            </SortableRow>
          )
        })}
      </SortableList>

      {naming !== null && (
        <EditorDialog title={naming.pageId === null ? 'New page' : 'Rename page'} onClose={() => setNaming(null)}>
          <form
            className="stack"
            onSubmit={(event) => {
              event.preventDefault()
              const title = naming.title.trim()
              if (title === '') return

              if (naming.pageId === null) onAdd(title)
              else onRename(naming.pageId, title)
              setNaming(null)
            }}
          >
            <div className="field">
              <label className="field__label" htmlFor="new-page-name">Page name</label>
              <input
                id="new-page-name"
                className="input"
                autoFocus
                maxLength={60}
                value={naming.title}
                placeholder="About us"
                onChange={(event) => setNaming({ ...naming, title: event.target.value })}
              />
              <span className="field__hint">It shows in the menu and as the page's title.</span>
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="submit" className="btn btn--sm btn--primary">
                {naming.pageId === null ? 'Create page' : 'Rename'}
              </button>
            </div>
          </form>
        </EditorDialog>
      )}

      {removing !== null && (
        <ConfirmDialog
          title={`Delete ${removing.title}?`}
          body="The page and its blocks are removed from the draft. Undo brings them back until you leave the editor."
          confirmLabel="Yes, delete"
          onConfirm={() => onRemove(removing.id)}
          onClose={() => setRemoving(null)}
        />
      )}
    </section>
  )
}
