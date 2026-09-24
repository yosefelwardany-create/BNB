import type { ReactNode } from 'react'
import { ApiError } from '@/api/client'

/**
 * Renders the loading, error and empty states of a query so every list screen
 * handles them the same way — including saying plainly when a refusal was a
 * permissions problem rather than a fault.
 */
export function QueryState({
  isLoading,
  error,
  isEmpty,
  emptyTitle = 'Nothing here yet',
  emptyBody,
  children,
}: {
  isLoading: boolean
  error: unknown
  isEmpty?: boolean
  emptyTitle?: string
  emptyBody?: string
  children: ReactNode
}) {
  if (isLoading) {
    // The shape of what is coming, shimmering, rather than a spinner: the
    // layout does not jump when the rows arrive.
    return (
      <div className="skeleton-block" aria-busy="true">
        <span className="sr-only">Loading…</span>
        {[0.92, 0.78, 0.86, 0.64].map((width, index) => (
          <div key={index} className="skeleton--row" aria-hidden="true">
            <span className="skeleton skeleton--circle" />
            <span className="stack" style={{ flex: 1, gap: 8 }}>
              <span className="skeleton" style={{ width: `${width * 60}%` }} />
              <span
                className="skeleton"
                style={{ width: `${width * 100}%`, height: 10, opacity: 0.7 }}
              />
            </span>
          </div>
        ))}
      </div>
    )
  }

  if (error) {
    const apiError = error instanceof ApiError ? error : null

    return (
      <div className="notice notice--error" role="alert">
        {apiError?.isForbidden
          ? 'You do not have permission to view this.'
          : (apiError?.message ?? 'Something went wrong loading this.')}
      </div>
    )
  }

  if (isEmpty === true) {
    return (
      <div className="empty">
        <div className="empty__title">{emptyTitle}</div>
        {emptyBody !== undefined && <p>{emptyBody}</p>}
      </div>
    )
  }

  return <>{children}</>
}
