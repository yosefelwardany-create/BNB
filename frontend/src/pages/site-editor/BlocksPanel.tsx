import { useState } from 'react'
import { ArrowDown, ArrowUp, Copy, Plus, Trash2 } from 'lucide-react'
import type { BlockDefinition, SitePage } from '@/api/types'
import { blockIcon } from './blockIcons'
import { ConfirmDialog } from './EditorDialog'
import { SortableList, SortableRow } from './SortableList'

/**
 * The blocks of one page, top to bottom, as the page shows them: drag to
 * reorder, click to change, and add more.
 */
export function BlocksPanel({
  page,
  definitions,
  selectedId,
  onSelect,
  onMove,
  onDuplicate,
  onRemove,
  onAdd,
}: {
  page: SitePage
  definitions: BlockDefinition[]
  selectedId: string | null
  onSelect: (blockId: string) => void
  onMove: (from: number, to: number) => void
  onDuplicate: (blockId: string) => void
  onRemove: (blockId: string) => void
  onAdd: () => void
}) {
  const [removing, setRemoving] = useState<string | null>(null)
  const labelOf = (type: string) => definitions.find((definition) => definition.type === type)?.label ?? type

  return (
    <section className="editor-panel" aria-label={`Blocks on ${page.title}`}>
      <div className="editor-panel__head">
        <h3>Blocks on this page</h3>
      </div>

      {page.blocks.length === 0 ? (
        <p className="small muted">This page is empty. Add a block to start.</p>
      ) : (
        <SortableList ids={page.blocks.map((block) => block.id)} onMove={onMove} label={`Blocks on ${page.title}`}>
          {page.blocks.map((block, index) => {
            const definition = definitions.find((candidate) => candidate.type === block.type)
            const label = labelOf(block.type)
            const Icon = blockIcon(definition?.icon ?? '')
            const summary = summarise(block.fields)

            return (
              <SortableRow key={block.id} id={block.id} handleLabel={`Drag ${label}`} selected={block.id === selectedId}>
                <button type="button" className="sortable__main" aria-label={`Edit ${label}`} onClick={() => onSelect(block.id)}>
                  <Icon size={15} aria-hidden />
                  <span className="sortable__text">
                    <span>{label}</span>
                    {summary !== '' && <span className="small faint">{summary}</span>}
                  </span>
                </button>
                <span className="sortable__actions">
                  <button type="button" className="btn btn--ghost btn--icon" aria-label={`Move ${label} up`} disabled={index === 0} onClick={() => onMove(index, index - 1)}>
                    <ArrowUp size={14} aria-hidden />
                  </button>
                  <button
                    type="button"
                    className="btn btn--ghost btn--icon"
                    aria-label={`Move ${label} down`}
                    disabled={index === page.blocks.length - 1}
                    onClick={() => onMove(index, index + 1)}
                  >
                    <ArrowDown size={14} aria-hidden />
                  </button>
                  <button type="button" className="btn btn--ghost btn--icon" aria-label={`Duplicate ${label}`} onClick={() => onDuplicate(block.id)}>
                    <Copy size={14} aria-hidden />
                  </button>
                  <button type="button" className="btn btn--ghost btn--icon" aria-label={`Remove ${label}`} onClick={() => setRemoving(block.id)}>
                    <Trash2 size={14} aria-hidden />
                  </button>
                </span>
              </SortableRow>
            )
          })}
        </SortableList>
      )}

      <button type="button" className="btn btn--sm editor-panel__add" onClick={onAdd} aria-label="Add block">
        <Plus size={15} aria-hidden /> Add block
      </button>

      {removing !== null && (
        <ConfirmDialog
          title="Remove this block?"
          body="It disappears from the page. Undo brings it back."
          confirmLabel="Yes, remove"
          onConfirm={() => onRemove(removing)}
          onClose={() => setRemoving(null)}
        />
      )}
    </section>
  )
}

/** The block's own words, to tell two blocks of a kind apart. */
function summarise(fields: Record<string, unknown>): string {
  for (const key of ['heading', 'title', 'text']) {
    const value = fields[key]
    if (typeof value === 'string' && value.trim() !== '') return value.length > 40 ? `${value.slice(0, 40)}…` : value
  }

  return ''
}
