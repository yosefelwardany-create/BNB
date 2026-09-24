import type { ReactElement, ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { render } from '@testing-library/react'
import { storeAuth } from '@/api/client'
import { AuthProvider } from '@/lib/auth'

/**
 * Rendering a screen the way the application renders it.
 *
 * The providers are the real ones. A test that swapped `useAuth` for a stub
 * would prove that a component reads a mock correctly and nothing about
 * whether the permissions the server actually sends reach the navigation.
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
  { route = '/', signedIn = true }: { route?: string; signedIn?: boolean } = {},
) {
  if (signedIn) {
    // The client only sends the bearer token and the tenant header when this
    // is present, so the session is established the same way the sign-in
    // screen establishes it.
    storeAuth({ token: 'test-token', organizationId: 'org_1' })
  }

  const client = testQueryClient()

  const wrapper = ({ children }: { children: ReactNode }) => (
    <MemoryRouter initialEntries={[route]}>
      <QueryClientProvider client={client}>
        <AuthProvider>{children}</AuthProvider>
      </QueryClientProvider>
    </MemoryRouter>
  )

  return { client, ...render(ui, { wrapper }) }
}
