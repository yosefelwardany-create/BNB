import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ChannelsPage } from '@/pages/ChannelsPage'
import type { AvailableChannel, ChannelAccount } from '@/api/types'
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


/*
|------------------------------------------------------------------------------
| Connecting a channel, and which direction it runs
|------------------------------------------------------------------------------
|
| Separate fixtures from the honesty tests above, because these are about a
| live adapter rather than a simulated one — and because the thing under test
| is the direction of the connection, not whether it is real.
|
| Importing is safe. Pushing replaces what the channel is showing, and a channel
| manager treats whatever it receives as the truth: a push from an empty
| calendar says "everything is available" over nights that are sold. So a new
| connection imports and does not push, the row says which it is doing, and
| turning on push is a separate act on a separate form.
|
*/

function hostexAccount(overrides: Partial<ChannelAccount> = {}): ChannelAccount {
  return {
    id: 'cha_1',
    channel: 'hostex',
    name: 'Hostex',
    status: 'connected',
    is_connected: true,
    has_credentials: true,
    external_account_id: null,
    webhook_url: null,
    webhook_secret_set: false,
    sync_availability: false,
    sync_rates: false,
    import_reservations: true,
    export_reservations: false,
    sync_messages: false,
    commission_basis_points: 0,
    commission_percent: 0,
    collects_payment: false,
    listings_count: 1,
    connected_at: '2026-10-02T18:47:47+00:00',
    last_verified_at: '2026-10-02T18:47:47+00:00',
    last_synced_at: null,
    last_imported_at: null,
    last_error: null,
    created_at: '2026-10-02T18:47:47+00:00',
    ...overrides,
  }
}

const HOSTEX: AvailableChannel = {
  channel: 'hostex',
  name: 'Hostex',
  is_live: true,
  simulation_reason: null,
  capabilities: ['messaging'],
  connected: true,
}

const SIMULATED_AIRBNB: AvailableChannel = {
  channel: 'airbnb',
  name: 'Airbnb',
  is_live: false,
  simulation_reason: 'Airbnb requires a signed partner agreement before its API can be used.',
  capabilities: [],
  connected: false,
}

function renderHostex(accounts: ChannelAccount[]) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions: ['*'] }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    'GET channels': { body: page(accounts) },
    'GET channels/available': { body: { data: [HOSTEX, SIMULATED_AIRBNB] } },
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
    'GET listings': { body: page([]) },
    'POST channels': { status: 201, body: { data: hostexAccount() } },
    'PATCH channels/cha_1': { body: { data: hostexAccount({ sync_availability: true }) } },
  })

  renderWithProviders(<ChannelsPage />)

  return server
}

/**
 * The connection row, found by its name.
 *
 * `findByText` alone is ambiguous here: a row prints the connection's name and,
 * underneath it, the channel's own label — which for Hostex is the same word
 * twice. Taking the first match that sits inside a row does not depend on which
 * of the two the query happened to reach first.
 */
async function hostexRowFor(name: string) {
  const matches = await screen.findAllByText(name)
  const row = matches.map((node) => node.closest('tr')).find((node) => node !== null)

  if (!row) throw new Error(`No connection row for ${name}`)

  return row
}

describe('what a connection row says', () => {
  it('marks a connection that only reads as import only', async () => {
    renderHostex([hostexAccount()])

    const row = await hostexRowFor('Hostex')

    expect(within(row).getByText('Import only')).toBeInTheDocument()
  })

  it('calls out a connection that pushes, because that is the direction that overwrites', async () => {
    renderHostex([hostexAccount({ sync_availability: true, sync_rates: true })])

    const row = await hostexRowFor('Hostex')

    expect(within(row).getByText('Pushes dates and rates')).toBeInTheDocument()
  })

  it('distinguishes pushing dates from pushing rates', async () => {
    renderHostex([hostexAccount({ sync_availability: true })])

    const row = await hostexRowFor('Hostex')

    expect(within(row).getByText('Pushes dates')).toBeInTheDocument()
  })
})

describe('a listing nothing here matches', () => {
  function unmapped() {
    return {
      id: 'map_1',
      channel_account_id: 'cha_1',
      property_id: null,
      listing_id: null,
      external_listing_id: 'basha-1745049255402634963',
      external_name: 'New Private Room + Gym + Pool, 1 min to subway',
      status: 'listed',
      is_active: false,
      availability_dirty: false,
      rates_dirty: false,
      is_failing: false,
      consecutive_failures: 0,
      last_error: null,
    }
  }

  it('offers to build the property from what the channel said', async () => {
    const server = stubApi({
      'GET auth/me': { body: session({ permissions: ['*'] }) },
      'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
      'GET channels': { body: page([hostexAccount()]) },
      'GET channels/available': { body: { data: [HOSTEX] } },
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
      'GET channel-listings': { body: page([unmapped()]) },
      'GET listings': { body: page([]) },
      'POST channel-listings/map_1/adopt': {
        status: 201,
        body: { message: 'Light Green Room was created from this listing.', needs: null },
      },
    })

    renderWithProviders(<ChannelsPage />)

    /*
     * The dead end this closes.
     *
     * Before, a first connection discovered a listing, matched it to nothing —
     * because there was nothing to match — and left a row the operator could not
     * act on. No property meant no bookings, no guest names, and an agent with
     * nowhere to live.
     */
    await userEvent.click(
      await screen.findByRole('button', { name: /Create a property from this/ }),
    )

    expect(server.callsTo('POST', 'channel-listings/map_1/adopt')).toHaveLength(1)
    expect(
      await screen.findByText(/Light Green Room was created from this listing/),
    ).toBeInTheDocument()
  })

  it('says what is still missing when the property cannot go live', async () => {
    stubApi({
      'GET auth/me': { body: session({ permissions: ['*'] }) },
      'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
      'GET channels': { body: page([hostexAccount()]) },
      'GET channels/available': { body: { data: [HOSTEX] } },
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
      'GET channel-listings': { body: page([unmapped()]) },
      'GET listings': { body: page([]) },
      'POST channel-listings/map_1/adopt': {
        status: 201,
        body: {
          message:
            'Light Green Room was created, but it cannot take bookings yet — a base nightly rate is required. Fill that in, activate it, then pull again.',
          needs: 'A base nightly rate is required',
        },
      },
    })

    renderWithProviders(<ChannelsPage />)

    await userEvent.click(
      await screen.findByRole('button', { name: /Create a property from this/ }),
    )

    // A property created but not activated looks like a success until the first
    // booking fails to land on it, so the gap is named rather than discovered.
    expect(await screen.findByText(/cannot take bookings yet/)).toBeInTheDocument()
  })
})

describe('connecting a channel', () => {
  it('sends the token as a credential and never as a plain field', async () => {
    const server = renderHostex([])

    await userEvent.click(await screen.findByRole('button', { name: /Connect a channel/ }))

    await userEvent.selectOptions(screen.getByLabelText(/Channel/), 'hostex')
    await userEvent.type(screen.getByLabelText(/What to call it/), 'Hostex')
    await userEvent.type(screen.getByLabelText(/Access token/), 'tok-live-abc123')

    await userEvent.click(screen.getByRole('button', { name: 'Connect' }))

    const [call] = server.callsTo('POST', 'channels')
    const body = call?.body as Record<string, unknown>

    expect(body.channel).toBe('hostex')
    // Nested under `credentials`, which is write-only on the server: no endpoint
    // returns it and the row afterwards reports only that one is stored.
    expect(body.credentials).toEqual({ access_token: 'tok-live-abc123' })
    expect(body.access_token).toBeUndefined()
  })

  it('converts a typed commission percentage into basis points', async () => {
    const server = renderHostex([])

    await userEvent.click(await screen.findByRole('button', { name: /Connect a channel/ }))

    await userEvent.selectOptions(screen.getByLabelText(/Channel/), 'hostex')
    await userEvent.type(screen.getByLabelText(/What to call it/), 'Hostex')
    await userEvent.type(screen.getByLabelText(/Commission %/), '15')

    await userEvent.click(screen.getByRole('button', { name: 'Connect' }))

    const [call] = server.callsTo('POST', 'channels')
    const body = call?.body as Record<string, unknown>

    // 15% is 1500 basis points. Integer all the way down, so a commission
    // cannot drift by a rounding.
    expect(body.commission_basis_points).toBe(1500)
  })

  it('offers no way to turn on pushing while connecting', async () => {
    renderHostex([])

    await userEvent.click(await screen.findByRole('button', { name: /Connect a channel/ }))

    /*
     * The load-bearing absence.
     *
     * A connection's calendar is empty at the moment it is created, so a push
     * from it says "everything is available" over a calendar where that is
     * false. Enabling that has to come after somebody has seen what was
     * imported, which means it cannot be a checkbox on this form.
     */
    expect(screen.queryByLabelText(/Push availability/)).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/Push rates/)).not.toBeInTheDocument()
  })

  it('names a simulated channel as simulated in the picker', async () => {
    renderHostex([])

    await userEvent.click(await screen.findByRole('button', { name: /Connect a channel/ }))

    // An operator choosing Airbnb is entitled to know that connecting it here
    // exercises the sync path against a local stand-in.
    expect(screen.getByRole('option', { name: 'Airbnb (simulated)' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Hostex' })).toBeInTheDocument()
  })
})

describe('changing an existing connection', () => {
  it('is where pushing gets turned on, and only there', async () => {
    const server = renderHostex([hostexAccount()])

    const row = await hostexRowFor('Hostex')
    await userEvent.click(within(row).getByRole('button', { name: /Settings/ }))

    await userEvent.click(screen.getByLabelText(/Push availability/))
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    const [call] = server.callsTo('PATCH', 'channels/cha_1')
    const body = call?.body as Record<string, unknown>

    expect(body.sync_availability).toBe(true)
  })

  it('leaves the stored token alone when the replace field is left blank', async () => {
    const server = renderHostex([hostexAccount()])

    const row = await hostexRowFor('Hostex')
    await userEvent.click(within(row).getByRole('button', { name: /Settings/ }))

    await userEvent.click(screen.getByLabelText(/Import guest messages/))
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    const [call] = server.callsTo('PATCH', 'channels/cha_1')
    const body = call?.body as Record<string, unknown>

    // Sending an empty string would clear the credential and silently
    // disconnect the channel, so an untouched field sends nothing at all.
    expect(body.credentials).toBeUndefined()
    expect(body.sync_messages).toBe(true)
  })
})
