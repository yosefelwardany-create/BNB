import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { PlatformOverviewPage } from '@/pages/platform/PlatformOverviewPage'
import { platformOverview, session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * The platform at a glance.
 *
 * Two things this screen must not do, both of which would be easy and both of
 * which would flatter the business into a wrong decision:
 *
 *  - Add euros to dollars. A single "total volume" figure is wrong in every
 *    currency it contains.
 *  - Present what customers transacted as what the platform earns. The two
 *    differ by orders of magnitude.
 */
function renderOverview(overrides: Parameters<typeof platformOverview>[0] = {}) {
  stubApi({
    'GET auth/me': { body: session({ is_platform_admin: true }) },
    'GET platform/overview': { body: { data: platformOverview(overrides) } },
    'GET platform/growth': { body: { data: [{ month: '2025-06', created: 4 }] } },
  })

  renderWithProviders(<PlatformOverviewPage />)
}

describe('customer transaction volume', () => {
  it('shows each currency on its own line and no total', async () => {
    renderOverview({
      customer_transaction_volume: {
        period: 'June 2025',
        by_currency: [
          { currency: 'EUR', amount: 1_250_000, payments: 84 },
          { currency: 'GBP', amount: 480_000, payments: 31 },
        ],
      },
    })

    expect(await screen.findByText('€12,500.00')).toBeInTheDocument()
    expect(screen.getByText('£4,800.00')).toBeInTheDocument()

    // 1,730,000 minor units summed across two currencies is a number that is
    // wrong in both of them, so it must appear nowhere.
    expect(screen.queryByText(/17,300\.00/)).not.toBeInTheDocument()
  })

  it('says on the card that this is not platform revenue', async () => {
    renderOverview()

    expect(
      await screen.findByText(/Money our customers took, not platform revenue/),
    ).toBeInTheDocument()
  })

  it('says nothing was captured rather than showing a zero', async () => {
    renderOverview()

    expect(await screen.findByText('Nothing captured this month.')).toBeInTheDocument()
  })
})

describe('what leads the page', () => {
  it('raises expired trials, because each one is a conversation somebody owes', async () => {
    renderOverview({
      organizations: {
        total: 42,
        by_status: { active: 38, trialing: 3, suspended: 1 },
        expired_trials: 3,
        new_this_month: 4,
      },
    })

    expect(await screen.findByText(/3 trial\(s\) have expired/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Show them' })).toHaveAttribute(
      'href',
      '/platform/tenants?expired_trials=1',
    )
  })

  it('says nothing when no trial has lapsed', async () => {
    renderOverview()

    // Waited for, so this asserts against the loaded page rather than against
    // the spinner it would otherwise catch.
    await screen.findByText('Nothing captured this month.')

    expect(screen.queryByText(/trial\(s\) have expired/)).not.toBeInTheDocument()
  })
})
