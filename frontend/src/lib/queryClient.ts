import { QueryClient } from '@tanstack/react-query'
import { StaleOrganizationError } from '@/api/client'

/**
 * The query client's defaults, in one place.
 *
 * Built by a function rather than held in a module variable because the
 * application now owns one client *per selected account*: switching accounts
 * throws the old cache away and starts a new one, so nothing fetched for one
 * client can ever be read under another.
 */
export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        // Operational data changes constantly; a short stale window keeps the
        // interface fresh without hammering the API on every render.
        staleTime: 30_000,
        refetchOnWindowFocus: true,
        retry: (failureCount, error) => {
          // A response that belongs to a previously selected account is
          // discarded, and asking again under the new one is the remount's
          // job, not a retry's.
          if (error instanceof StaleOrganizationError) return false

          // A permission or validation failure will not succeed on retry.
          const status = (error as { status?: number }).status
          if (status !== undefined && status >= 400 && status < 500) return false
          return failureCount < 2
        },
      },
    },
  })
}
