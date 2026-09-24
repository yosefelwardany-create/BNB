import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ApiError } from '@/api/client'
import { QueryState } from '@/components/QueryState'

/**
 * Loading, failure and emptiness.
 *
 * Every list screen routes through this, so a mistake here is a mistake
 * everywhere at once. The case that matters most is the third one: a refusal
 * the user cannot do anything about must not be dressed up as a fault, or
 * somebody spends an afternoon reporting a bug that is working as intended.
 */
describe('QueryState', () => {
  it('shows the children once the data is there', () => {
    render(
      <QueryState isLoading={false} error={null} isEmpty={false}>
        <p>Four properties</p>
      </QueryState>,
    )

    expect(screen.getByText('Four properties')).toBeInTheDocument()
  })

  it('does not render the children while loading', () => {
    render(
      <QueryState isLoading error={null}>
        <p>Four properties</p>
      </QueryState>,
    )

    expect(screen.getByText('Loading…')).toBeInTheDocument()
    expect(screen.queryByText('Four properties')).not.toBeInTheDocument()
  })

  it('says plainly when the refusal was a permission, not a fault', () => {
    render(
      <QueryState isLoading={false} error={new ApiError(403, 'This action is unauthorized.')}>
        <p>Four properties</p>
      </QueryState>,
    )

    expect(screen.getByRole('alert')).toHaveTextContent('You do not have permission to view this.')
  })

  it('repeats the server’s own message for any other failure', () => {
    // The server's refusals are written for a person to read — "This stay
    // overlaps a confirmed booking" is more use than "Something went wrong".
    render(
      <QueryState
        isLoading={false}
        error={new ApiError(422, 'This stay overlaps a confirmed booking.')}
      >
        <p>Four properties</p>
      </QueryState>,
    )

    expect(screen.getByRole('alert')).toHaveTextContent('This stay overlaps a confirmed booking.')
  })

  it('falls back to a generic message for a failure that is not from the API', () => {
    render(
      <QueryState isLoading={false} error={new TypeError('Failed to fetch')}>
        <p>Four properties</p>
      </QueryState>,
    )

    expect(screen.getByRole('alert')).toHaveTextContent('Something went wrong loading this.')
  })

  it('distinguishes an empty list from a failed one', () => {
    render(
      <QueryState
        isLoading={false}
        error={null}
        isEmpty
        emptyTitle="No payments yet"
        emptyBody="Nothing has been taken for this property."
      >
        <p>Four properties</p>
      </QueryState>,
    )

    expect(screen.getByText('No payments yet')).toBeInTheDocument()
    expect(screen.getByText('Nothing has been taken for this property.')).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})
