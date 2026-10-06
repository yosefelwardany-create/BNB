import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { App } from '@/App'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { page, stubApi } from '@/test/server'

/**
 * Routing.
 *
 * Three kinds of person sign in. The platform owner gets the operational
 * workspace, scoped to whichever client account is selected, plus the
 * Accounts screen that used to be a separate console. A client gets the
 * read-only portal. Staff of a client company (if any remain) get the
 * workspace without Accounts. The old console address answers nothing of its
 * own any more.
 */
function stub(overrides: Parameters<typeof session>[0] = {}, extra: Record<string, { body: unknown }> = {}) {
  return stubApi({
    'GET auth/me': { body: session(overrides) },
    'GET platform/organizations': { body: page([]) },
    ...extra,
  })
}

describe('routing', () => {
  it('sends an unauthenticated visitor to sign in', async () => {
    stubApi({})

    renderWithProviders(<App />, { route: '/reservations', signedIn: false })

    expect(await screen.findByLabelText(/Email/i)).toBeInTheDocument()
  })

  it('shows the workspace to a signed-in user', async () => {
    stub({ permissions: ['reservations.view'] })

    renderWithProviders(<App />, { route: '/' })

    expect(await screen.findByRole('link', { name: 'Reservations' })).toBeInTheDocument()
  })

  it('does not show account administration to an ordinary administrator', async () => {
    stub({ permissions: ['*'], is_platform_admin: false })

    renderWithProviders(<App />, { route: '/accounts' })

    // The route is not registered for them, so the workspace's own not-found
    // screen answers — and the sidebar offers no way there.
    expect(await screen.findByText('Page not found')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Accounts' })).not.toBeInTheDocument()
  })

  it('answers the old console address with the workspace', async () => {
    stub({ permissions: ['*'], is_platform_admin: true })

    renderWithProviders(<App />, { route: '/platform/tenants' })

    expect(await screen.findByRole('link', { name: 'Dashboard' })).toBeInTheDocument()
    expect(screen.queryByText('Platform console')).not.toBeInTheDocument()
  })

  it('sends the platform owner with no client accounts to Accounts', async () => {
    // Nothing for the operational screens to be about yet: the only useful
    // thing to do is create the first client.
    stub({ permissions: [], is_platform_admin: true, organization: null, organizations: [] })

    renderWithProviders(<App />, { route: '/', organizationId: null })

    expect(await screen.findByRole('heading', { name: 'Accounts' })).toBeInTheDocument()
  })

  it('gives the platform owner the workspace scoped to the selected account', async () => {
    stub({ permissions: [], is_platform_admin: true })

    renderWithProviders(<App />, { route: '/' })

    expect(await screen.findByRole('link', { name: 'Accounts' })).toBeInTheDocument()
    // Every permission-gated screen, because the owner holds them all.
    expect(screen.getByRole('link', { name: 'Channels' })).toBeInTheDocument()
    // And it says whose account this is.
    expect(screen.getAllByText('Managing').length).toBeGreaterThan(0)
    expect(screen.getByLabelText('Switch account')).toBeInTheDocument()
  })

  it('gives the platform owner the workspace even if a membership says portal', async () => {
    // The owner may hold a client-portal membership somewhere (a test account,
    // say). They still operate from the workspace.
    const base = session()

    stub({
      permissions: [],
      is_platform_admin: true,
      membership: { ...base.membership!, default_portal: 'owner' },
    })

    renderWithProviders(<App />, { route: '/' })

    expect(await screen.findByRole('link', { name: 'Accounts' })).toBeInTheDocument()
  })

  it('does not leave a signed-in user on the sign-in screen', async () => {
    stub({ permissions: [] })

    renderWithProviders(<App />, { route: '/login' })

    expect(await screen.findByRole('link', { name: 'Dashboard' })).toBeInTheDocument()
  })
})
