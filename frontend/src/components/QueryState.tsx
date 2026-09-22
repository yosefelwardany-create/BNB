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
    return (
      <div className="empty">
        <span className="spinner" />
        <div className="mt-2 muted">Loading…</div>
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
