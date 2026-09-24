import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ChannelsPage } from '@/pages/ChannelsPage'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { page, stubApi } from '@/test/server'

/**
 * Distribution, and whether it is real.
 *
 * This carries the honesty rule with the sharpest consequence in the product.
 * Every major OTA requires a commercial partner agreement before its API can be
 * used, so without credentials those channels run against a local simulation.
 * An operator who believes this platform is holding their Airbnb calendar open
 * when it is not will double-book a real guest into a real flat.
 *
 * So: the warning sits on the connection row itself, connected or not, and a
 * verification answered by the simulation says so in the same breath as
 * "successful".
 */
function account(overrides: Record<string, unknown> = {}) {
  return {
    id: 'cha_1',
    channel: 'airbnb',
    name: 'Airbnb — Lisbon portfolio',
    status: 'connected',
    is_connected: true,
    has_credentials: true,
    commission_percent: 15,
    collects_payment: true,
    listings_count: 4,
    last_verified_at: '2025-06-01T09:00:00+00:00',
    last_synced_at: '2025-06-01T09:00:00+00:00',
    last_error: null,
    ...overrides,
  }
}

function available(overrides: Record<string, unknown> = {}) {
  return {
    channel: 'airbnb',
    name: 'Airbnb',
    is_live: false,
    simulation_reason:
      'No partner agreement exists, so this channel runs against a local simulation.',
    capabilities: ['listings', 'availability', 'rates'],
    connected: true,
    ...overrides,
  }
}

function renderChannels({
  accounts = [account()],
  catalogue = [available()],
  permissions = ['*'],
}: {
  accounts?: ReturnType<typeof account>[]
  catalogue?: ReturnType<typeof available>[]
  permissions?: string[]
} = {}) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    'GET channels': { body: page(accounts) },
    'GET channels/available': { body: { data: catalogue } },
    'GET channel-sync/health': {
      body: {
        data: {
          window_hours: 24,
          jobs: {},
          accounts: {},
          listings_behind: 0,
          listings_failing: 0,
          retries_waiting: 0,
        },
      },
    },
    'GET channel-listings': { body: page([]) },
  })

  renderWithProviders(<ChannelsPage />)

  return server
}

async function connectionRow(name: string) {
  const cell = await screen.findByText(name)

  return cell.closest('tr') as HTMLElement
}

describe('channel honesty', () => {
  it('marks a connection whose adapter is a simulation', async () => {
    renderChannels()

    const row = await connectionRow('Airbnb — Lisbon portfolio')

    expect(within(row).getByText('Simulated')).toBeInTheDocument()
  })

  it('says why, on the row, rather than only in a list further down', async () => {
    renderChannels()

    const row = await connectionRow('Airbnb — Lisbon portfolio')

    expect(
      within(row).getByText(/No partner agreement exists/),
    ).toBeInTheDocument()
  })

  it('warns even when the connection reports itself as connected', async () => {
    // The most dangerous row on the screen: everything looks healthy and
    // nothing is reaching Airbnb.
    renderChannels({
      accounts: [account({ status: 'connected', is_connected: true, has_credentials: true })],
    })

    const row = await connectionRow('Airbnb — Lisbon portfolio')

    expect(within(row).getByText('connected')).toBeInTheDocument()
    expect(within(row).getByText('Simulated')).toBeInTheDocument()
  })

  it('leaves the warning off a channel that really does connect', async () => {
    renderChannels({
      accounts: [account({ id: 'cha_2', channel: 'direct', name: 'Direct bookings' })],
      catalogue: [
        available({ channel: 'direct', name: 'Direct', is_live: true, simulation_reason: null }),
      ],
    })

    const row = await connectionRow('Direct bookings')

    expect(within(row).queryByText('Simulated')).not.toBeInTheDocument()
  })

  it('says who holds the guest’s money', async () => {
    // It decides whether a booking produces cash or a receivable, so it is
    // stated rather than left to the commission column to imply.
    renderChannels({ accounts: [account({ collects_payment: true })] })

    const row = await connectionRow('Airbnb — Lisbon portfolio')

    expect(within(row).getByText('Channel collects')).toBeInTheDocument()
  })

  it('admits when a successful verification was answered by the simulation', async () => {
    const server = renderChannels()

    server.on('POST channels/cha_1/verify', {
      body: {
        data: {
          successful: true,
          message: 'Credentials accepted.',
          is_simulated: true,
          simulation_reason: 'No partner agreement exists.',
        },
      },
    })

    await screen.findByText('Airbnb — Lisbon portfolio')

    await userEvent.click(screen.getByRole('button', { name: /Verify/i }))

    // "Credentials accepted" on its own is the single most misleading sentence
    // this screen could print.
    expect(
      await screen.findByText(/Credentials accepted\.\s*\(answered by a local simulation\)/),
    ).toBeInTheDocument()
  })
})
