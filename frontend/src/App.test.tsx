import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { App } from '@/App'
import { platformOverview, session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * Routing.
 *
 * The console that governs every customer is a separate shell reached at
 * /platform, and it is not part of the tenant interface. The guard in the
 * router is a courtesy — the server answers every route under /api/v1/platform
 * with a 404 unless the caller holds the flag — but a courtesy that fails open
 * shows an operator a console full of other companies' names before the first
 * request comes back, which is a disclosure in itself.
 */
function stub(overrides: Parameters<typeof session>[0] = {}) {
  return stubApi({
    'GET auth/me': { body: session(overrides) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    'GET platform/overview': { body: { data: platformOverview() } },
    'GET platform/growth': { body: { data: [] } },
  })
}

describe('routing', () => {
  it('sends an unauthenticated visitor to sign in', async () => {
    stubApi({})

    renderWithProviders(<App />, { route: '/reservations', signedIn: false })

    expect(await screen.findByLabelText(/Email/i)).toBeInTheDocument()
  })

  it('shows the tenant shell to a signed-in user', async () => {
    stub({ permissions: ['reservations.view'] })

    renderWithProviders(<App />, { route: '/' })

    expect(await screen.findByRole('link', { name: 'Reservations' })).toBeInTheDocument()
  })

  it('does not route an ordinary user into the platform console', async () => {
    stub({ permissions: ['*'], is_platform_admin: false })

    renderWithProviders(<App />, { route: '/platform' })

    // It falls through to the tenant router, which does not know the path —
    // not to a console that briefly renders before the server refuses it.
    expect(await screen.findByText('Page not found')).toBeInTheDocument()
    expect(document.querySelector('.shell--platform')).toBeNull()
  })

  it('sends a platform administrator with no tenant straight to the console', async () => {
    // The bug this covers: a platform operator holds no membership anywhere,
    // so the tenant shell has nothing to show them — every screen in it is
    // about an organization they do not belong to. They landed on an empty
    // shell with a blank company name.
    stub({ permissions: [], is_platform_admin: true, organization: null })

    renderWithProviders(<App />, { route: '/' })

    await screen.findByText('Platform console')

    expect(document.querySelector('.shell--platform')).not.toBeNull()
  })

  it('leaves a platform administrator who does have a tenant on the tenant shell', async () => {
    // Holding the flag does not mean giving up the product: somebody who both
    // operates the platform and works for a company still gets their company.
    stub({ permissions: ['*'], is_platform_admin: true })

    renderWithProviders(<App />, { route: '/' })

    expect(await screen.findByRole('link', { name: 'Subscription' })).toBeInTheDocument()
  })

  it('routes a platform administrator into it', async () => {
    stub({ permissions: [], is_platform_admin: true })

    renderWithProviders(<App />, { route: '/platform' })

    await screen.findByText('Platform console')

    expect(document.querySelector('.shell--platform')).not.toBeNull()
  })

  it('does not leave a signed-in user on the sign-in screen', async () => {
    stub({ permissions: [] })

    renderWithProviders(<App />, { route: '/login' })

    expect(await screen.findByRole('link', { name: 'Subscription' })).toBeInTheDocument()
  })
})
