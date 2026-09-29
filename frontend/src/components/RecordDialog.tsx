import { useEffect, useState } from 'react'
import { X } from 'lucide-react'
import { ApiError } from '@/api/client'

/**
 * One field in a record form.
 *
 * Declared as data rather than markup because this application needs the same
 * form in a dozen places and hand-written markup drifts: one screen gets the
 * error line under the input, another above it, a third forgets it. A spec
 * cannot drift.
 */
export type FieldSpec =
  | {
      name: string
      label: string
      type: 'text' | 'email' | 'tel' | 'date' | 'time' | 'textarea' | 'number'
      required?: boolean
      hint?: string
      placeholder?: string
      rows?: number
    }
  | {
      name: string
      label: string
      type: 'select'
      options: { value: string; label: string }[]
      required?: boolean
      hint?: string
      /**
       * What to say when there is nothing to choose from.
       *
       * An empty picker on a required field is a dead end, and an empty dropdown
       * does not explain itself: the person sees a control that opens onto
       * nothing and cannot tell whether the data is missing, still loading, or
       * hidden from them. Saying which, and where to go, is the difference
       * between a gap they can close and one they report as a broken screen.
       */
      emptyHint?: React.ReactNode
    }
  | {
      name: string
      label: string
      /**
       * Money, typed by a person in whole units and sent in minor units.
       *
       * The platform stores money as integers throughout, because a rounding
       * error in a ledger is a defect. Nobody types "14500" for €145, so the
       * conversion happens here — once, in a place that is tested — rather than
       * in each screen where it would eventually be forgotten.
       */
      type: 'money'
      required?: boolean
      hint?: string
    }
  | { name: string; label: string; type: 'checkbox'; hint?: string }
  | {
      name: string
      label: string
      /**
       * Several of a fixed list — amenities, most of the time.
       *
       * Held as a comma-joined string inside the form's values because the rest
       * of this component treats a value as a scalar, and split back into an
       * array on the way out. Keeping one value type is worth more than the
       * purity of holding an array here.
       */
      type: 'multiselect'
      options: { value: string; label: string }[]
      hint?: string
    }

/** What a control holds while the form is open. */
export type FieldValue = string | number | boolean | null

/**
 * What a form submits.
 *
 * Wider than {@see FieldValue} because a multi-select submits an array while
 * holding a joined string — the one place the two genuinely differ.
 */
export type RecordValues = Record<string, FieldValue | string[]>

/**
 * A dialog for creating or editing one record.
 *
 * Two behaviours worth stating, because they are what make it safe to use for
 * editing as well as creating:
 *
 *  - **Only changed fields are submitted on an edit.** Sending the whole form
 *    back would overwrite a field a colleague changed while this dialog was
 *    open, and would rewrite values the person never looked at.
 *  - **Server validation lands on the field it belongs to.** The API answers a
 *    bad value with a 422 naming the field; showing that as one banner at the
 *    top makes the person hunt for it.
 */
export function RecordDialog({
  title,
  description,
  fields,
  initial,
  submitLabel = 'Save',
  pending = false,
  error = null,
  onSubmit,
  onClose,
  children,
}: {
  title: string
  description?: string
  fields: FieldSpec[]
  /** Present when editing; absent when creating. */
  initial?: RecordValues
  submitLabel?: string
  pending?: boolean
  error?: unknown
  /** Receives only the fields that changed. */
  onSubmit: (values: RecordValues) => void
  onClose: () => void
  children?: React.ReactNode
}) {
  // Seeded once. Callers mount this dialog only while it is open, so opening it
  // on a different record mounts a fresh one — there is no stale state to
  // synchronise, and an effect that re-seeded on every `fields` identity change
  // would wipe what the person had typed the moment a picker's options loaded.
  const [values, setValues] = useState<Record<string, FieldValue>>(() => blank(fields, initial))

  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose()
    }

    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const apiError = error instanceof ApiError ? error : null

  function set(name: string, value: FieldValue) {
    setValues((current) => ({ ...current, [name]: value }))
  }

  function submit() {
    onSubmit(changedOnly(fields, values, initial))
  }

  return (
    <div className="dialog-backdrop" role="presentation" onClick={onClose}>
      <div
        className="dialog"
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

        <form
          className="dialog__body stack"
          onSubmit={(event) => {
            event.preventDefault()
            submit()
          }}
        >
          {apiError !== null && Object.keys(apiError.errors).length === 0 && (
            <div className="notice notice--error" role="alert">
              {apiError.message}
            </div>
          )}

          {fields.map((field) => (
            <FieldControl
              key={field.name}
              field={field}
              value={values[field.name] ?? null}
              error={apiError?.fieldError(field.name)}
              onChange={(value) => set(field.name, value)}
            />
          ))}

          {children}

          <div className="row row--between">
            <button type="button" className="btn" onClick={onClose}>
              Cancel
            </button>
            <button type="submit" className="btn btn--primary" disabled={pending}>
              {pending ? 'Saving…' : submitLabel}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

function FieldControl({
  field,
  value,
  error,
  onChange,
}: {
  field: FieldSpec
  value: FieldValue
  error?: string
  onChange: (value: FieldValue) => void
}) {
  const id = `field-${field.name}`

  // Whether `id` belongs to a real form control. A multi-select is a group of
  // them, and a picker with nothing to pick renders a message instead — in both
  // cases `htmlFor` would address something that is not an input, which is worse
  // than no association at all because a screen reader announces neither.
  const control =
    field.type !== 'multiselect' &&
    !(field.type === 'select' && field.options.length === 0 && field.emptyHint !== undefined)

  return (
    <div className="field">
      <label
        className="field__label"
        htmlFor={control ? id : undefined}
        id={control ? undefined : `${id}-label`}
      >
        {field.label}
        {'required' in field && field.required === true && (
          <span className="field__required" aria-hidden>
            {' '}
            *
          </span>
        )}
      </label>

      {field.type === 'textarea' ? (
        <textarea
          id={id}
          rows={field.rows ?? 3}
          value={String(value ?? '')}
          placeholder={field.placeholder}
          onChange={(event) => onChange(event.target.value)}
        />
      ) : field.type === 'select' ? (
        field.options.length === 0 && field.emptyHint !== undefined ? (
          <div className="notice notice--warning small" role="note" aria-labelledby={`${id}-label`}>
            {field.emptyHint}
          </div>
        ) : (
          <select id={id} value={String(value ?? '')} onChange={(event) => onChange(event.target.value)}>
            <option value="">—</option>
            {field.options.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        )
      ) : field.type === 'checkbox' ? (
        <input
          id={id}
          type="checkbox"
          checked={value === true}
          onChange={(event) => onChange(event.target.checked)}
        />
      ) : field.type === 'multiselect' ? (
        <div className="checks" role="group" aria-labelledby={`${id}-label`}>
          {field.options.map((option) => {
            const chosen = String(value ?? '')
              .split(',')
              .filter((entry) => entry !== '')

            return (
              <label key={option.value}>
                <input
                  type="checkbox"
                  checked={chosen.includes(option.value)}
                  onChange={(event) =>
                    onChange(
                      (event.target.checked
                        ? [...chosen, option.value]
                        : chosen.filter((entry) => entry !== option.value)
                      ).join(','),
                    )
                  }
                />
                <span>{option.label}</span>
              </label>
            )
          })}
        </div>
      ) : field.type === 'money' ? (
        <input
          id={id}
          type="number"
          step="0.01"
          min="0"
          value={value === null || value === '' ? '' : String(value)}
          onChange={(event) => onChange(event.target.value === '' ? null : event.target.value)}
        />
      ) : (
        <input
          id={id}
          type={field.type}
          value={String(value ?? '')}
          placeholder={'placeholder' in field ? field.placeholder : undefined}
          onChange={(event) => onChange(event.target.value)}
        />
      )}

      {field.hint !== undefined && <p className="field__hint small faint">{field.hint}</p>}
      {error !== undefined && (
        <p className="field__error small" role="alert">
          {error}
        </p>
      )}
    </div>
  )
}

function blank(fields: FieldSpec[], initial?: RecordValues): Record<string, FieldValue> {
  const values: Record<string, FieldValue> = {}

  for (const field of fields) {
    const seed = initial?.[field.name]

    if (Array.isArray(seed)) {
      // A multi-select is seeded from the array the API returned and held as a
      // joined string while the form is open.
      values[field.name] = seed.join(',')

      continue
    }

    values[field.name] =
      field.type === 'money' && typeof seed === 'number'
        ? // Stored in minor units, shown in whole ones.
          (seed / 100).toFixed(2)
        : (seed ?? (field.type === 'checkbox' ? false : ''))
  }

  return values
}

/**
 * The values actually worth sending.
 *
 * On a create that is everything the person filled in — an empty optional field
 * is left out rather than sent as an empty string, which several of the API's
 * validators would reject as a malformed value rather than read as "not set".
 *
 * On an edit it is only what changed, so a stale form cannot quietly revert a
 * field somebody else has since corrected.
 */
function changedOnly(
  fields: FieldSpec[],
  values: Record<string, FieldValue>,
  initial?: RecordValues,
): RecordValues {
  const payload: RecordValues = {}

  for (const field of fields) {
    const raw = values[field.name] ?? null
    const value = field.type === 'money' ? toMinorUnits(raw) : raw

    if (field.type === 'multiselect') {
      const chosen = String(raw ?? '').split(',').filter((entry) => entry !== '')

      // Sent when creating, or when the selection differs from what was there.
      const seeded = Array.isArray(initial?.[field.name])
        ? (initial[field.name] as string[]).join(',')
        : ''

      if (initial === undefined ? chosen.length > 0 : String(raw ?? '') !== seeded) {
        payload[field.name] = chosen
      }

      continue
    }

    if (initial === undefined) {
      if (value !== '' && value !== null) payload[field.name] = value

      continue
    }

    // Money arrives already in minor units and is shown in whole ones, so the
    // comparison is against the raw initial value rather than a second
    // conversion of it — running `toMinorUnits` over 14500 would yield
    // 1,450,000 and make every untouched price look edited.
    const stored = initial[field.name]
    const before: FieldValue = Array.isArray(stored)
      ? stored.join(',')
      : field.type === 'money'
        ? (typeof stored === 'number' ? stored : null)
        : (stored ?? '')

    if (value !== before) payload[field.name] = value
  }

  return payload
}

function toMinorUnits(value: FieldValue): number | null {
  if (value === null || value === '' || typeof value === 'boolean') return null

  const amount = Number(value)

  // Rounded rather than truncated: 145.005 typed into a price field is a person
  // meaning 145.01, and Math.round is the behaviour they expect from a till.
  return Number.isFinite(amount) ? Math.round(amount * 100) : null
}
