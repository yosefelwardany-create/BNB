import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { LifeBuoy, Phone, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type { PropertyHelper } from '@/api/types'

/**
 * Who to call about one property.
 *
 * Built for the moment it is actually used, which is not a calm one: a boiler at
 * midnight, a lock that will not open, a guest already standing outside. So the
 * number is the largest thing on each row and it is a `tel:` link, and whoever
 * is marked to call first is at the top with a badge rather than sorted
 * invisibly.
 *
 * A helper can point at a vendor or a colleague the organization already holds,
 * in which case their record is the truth and this screen says so rather than
 * offering boxes that will be ignored.
 */
export function PropertyHelpers({
  propertyId,
  mayEdit,
}: {
  propertyId: string
  mayEdit: boolean
}) {
  const queryClient = useQueryClient()
  const [adding, setAdding] = useState(false)

  const helpers = useQuery({
    queryKey: ['property-helpers', propertyId],
    queryFn: () =>
      api.get<{ data: PropertyHelper[]; meta: { roles: string[] } }>(
        `properties/${propertyId}/helpers`,
      ),
  })

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['property-helpers', propertyId] })
    // The card shows a count, so it goes stale the moment this list changes.
    void queryClient.invalidateQueries({ queryKey: ['properties'] })
  }

  const remove = useMutation({
    mutationFn: (id: string) => api.delete(`helpers/${id}`),
    onSuccess: invalidate,
  })

  const rows = helpers.data?.data ?? []
  const roles = helpers.data?.meta.roles ?? []

  return (
    <section className="card mb-3">
      <header className="card__header row row--between">
        <h2>
          <LifeBuoy size={16} aria-hidden /> Who to call
        </h2>
        {mayEdit && !adding && (
          <button type="button" className="btn btn--sm" onClick={() => setAdding(true)}>
            Add someone
          </button>
        )}
      </header>

      <div className="card__body stack">
        {rows.length === 0 && !adding && (
          <p className="small muted">
            Nobody yet. The agent escalates to whoever is listed here, so an empty list means it has
            nowhere to send a problem it cannot solve.
          </p>
        )}

        {rows.map((helper) => (
          <div key={helper.id} className="row row--between bordered p-2">
            <span className="stack stack--tight">
              <span>
                <strong>{helper.name}</strong>{' '}
                <span className="small faint">· {helper.label ?? helper.role_label}</span>
                {helper.is_primary && <span className="small"> · call first</span>}
              </span>
              {helper.phone !== null ? (
                <a href={`tel:${helper.phone}`} className="property-helper__phone">
                  <Phone size={14} aria-hidden /> {helper.phone}
                </a>
              ) : (
                // Said out loud. "We have no number for the electrician" is a
                // thing somebody needs to learn before two in the morning, not
                // during it.
                <span className="small muted">No number recorded</span>
              )}
              {helper.is_linked && (
                <span className="small faint">
                  Details come from their own record — change them there.
                </span>
              )}
            </span>

            {mayEdit && (
              <button
                type="button"
                className="btn btn--sm btn--ghost"
                aria-label={`Remove ${helper.name}`}
                disabled={remove.isPending}
                onClick={() => remove.mutate(helper.id)}
              >
                <Trash2 size={14} aria-hidden />
              </button>
            )}
          </div>
        ))}

        {remove.error !== null && (
          <p className="field__error small" role="alert">
            {remove.error instanceof ApiError ? remove.error.message : 'That could not be removed.'}
          </p>
        )}

        {adding && (
          <HelperForm
            propertyId={propertyId}
            roles={roles}
            onDone={() => {
              setAdding(false)
              invalidate()
            }}
            onCancel={() => setAdding(false)}
          />
        )}
      </div>
    </section>
  )
}

function HelperForm({
  propertyId,
  roles,
  onDone,
  onCancel,
}: {
  propertyId: string
  roles: string[]
  onDone: () => void
  onCancel: () => void
}) {
  const [role, setRole] = useState(roles[0] ?? 'manager')
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [isPrimary, setIsPrimary] = useState(false)

  const save = useMutation({
    mutationFn: () =>
      api.post(`properties/${propertyId}/helpers`, {
        role,
        name: name.trim() === '' ? null : name.trim(),
        phone: phone.trim() === '' ? null : phone.trim(),
        is_primary: isPrimary,
      }),
    onSuccess: onDone,
  })

  return (
    <form
      className="stack bordered p-2"
      onSubmit={(event) => {
        event.preventDefault()
        save.mutate()
      }}
    >
      {save.error !== null && (
        <p className="field__error small" role="alert">
          {save.error instanceof ApiError ? save.error.message : 'That could not be saved.'}
        </p>
      )}

      <div className="grid grid--2">
        <div className="field">
          <label className="field__label" htmlFor="helper-role">
            What they do
          </label>
          <select id="helper-role" value={role} onChange={(event) => setRole(event.target.value)}>
            {roles.map((option) => (
              <option key={option} value={option}>
                {option.charAt(0).toUpperCase() + option.slice(1)}
              </option>
            ))}
          </select>
        </div>

        <div className="field">
          <label className="field__label" htmlFor="helper-name">
            Name
          </label>
          <input
            id="helper-name"
            type="text"
            value={name}
            onChange={(event) => setName(event.target.value)}
          />
        </div>
      </div>

      <div className="field">
        <label className="field__label" htmlFor="helper-phone">
          Number
        </label>
        <input
          id="helper-phone"
          type="tel"
          value={phone}
          onChange={(event) => setPhone(event.target.value)}
        />
      </div>

      <label>
        <input
          type="checkbox"
          checked={isPrimary}
          onChange={(event) => setIsPrimary(event.target.checked)}
        />
        <span>Call this one first</span>
      </label>

      <div className="row row--between">
        <button type="button" className="btn btn--sm btn--ghost" onClick={onCancel}>
          Cancel
        </button>
        <button type="submit" className="btn btn--sm btn--primary" disabled={save.isPending}>
          {save.isPending ? 'Saving…' : 'Add'}
        </button>
      </div>
    </form>
  )
}
