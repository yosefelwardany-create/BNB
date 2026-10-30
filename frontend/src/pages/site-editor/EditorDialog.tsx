import { useEffect, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { X } from 'lucide-react'

/**
 * A small dialog over the editor, in the application's own dialog style:
 * the block library, a confirmation, a new page's name.
 */
export function EditorDialog({
  title,
  description,
  onClose,
  children,
  wide = false,
}: {
  title: string
  description?: string
  onClose: () => void
  children: ReactNode
  wide?: boolean
}) {
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose()
    }

    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  return createPortal(
    <div className="dialog-backdrop" role="presentation" onClick={onClose}>
      <div
        className={wide ? 'dialog dialog--wide' : 'dialog'}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onClick={(event) => event.stopPropagation()}
      >
        <header className="dialog__header row row--between">
          <div>
            <h2>{title}</h2>
            {description !== undefined && <p className="small muted">{description}</p>}
          </div>
          <button type="button" className="btn btn--ghost btn--sm" onClick={onClose} aria-label="Close">
            <X size={16} aria-hidden />
          </button>
        </header>
        <div className="dialog__body stack">{children}</div>
      </div>
    </div>,
    document.body,
  )
}

/** Ask once more before something that cannot be taken back. */
export function ConfirmDialog({
  title,
  body,
  confirmLabel,
  onConfirm,
  onClose,
}: {
  title: string
  body: string
  confirmLabel: string
  onConfirm: () => void
  onClose: () => void
}) {
  return (
    <EditorDialog title={title} onClose={onClose}>
      <p>{body}</p>
      <div className="row" style={{ justifyContent: 'flex-end' }}>
        <button type="button" className="btn btn--sm" onClick={onClose}>
          Cancel
        </button>
        <button
          type="button"
          className="btn btn--sm btn--danger"
          onClick={() => {
            onConfirm()
            onClose()
          }}
        >
          {confirmLabel}
        </button>
      </div>
    </EditorDialog>
  )
}
