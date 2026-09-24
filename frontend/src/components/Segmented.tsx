import { useId, useRef, type ComponentType, type KeyboardEvent } from 'react'
import { motion } from 'motion/react'

/**
 * A segmented control: a small set of mutually exclusive options with a lime
 * thumb that slides to the chosen one.
 *
 * A radio group to assistive technology and to the keyboard: one tab stop,
 * and the arrow keys move the choice.
 */
export function Segmented<T extends string>({
  label,
  value,
  options,
  onChange,
}: {
  label: string
  value: T
  options: { value: T; label: string; icon?: ComponentType<{ size?: number }> }[]
  onChange: (value: T) => void
}) {
  const id = useId()
  const group = useRef<HTMLDivElement>(null)

  function onKeyDown(event: KeyboardEvent) {
    const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key]
    if (step === undefined) return

    event.preventDefault()
    const index = options.findIndex((option) => option.value === value)
    const next = options[(index + step + options.length) % options.length]
    if (next === undefined) return

    onChange(next.value)
    group.current?.querySelector<HTMLElement>(`[data-value="${CSS.escape(next.value)}"]`)?.focus()
  }

  return (
    <div className="segmented" role="radiogroup" aria-label={label} ref={group} onKeyDown={onKeyDown}>
      {options.map((option) => {
        const checked = option.value === value
        const Icon = option.icon

        return (
          <button
            key={option.value}
            type="button"
            role="radio"
            aria-checked={checked}
            tabIndex={checked ? 0 : -1}
            data-value={option.value}
            className={`segmented__option${checked ? ' segmented__option--active' : ''}`}
            onClick={() => onChange(option.value)}
          >
            {checked && (
              <motion.span
                layoutId={`segmented-${id}`}
                className="segmented__thumb"
                transition={{ type: 'spring', stiffness: 520, damping: 38 }}
              />
            )}
            {Icon !== undefined && <Icon size={15} />}
            <span>{option.label}</span>
          </button>
        )
      })}
    </div>
  )
}
