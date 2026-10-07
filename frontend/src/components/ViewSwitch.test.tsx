import { afterEach, describe, expect, it } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { App } from '@/App'
import { setClientView } from '@/lib/clientView'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { page, stubApi } from '@/test/server'

/**
 * The platform owner's client view.
 *
 * A switch in the managing top bar shows the selected account through the
 * client's own read-only screens, and the same switch there brings the
 * managing screens back. Only the platform owner has it.
 */
afterEach(() => {
  setClientView(false)
})

function stub(overrides: Parameters<typeof session>[0] = {}) {
  return stubApi({
    'GET auth/me': { body: session(overrides) },
    'GET platform/organizations': { body: page([]) },
  })
}

describe('client view', () => {
  it('switches the platform owner to the client view of the account and back', async () => {
    const server = stub({ permissions: [], is_platform_admin: true })

    renderWithProviders(<App />, { route: '/' })

    const toClient = await screen.findByRole('switch', { name: 'Client view' })
    expect(toClient).toHaveAttribute('aria-checked', 'false')
    expect(screen.getByRole('link', { name: 'Channels' })).toBeInTheDocument()

    await userEvent.click(toClient)

    // The client's screens, read through the portal, and nothing operational.
    expect(await screen.findByRole('link', { name: 'Money' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Channels' })).not.toBeInTheDocument()
    expect(screen.getByText('Client view of')).toBeInTheDocument()
    expect(screen.getByText('read-only')).toBeInTheDocument()
    await waitFor(() => expect(server.callsTo('GET', 'portal/owner/summary').length).toBeGreaterThan(0))

    const toManaging = screen.getByRole('switch', { name: 'Client view' })
    expect(toManaging).toHaveAttribute('aria-checked', 'true')

    await userEvent.click(toManaging)

    expect(await screen.findByRole('link', { name: 'Channels' })).toBeInTheDocument()
    expect(screen.getByRole('switch', { name: 'Client view' })).toHaveAttribute('aria-checked', 'false')
  })

  it('is not offered to a client, whose screens are the client view already', async () => {
    const base = session()

    stub({ permissions: [], membership: { ...base.membership!, default_portal: 'owner' } })

    renderWithProviders(<App />, { route: '/' })

    expect(await screen.findByRole('link', { name: 'Money' })).toBeInTheDocument()
    expect(screen.queryByRole('switch', { name: 'Client view' })).not.toBeInTheDocument()
  })

  it('is not offered to staff of a client company', async () => {
    stub({ permissions: ['*'], is_platform_admin: false })

    renderWithProviders(<App />, { route: '/' })

    expect(await screen.findByRole('link', { name: 'Dashboard' })).toBeInTheDocument()
    expect(screen.queryByRole('switch', { name: 'Client view' })).not.toBeInTheDocument()
  })
})
