import { useState } from 'react'
import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { TeamOptions } from '@/api/types'
import { AccessMatrix } from '@/components/AccessMatrix'
import { modulesFrom } from '@/lib/accessMatrix'

/**
 * What somebody can see and change, a feature to a row.
 *
 * The rules worth holding: changing a feature means seeing it, so Edit brings
 * See with it and losing See loses Edit; and a module's own box does the same
 * for every feature in it.
 */
const areas: TeamOptions['areas'] = [
  {
    name: 'Reservations',
    permissions: [
      { key: 'reservations.view', label: 'View reservations' },
      { key: 'reservations.cancel', label: 'Cancel reservations' },
      { key: 'calendar.view', label: 'View the multi-calendar' },
      { key: 'calendar.update', label: 'Modify availability from the calendar' },
    ],
  },
  {
    name: 'Platform & integrations',
    permissions: [{ key: 'api_keys.manage', label: 'Manage API keys' }],
  },
]

function Harness({ start = [] as string[], giveable = (_: string) => true }) {
  const [held, setHeld] = useState<string[]>(start)

  return (
    <>
      <AccessMatrix
        areas={areas}
        holds={(permission) => held.includes(permission)}
        canGive={giveable}
        onChange={(permissions, on) =>
          setHeld((current) => (on ? [...new Set([...current, ...permissions])] : current.filter((entry) => !permissions.includes(entry))))
        }
      />
      <output data-testid="held">{[...held].sort().join(',')}</output>
    </>
  )
}

describe('features by module', () => {
  it('splits each module into features that are seen or changed', () => {
    const [reservations, platform] = modulesFrom(areas)

    expect(reservations?.features.map((feature) => [feature.label, feature.see, feature.edit])).toEqual([
      ['Reservations', ['reservations.view'], ['reservations.cancel']],
      ['Calendar', ['calendar.view'], ['calendar.update']],
    ])
    // A feature with nothing to see has no See box, only Edit.
    expect(platform?.features[0]).toMatchObject({ label: 'API keys', see: [], edit: ['api_keys.manage'] })
  })
})

describe('ticking', () => {
  it('brings See along with Edit, and takes Edit away with See', async () => {
    render(<Harness />)

    await userEvent.click(screen.getByLabelText('Edit Calendar'))
    expect(screen.getByTestId('held')).toHaveTextContent('calendar.update,calendar.view')

    await userEvent.click(screen.getByLabelText('See Calendar'))
    expect(screen.getByTestId('held')).toHaveTextContent(/^$/)
  })

  it('ticks a whole module at once', async () => {
    render(<Harness />)

    await userEvent.click(screen.getByLabelText('See all of Reservations'))
    expect(screen.getByTestId('held')).toHaveTextContent('calendar.view,reservations.view')

    await userEvent.click(screen.getByLabelText('Edit all of Reservations'))
    expect(screen.getByTestId('held')).toHaveTextContent('calendar.update,calendar.view,reservations.cancel,reservations.view')
  })

  it('shows part of a feature as part-ticked', () => {
    render(<Harness start={['reservations.view']} />)

    expect(screen.getByLabelText('See all of Reservations')).toHaveProperty('indeterminate', true)
    expect(screen.getByLabelText('See Reservations')).toBeChecked()
  })

  it('will not give what the editor cannot, and still lets them take it away', () => {
    render(<Harness start={['calendar.update', 'calendar.view']} giveable={(permission) => permission !== 'api_keys.manage' && permission !== 'calendar.update'} />)

    expect(screen.getByLabelText('Edit API keys')).toBeDisabled()
    // Held already, so it can be unticked even though it could not be given.
    expect(screen.getByLabelText('Edit Calendar')).toBeEnabled()
  })
})
