import { useEffect, useId, useMemo, useRef, useState, type ComponentType } from 'react'
import { AnimatePresence, motion } from 'motion/react'
import { ArrowDownUp, CornerDownLeft, Search } from 'lucide-react'

/**
 * The command palette: ⌘K (Ctrl+K elsewhere), or "/" when not typing.
 *
 * Every destination and action a shell offers, filtered as you type and run
 * from the keyboard. It only ever lists what the navigation already shows, so
 * it never offers a screen the user's role would hide.
 */

export interface Command {
  id: string
  label: string
  group: string
  icon: ComponentType<{ size?: number }>
  hint?: string
  keywords?: string
  run: () => void
}

export function CommandPalette({
  open,
  onClose,
  commands,
}: {
  open: boolean
  onClose: () => void
  commands: Command[]
}) {
  return (
    <AnimatePresence>
      {open && <PaletteDialog key="palette" onClose={onClose} commands={commands} />}
    </AnimatePresence>
  )
}

function PaletteDialog({ onClose, commands }: { onClose: () => void; commands: Command[] }) {
  const [query, setQuery] = useState('')
  const [active, setActive] = useState(0)
  const listId = useId()
  const inputRef = useRef<HTMLInputElement>(null)
  const listRef = useRef<HTMLDivElement>(null)

  const results = useMemo(() => {
    const terms = query.trim().toLowerCase().split(/\s+/).filter(Boolean)
    if (terms.length === 0) return commands

    return commands.filter((command) => {
      const haystack = `${command.label} ${command.group} ${command.keywords ?? ''}`.toLowerCase()
      return terms.every((term) => haystack.includes(term))
    })
  }, [commands, query])

  const current = Math.min(active, Math.max(0, results.length - 1))

  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    inputRef.current?.focus()
    return () => previous?.focus?.()
  }, [])

  useEffect(() => {
    listRef.current
      ?.querySelector(`[data-index="${current}"]`)
      ?.scrollIntoView({ block: 'nearest' })
  }, [current])

  function run(command: Command | undefined) {
    if (command === undefined) return
    onClose()
    command.run()
  }

  function onKeyDown(event: React.KeyboardEvent) {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setActive((current + 1) % Math.max(1, results.length))
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActive((current - 1 + results.length) % Math.max(1, results.length))
    } else if (event.key === 'Enter') {
      event.preventDefault()
      run(results[current])
    } else if (event.key === 'Escape') {
      event.preventDefault()
      onClose()
    } else if (event.key === 'Tab') {
      // The palette is modal: focus stays in the search field.
      event.preventDefault()
    }
  }

  return (
    <motion.div
      className="palette-backdrop"
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={{ opacity: 0 }}
      transition={{ duration: 0.18 }}
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <motion.div
        className="palette"
        role="dialog"
        aria-modal="true"
        aria-label="Command palette"
        initial={{ opacity: 0, y: -16, scale: 0.96 }}
        animate={{ opacity: 1, y: 0, scale: 1 }}
        exit={{ opacity: 0, y: -10, scale: 0.97 }}
        transition={{ type: 'spring', stiffness: 460, damping: 34 }}
        onKeyDown={onKeyDown}
      >
        <div className="palette__search">
          <Search size={19} aria-hidden="true" />
          <input
            ref={inputRef}
            type="text"
            role="combobox"
            aria-expanded="true"
            aria-controls={listId}
            aria-activedescendant={results.length > 0 ? `${listId}-${current}` : undefined}
            aria-label="Search pages and actions"
            placeholder="Where to? Search pages and actions…"
            value={query}
            onChange={(event) => {
              setQuery(event.target.value)
              setActive(0)
            }}
          />
          <kbd>esc</kbd>
        </div>

        <div className="palette__list" id={listId} role="listbox" ref={listRef}>
          {results.length === 0 && (
            <div className="palette__empty">Nothing matches “{query}”.</div>
          )}

          {results.map((command, index) => {
            const heading = index === 0 || results[index - 1]?.group !== command.group ? command.group : null
            const Icon = command.icon
            const isActive = index === current

            return (
              <div key={command.id}>
                {heading !== null && <div className="palette__group">{heading}</div>}
                <button
                  type="button"
                  id={`${listId}-${index}`}
                  data-index={index}
                  role="option"
                  aria-selected={isActive}
                  tabIndex={-1}
                  className={`palette__item${isActive ? ' palette__item--active' : ''}`}
                  onMouseMove={() => setActive(index)}
                  onClick={() => run(command)}
                >
                  {isActive && (
                    <motion.span
                      layoutId="palette-highlight"
                      className="palette__highlight"
                      transition={{ type: 'spring', stiffness: 600, damping: 42 }}
                    />
                  )}
                  <span className="palette__item-icon" aria-hidden="true">
                    <Icon size={16} />
                  </span>
                  {command.label}
                  {command.hint !== undefined && (
                    <span className="palette__item-hint">{command.hint}</span>
                  )}
                </button>
              </div>
            )
          })}
        </div>

        <div className="palette__footer" aria-hidden="true">
          <span>
            <ArrowDownUp size={13} /> navigate
          </span>
          <span>
            <CornerDownLeft size={13} /> open
          </span>
          <span>
            <kbd>esc</kbd> close
          </span>
        </div>
      </motion.div>
    </motion.div>
  )
}
