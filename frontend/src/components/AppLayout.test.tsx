import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { AppLayout } from '@/components/AppLayout'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * The navigation.
 *
 * Hiding a link is a courtesy rather than a control — the server authorises
 * every request again — but it is the courtesy that decides whether the
 * product feels coherent or like a wall of things that answer "forbidden".
 *
 * The one that is not a courtesy is the platform console link. What lies behind
 * it affects other companies, and it must not appear for anybody who is not a
 * platform administrator.
 */
async function renderLayout(overrides: Parameters<typeof session>[0] = {}) {
  stubApi({
    'GET auth/me': { body: session(overrides) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
  })

  renderWithProviders(
    <AppLayout>
      <p>content</p>
    </AppLayout>,
  )

  await screen.findByText('Demo Hospitality Group', { selector: 'strong' })
}

describe('AppLayout navigation', () => {
  it('shows a link for each permission the user holds', async () => {
    await renderLayout({ permissions: ['reservations.view', 'properties.view'] })

    expect(screen.getByRole('link', { name: 'Reservations' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Properties' })).toBeInTheDocument()
  })

  it('hides the ones they do not', async () => {
    await renderLayout({ permissions: ['reservations.view'] })

    expect(screen.queryByRole('link', { name: 'Properties' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Revenue' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Reports' })).not.toBeInTheDocument()
  })

  it('hides a whole section when nothing in it is visible', async () => {
    await renderLayout({ permissions: ['reservations.view'] })

    // A heading over an empty space reads as a screen that failed to load.
    expect(screen.queryByText('Money')).not.toBeInTheDocument()
    expect(screen.getByText('Operate')).toBeInTheDocument()
  })

  it('needs only one of the permissions a link lists', async () => {
    // Financials lists three; holding any of them means there is a tab worth
    // opening.
    await renderLayout({ permissions: ['owner_statements.view'] })

    expect(screen.getByRole('link', { name: 'Financials' })).toBeInTheDocument()
  })

  it('shows the subscription to everybody, permission or not', async () => {
    await renderLayout({ permissions: [] })

    // Somebody who cannot add a property is still entitled to know the reason
    // is a plan cap rather than a fault.
    expect(screen.getByRole('link', { name: 'Subscription' })).toBeInTheDocument()
  })

  it('shows everything to a role holding the wildcard', async () => {
    await renderLayout({ permissions: ['*'] })

    for (const label of ['Calendar', 'Inbox', 'Owners', 'Channels', 'Revenue', 'Reports']) {
      expect(screen.getByRole('link', { name: label })).toBeInTheDocument()
    }
  })

  it('does not offer the platform console to an ordinary administrator', async () => {
    await renderLayout({ permissions: ['*'], is_platform_admin: false })

    expect(screen.queryByRole('link', { name: /Platform console/ })).not.toBeInTheDocument()
  })

  it('offers it to a platform administrator', async () => {
    await renderLayout({ permissions: [], is_platform_admin: true })

    expect(screen.getByRole('link', { name: /Platform console/ })).toBeInTheDocument()
  })

  it('offers the organization switcher only when there is something to switch to', async () => {
    await renderLayout({ permissions: [] })

    // A select with one option is a control that does nothing.
    expect(screen.queryByLabelText('Switch organization')).not.toBeInTheDocument()
  })
})
