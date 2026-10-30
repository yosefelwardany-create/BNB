import type { ReactNode } from 'react'
import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core'
import { SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { GripVertical } from 'lucide-react'

/**
 * A list whose rows are dragged into order — by mouse, finger, or from the
 * keyboard (focus a handle, Space, the arrow keys, Space).
 */
export function SortableList({
  ids,
  onMove,
  label,
  children,
}: {
  ids: string[]
  onMove: (from: number, to: number) => void
  label: string
  children: ReactNode
}) {
  const sensors = useSensors(
    // A small threshold, so a click on a row still selects it.
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )

  function onDragEnd(event: DragEndEvent) {
    const from = ids.indexOf(String(event.active.id))
    const to = event.over === null ? -1 : ids.indexOf(String(event.over.id))

    if (from !== -1 && to !== -1 && from !== to) onMove(from, to)
  }

  return (
    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd} accessibility={{ screenReaderInstructions: { draggable: `To move a row in ${label}, press Space, use the arrow keys, then press Space again.` } }}>
      <SortableContext items={ids} strategy={verticalListSortingStrategy}>
        <ul className="sortable" aria-label={label}>
          {children}
        </ul>
      </SortableContext>
    </DndContext>
  )
}

/**
 * One row: a drag handle, then whatever the row holds.
 */
export function SortableRow({
  id,
  handleLabel,
  disabled = false,
  selected = false,
  children,
}: {
  id: string
  handleLabel: string
  disabled?: boolean
  selected?: boolean
  children: ReactNode
}) {
  const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id, disabled })

  return (
    <li
      ref={setNodeRef}
      className={['sortable__row', selected ? 'is-selected' : '', isDragging ? 'is-dragging' : ''].join(' ').trim()}
      style={{ transform: CSS.Transform.toString(transform), transition }}
    >
      {disabled ? (
        <span className="sortable__handle sortable__handle--fixed" aria-hidden />
      ) : (
        <button type="button" className="sortable__handle" ref={setActivatorNodeRef} aria-label={handleLabel} {...attributes} {...listeners}>
          <GripVertical size={15} aria-hidden />
        </button>
      )}
      {children}
    </li>
  )
}
