import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import { SubscriptionPage } from '@/pages/SubscriptionPage'
import type { TenantPlan } from '@/api/types'
import { money, session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { page, stubApi } from '@/test/server'

/**
 * The customer's side of the platform console.
 *
 * A platform that meters a customer and caps what they do without showing them
 * their plan or their usage produces a refusal out of nowhere, and their only
 * recourse is a support ticket asking what happened. So the numbers here are
 * the same numbers the operator sees, from the same service.
 *
 * The support-session list is the other half of that: read-only access to a
 * customer's account is defensible because they can see it happened, who did
 * it, and why. A record only the operator can read is not a record, it is a
 * back door with a log file.
 */
function plan(overrides: Partial<TenantPlan> = {}): TenantPlan {
  return {
    plan: {
      id: 'pln_1',
      name: 'Growth',
      slug: 'growth',
      description: 'For portfolios up to fifty properties.',
      price: money(19900),
      billing_interval: 'month',
      trial_days: 14,
      limits: { max_properties: 50, max_users: 10 },
      features: ['multi_currency'],
      is_public: true,
      is_active: true,
      position: 2,
    },
    status: 'active',
    status_label: 'Active',
    trial_ends_at: null,
    trial_has_expired: false,
    usage: {
      max_properties: { used: 12, limit: 50, remaining: 38, at_limit: false },
      max_users: { used: 10, limit: 10, remaining: 0, at_limit: true },
    },
    features: {
      multi_currency: { enabled: true, source: 'plan' },
      revenue_management: { enabled: false, source: 'plan' },
    },
    is_metered: true,
    ...overrides,
  }
}

function renderSubscription(
  data: TenantPlan = plan(),
  {
    sessions = [] as Record<string, unknown>[],
    permissions = ['organization.view'],
  }: { sessions?: Record<string, unknown>[]; permissions?: string[] } = {},
) {
  stubApi({
    'GET auth/me': { body: session({ permissions }) },
    'GET organization/plan': { body: { data, meta: { support_email: 'help@habitat.test' } } },
    'GET organization/support-sessions': { body: page(sessions) },
  })

  renderWithProviders(<SubscriptionPage />)
}

async function limitRow(label: string) {
  const cell = await screen.findByText(label)

  return cell.closest('tr') as HTMLElement
}

describe('what the customer can see about their own account', () => {
  it('shows usage against each cap', async () => {
    renderSubscription()

    const row = await limitRow('properties')

    expect(within(row).getByText('12')).toBeInTheDocument()
    expect(within(row).getByText('50')).toBeInTheDocument()
    expect(within(row).getByText('24% used')).toBeInTheDocument()
  })

  it('says outright when a limit has been reached', async () => {
    renderSubscription()

    const row = await limitRow('users')

    // So the next refusal is explicable before it happens rather than after.
    expect(within(row).getByText('At your limit')).toBeInTheDocument()
  })

  it('distinguishes unlimited from a large number', async () => {
    renderSubscription(
      plan({
        usage: {
          max_properties: { used: 12, limit: null, remaining: null, at_limit: false },
        },
      }),
    )

    const row = await limitRow('properties')

    expect(within(row).getByText('Unlimited')).toBeInTheDocument()
  })

  it('says plainly that an unmetered account is not a free tier that might cut off', async () => {
    renderSubscription(plan({ plan: null, is_metered: false }))

    expect(
      await screen.findByText(/Nothing on your account is metered/),
    ).toBeInTheDocument()

    expect(screen.queryByText('At your limit')).not.toBeInTheDocument()
  })

  it('shows which features are off, not only which are on', async () => {
    renderSubscription()

    expect(await screen.findByText('multi currency')).toHaveClass('chip--emerald')
    expect(screen.getByText('revenue management')).toHaveClass('chip--zinc')
  })

  it('warns when the trial has run out, with somewhere to write', async () => {
    renderSubscription(
      plan({ status: 'trialing', trial_ends_at: '2025-05-01', trial_has_expired: true }),
    )

    expect(await screen.findByText(/Your trial ended on 1 May 2025/)).toBeInTheDocument()
    expect(screen.getByText(/help@habitat\.test/)).toBeInTheDocument()
  })
})

describe('who has looked at the account', () => {
  it('shows the customer every support session, with its reason', async () => {
    renderSubscription(plan(), {
      sessions: [
        {
          id: 'imp_1',
          organization_id: 'org_1',
          operator: { id: 'usr_9', name: 'Platform Support', email: 'support@habitat.test' },
          reason: 'Investigating a duplicated payout reported in ticket 4821.',
          started_at: '2025-06-10T08:00:00+00:00',
          expires_at: null,
          ended_at: '2025-06-10T08:20:00+00:00',
          ended_reason: 'completed',
          is_open: false,
          request_count: 14,
          ip_address: null,
          was_read_only: true,
        },
      ],
    })

    expect(await screen.findByText('support@habitat.test')).toBeInTheDocument()
    expect(
      screen.getByText('Investigating a duplicated payout reported in ticket 4821.'),
    ).toBeInTheDocument()
  })

  it('states that those sessions cannot change anything', async () => {
    renderSubscription()

    expect(await screen.findByText(/read-only — nothing in your account can be changed/))
      .toBeInTheDocument()
  })

  it('says nobody has looked, rather than showing an empty table', async () => {
    renderSubscription()

    expect(
      await screen.findByText('Nobody from our team has looked inside your account.'),
    ).toBeInTheDocument()
  })
})
