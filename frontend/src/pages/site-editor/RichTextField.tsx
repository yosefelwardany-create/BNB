import { useEffect, useRef } from 'react'
import { Bold, Italic, Link, List, ListOrdered } from 'lucide-react'

/**
 * Formatted text, typed the way it reads: bold, italic, lists and links, and
 * nothing else. The server keeps only those, whatever is pasted in.
 */
export function RichTextField({
  labelledBy,
  value,
  onChange,
}: {
  labelledBy: string
  value: string
  onChange: (html: string) => void
}) {
  const box = useRef<HTMLDivElement>(null)

  // Written into the box only when it differs, so typing never moves the
  // caret back to the start.
  useEffect(() => {
    if (box.current !== null && box.current.innerHTML !== value) box.current.innerHTML = value
  }, [value])

  const emit = () => onChange(box.current?.innerHTML ?? '')

  function command(name: string, argument?: string) {
    box.current?.focus()
    // Old, but the one way every browser formats a selection in place.
    if (typeof document.execCommand === 'function') document.execCommand(name, false, argument)
    emit()
  }

  function link() {
    const address = window.prompt('Link to (https://…, mailto: or tel:)')
    if (address !== null && address.trim() !== '') command('createLink', address.trim())
  }

  return (
    <div className="rich-text">
      <div className="rich-text__toolbar" role="toolbar" aria-label="Formatting">
        <button type="button" className="btn btn--ghost btn--icon" aria-label="Bold" onMouseDown={(event) => event.preventDefault()} onClick={() => command('bold')}>
          <Bold size={14} aria-hidden />
        </button>
        <button type="button" className="btn btn--ghost btn--icon" aria-label="Italic" onMouseDown={(event) => event.preventDefault()} onClick={() => command('italic')}>
          <Italic size={14} aria-hidden />
        </button>
        <button type="button" className="btn btn--ghost btn--icon" aria-label="Bulleted list" onMouseDown={(event) => event.preventDefault()} onClick={() => command('insertUnorderedList')}>
          <List size={14} aria-hidden />
        </button>
        <button type="button" className="btn btn--ghost btn--icon" aria-label="Numbered list" onMouseDown={(event) => event.preventDefault()} onClick={() => command('insertOrderedList')}>
          <ListOrdered size={14} aria-hidden />
        </button>
        <button type="button" className="btn btn--ghost btn--icon" aria-label="Link" onMouseDown={(event) => event.preventDefault()} onClick={link}>
          <Link size={14} aria-hidden />
        </button>
      </div>
      <div
        ref={box}
        className="rich-text__box input"
        contentEditable
        suppressContentEditableWarning
        role="textbox"
        aria-multiline="true"
        aria-labelledby={labelledBy}
        onInput={emit}
        onBlur={emit}
      />
    </div>
  )
}
