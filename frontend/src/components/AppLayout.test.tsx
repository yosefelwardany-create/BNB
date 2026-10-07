import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
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
 * The one that is not a courtesy is the Accounts link. What lies behind it
 * affects every client, and it must not appear for anybody who is not the
 * platform owner.
 */
async function renderLayout(overrides: Parameters<typeof session>[0] = {}) {
  stubApi({
    'GET auth/me': { body: session(overrides) },
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
    expect(screen.queryByText('Configure')).not.toBeInTheDocument()
    expect(screen.getByText('Operate')).toBeInTheDocument()
  })

  it('needs only one of the permissions a link lists', async () => {
    // Financials lists three; holding any of them means there is a tab worth
    // opening.
    await renderLayout({ permissions: ['owner_statements.view'] })

    // Behind the folded Money section, so this opens it: the claim under test
    // is that one of three permissions is enough, not that it is on screen
    // without asking.
    await userEvent.click(screen.getByRole('button', { name: /Money/ }))

    expect(screen.getByRole('link', { name: 'Financials' })).toBeInTheDocument()
  })

  it('offers no subscription screen: there is no subscription', async () => {
    await renderLayout({ permissions: ['*'] })

    expect(screen.queryByRole('link', { name: 'Subscription' })).not.toBeInTheDocument()
  })

  it('shows everything to a role holding the wildcard', async () => {
    await renderLayout({ permissions: ['*'] })

    for (const label of ['Calendar', 'Inbox', 'Owners', 'Channels']) {
      expect(screen.getByRole('link', { name: label })).toBeInTheDocument()
    }
  })

  it('does not offer account administration to an ordinary administrator', async () => {
    await renderLayout({ permissions: ['*'], is_platform_admin: false })

    expect(screen.queryByRole('link', { name: 'Accounts' })).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Switch account')).not.toBeInTheDocument()
  })

  it('offers it, and the account selector, to the platform owner', async () => {
    await renderLayout({ permissions: [], is_platform_admin: true })

    expect(screen.getByRole('link', { name: 'Accounts' })).toBeInTheDocument()
    expect(screen.getByLabelText('Switch account')).toBeInTheDocument()
    expect(screen.getAllByText('Managing').length).toBeGreaterThan(0)
  })

  it('offers the organization switcher only when there is something to switch to', async () => {
    await renderLayout({ permissions: [] })

    // A select with one option is a control that does nothing.
    expect(screen.queryByLabelText('Switch organization')).not.toBeInTheDocument()
  })
})

/**
 * Folding the sections that are not the point.
 *
 * From the Oct 1 review: the platform's claim is the properties and the agents
 * running them, and a sidebar that gives finances the same weight as the thing
 * people open fifty times a day buries it. Folded, never removed — and never
 * folded over the page somebody is actually on.
 */
describe('the folded sidebar', () => {
  it('starts with the money section shut and the working sections open', async () => {
    await renderLayout({ permissions: ['*'] })

    // The agent-centric half is what the platform opens on.
    expect(screen.getByRole('link', { name: 'Agents' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Properties' })).toBeInTheDocument()

    // Folded away, not taken away.
    expect(screen.queryByRole('link', { name: 'Revenue' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Money/ })).toHaveAttribute('aria-expanded', 'false')
  })

  it('opens when asked, and the links are reachable again', async () => {
    await renderLayout({ permissions: ['*'] })

    await userEvent.click(screen.getByRole('button', { name: /Money/ }))

    expect(screen.getByRole('link', { name: 'Revenue' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Reports' })).toBeInTheDocument()
  })
})
