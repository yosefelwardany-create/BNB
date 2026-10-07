import { useEffect, useMemo, type ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { currentAuth } from '@/api/client'
import { useAuth } from '@/lib/auth'
import { createQueryClient } from '@/lib/queryClient'

/**
 * One query cache per selected account.
 *
 * The cache used to be a single module-level client and no query key carried
 * the account id, so after switching accounts anything fetched in the last
 * thirty seconds was shown under the new one. Keying the provider on the
 * account id means a switch mounts an empty cache and unmounts every observer
 * of the old one; the old client is cancelled and cleared on the way out.
 *
 * This is the structural half of the guarantee. The other half is in the API
 * client, which refuses a response that arrives after the account changed.
 */
export function OrganizationScopedQueries({
  children,
  create = createQueryClient,
}: {
  children: ReactNode
  create?: () => QueryClient
}) {
  const { session } = useAuth()

  // The stored selection is the fallback so the key is right from the first
  // render: a session that loads for the account already selected must not
  // remount the tree (and lose whatever the person was doing) for nothing.
  const accountId = session?.organization?.id ?? currentAuth()?.organizationId ?? 'none'

  // eslint-disable-next-line react-hooks/exhaustive-deps -- a new client per account, by design
  const client = useMemo(() => create(), [accountId])

  useEffect(
    () => () => {
      void client.cancelQueries()
      client.clear()
    },
    [client],
  )

  return (
    <QueryClientProvider key={accountId} client={client}>
      {children}
    </QueryClientProvider>
  )
}
