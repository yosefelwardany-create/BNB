import type { ReactElement, ReactNode } from 'react'
import { QueryClient } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { render } from '@testing-library/react'
import { storeAuth } from '@/api/client'
import { AuthProvider } from '@/lib/auth'
import { OrganizationScopedQueries } from '@/lib/OrganizationScopedQueries'

/**
 * Rendering a screen the way the application renders it.
 *
 * The providers are the real ones. A test that swapped `useAuth` for a stub
 * would prove that a component reads a mock correctly and nothing about
 * whether the permissions the server actually sends reach the navigation.
 *
 * The query cache is the real per-account one too, so a test of switching
 * accounts exercises the same remount the application performs.
 */

export function testQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      // A retried failure turns a two-millisecond assertion into a timeout,
      // and a cached one leaks between tests.
      queries: { retry: false, gcTime: 0, staleTime: 0 },
      mutations: { retry: false },
    },
  })
}

export function renderWithProviders(
  ui: ReactElement,
  {
    route = '/',
    signedIn = true,
    organizationId = 'org_1',
  }: { route?: string; signedIn?: boolean; organizationId?: string | null } = {},
) {
  if (signedIn) {
    // The client only sends the bearer token and the tenant header when this
    // is present, so the session is established the same way the sign-in
    // screen establishes it.
    storeAuth({ token: 'test-token', organizationId })
  }

  // Every client this render creates, in order, so a test can assert that an
  // account switch left the first one empty and started a second.
  const clients: QueryClient[] = []

  const create = () => {
    const client = testQueryClient()
    clients.push(client)

    return client
  }

  const wrapper = ({ children }: { children: ReactNode }) => (
    <MemoryRouter initialEntries={[route]}>
      <AuthProvider>
        <OrganizationScopedQueries create={create}>{children}</OrganizationScopedQueries>
      </AuthProvider>
    </MemoryRouter>
  )

  const result = render(ui, { wrapper })

  return {
    get client() {
      return clients[clients.length - 1]
    },
    clients,
    ...result,
  }
}
