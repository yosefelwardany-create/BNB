import { useEffect, useRef } from 'react'
import type { TeamOptions } from '@/api/types'
import { modulesFrom, type FeatureRow } from '@/lib/accessMatrix'

/**
 * What somebody can see and change, feature by feature, module by module.
 *
 * Every permission belongs to a feature — the part before its dot, so
 * `reservations.cancel` is Reservations and `calendar.update` is the Calendar
 * — and is either seeing (`view`, `view_own`) or changing it (everything
 * else). A row is a feature with a See box and an Edit box; a module's own
 * row ticks every feature in it.
 *
 * Changing something you cannot see makes no sense, so ticking Edit ticks See
 * as well and unticking See unticks Edit. A box with some of its feature's
 * permissions and not others — a role that may create bookings but not
 * cancel them — shows as part-ticked; ticking it gives the rest.
 */
type State = 'all' | 'some' | 'none'

function stateOf(permissions: string[], holds: (permission: string) => boolean): State {
  const held = permissions.filter(holds).length

  return held === 0 ? 'none' : held === permissions.length ? 'all' : 'some'
}

function Tick({
  state,
  label,
  disabled,
  title,
  onChange,
}: {
  state: State
  label: string
  disabled: boolean
  title?: string
  onChange: (on: boolean) => void
}) {
  const ref = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (ref.current !== null) ref.current.indeterminate = state === 'some'
  }, [state])

  return (
    <input
      ref={ref}
      type="checkbox"
      aria-label={label}
      title={title}
      checked={state === 'all'}
      disabled={disabled}
      onChange={(event) => onChange(event.target.checked)}
    />
  )
}

export function AccessMatrix({
  areas,
  holds,
  canGive,
  onChange,
}: {
  areas: TeamOptions['areas']
  /** Whether the person will hold this permission. */
  holds: (permission: string) => boolean
  /** Whether whoever is editing may give it. */
  canGive: (permission: string) => boolean
  /** Give (`on`) or take away every permission listed. */
  onChange: (permissions: string[], on: boolean) => void
}) {
  const modules = modulesFrom(areas)

  // Ticking on gives what is missing; it is refused only for what the editor
  // could not give, so a box already full from the role still unticks.
  const blocked = (permissions: string[]) => permissions.some((permission) => !holds(permission) && !canGive(permission))

  function see(features: FeatureRow[], on: boolean) {
    const permissions = features.flatMap((feature) => (on ? feature.see : [...feature.see, ...feature.edit]))
    onChange(permissions, on)
  }

  function edit(features: FeatureRow[], on: boolean) {
    const permissions = features.flatMap((feature) => (on ? [...feature.see, ...feature.edit] : feature.edit))
    onChange(permissions, on)
  }

  return (
    <div className="access-matrix">
      {modules.map((module) => {
        const allSee = module.features.flatMap((feature) => feature.see)
        const allEdit = module.features.flatMap((feature) => feature.edit)

        return (
          <table key={module.name} className="data access-matrix__module">
            <thead>
              <tr>
                <th>{module.name}</th>
                <th className="access-matrix__tick">
                  {allSee.length > 0 && (
                    <Tick
                      state={stateOf(allSee, holds)}
                      label={`See all of ${module.name}`}
                      disabled={stateOf(allSee, holds) !== 'all' && blocked(allSee)}
                      onChange={(on) => see(module.features, on)}
                    />
                  )}{' '}
                  See
                </th>
                <th className="access-matrix__tick">
                  {allEdit.length > 0 && (
                    <Tick
                      state={stateOf(allEdit, holds)}
                      label={`Edit all of ${module.name}`}
                      disabled={stateOf(allEdit, holds) !== 'all' && blocked([...allSee, ...allEdit])}
                      onChange={(on) => edit(module.features, on)}
                    />
                  )}{' '}
                  Edit
                </th>
              </tr>
            </thead>
            <tbody>
              {module.features.map((feature) => {
                const seeState = stateOf(feature.see, holds)
                const editState = stateOf(feature.edit, holds)
                const describe = (permissions: string[]) =>
                  permissions.map((permission) => feature.labels[permission] ?? permission).join(' · ')

                return (
                  <tr key={feature.key}>
                    <td className="small">{feature.label}</td>
                    <td className="access-matrix__tick">
                      {feature.see.length > 0 ? (
                        <Tick
                          state={seeState}
                          label={`See ${feature.label}`}
                          title={describe(feature.see)}
                          disabled={seeState !== 'all' && blocked(feature.see)}
                          onChange={(on) => see([feature], on)}
                        />
                      ) : (
                        <span className="faint">—</span>
                      )}
                    </td>
                    <td className="access-matrix__tick">
                      {feature.edit.length > 0 ? (
                        <Tick
                          state={editState}
                          label={`Edit ${feature.label}`}
                          title={describe(feature.edit)}
                          disabled={editState !== 'all' && blocked([...feature.see, ...feature.edit])}
                          onChange={(on) => edit([feature], on)}
                        />
                      ) : (
                        <span className="faint">—</span>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )
      })}
    </div>
  )
}
