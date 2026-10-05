import { useMemo, useState } from 'react'
import { HostexSyncDiagnostics } from '@/components/HostexSyncDiagnostics'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link2, Plug, Plus, Settings2 } from 'lucide-react'
import { Link } from 'react-router-dom'
import { api, ApiError } from '@/api/client'
import type {
  AvailableChannel,
  ChannelAccount,
  ChannelListing,
  Paginated,
  SyncHealth,
} from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { RecordDialog } from '@/components/RecordDialog'
import type { FieldSpec, RecordValues } from '@/components/RecordDialog'
import { useListingOptions } from '@/lib/options'
import { useRecordDialog } from '@/lib/useRecordDialog'
import { formatNumber } from '@/lib/format'
import { useAuth } from '@/lib/auth'

interface VerifyResult {
  data: {
    successful: boolean
    message: string
    is_simulated: boolean
    simulation_reason: string | null
  }
}

/**
 * Distribution.
 *
 * The honesty rule that matters most in the whole product lives on this
 * screen. Every major OTA requires a commercial partner agreement before its
 * API can be used, so until credentials exist those channels run against a
 * local simulation. The interface says so, per channel, next to the connection
 * — because an operator who believes their Airbnb calendar is being held open
 * by this platform when it is not will double-book a real guest.
 */
/**
 * What one pull brought in, per stage.
 *
 * Stages are reported separately because they fail separately: a channel that
 * cannot serve its inbox still served the bookings, and a single
 * success-or-failure line would throw that away.
 */
interface PullOutcome {
  [stage: string]: unknown
}

function latestPull(local: PullOutcome | undefined, saved: PullOutcome | null | undefined): PullOutcome {
  if (saved && typeof saved.at === 'string' && local && typeof local.at === 'string'
    && saved.at >= local.at) return saved
  return local ?? saved ?? {}
}

/**
 * The pull outcome as lines an operator can read.
 *
 * "Skipped" and "nothing found" are kept apart on purpose: they look the same in
 * a count and are nothing alike on a screen that claims the inbox is empty.
 */
function describePull(outcome: PullOutcome): { stage: string; text: string; failed: boolean }[] {
  if (outcome.status === 'queued') return [{ stage: 'queued', failed: false, text: 'Import queued. It will start on the next background check; you can leave this page.' }]
  return ['listings', 'properties', 'availability', 'reservations', 'transactions', 'messages'].map((stage) => {
    if (outcome.status === 'running' && !outcome[stage]) {
      return { stage, failed: false, text: stage === outcome.current_stage ? `${stage}: running…` : `${stage}: waiting for the current pull.` }
    }
    const result = (outcome[stage] ?? {}) as Record<string, unknown>

    if (typeof result.failed === 'string') {
      return {
        stage,
        failed: true,
        text: `${stage}: ${result.failed}${result.retryable === true ? ' (worth trying again)' : ''}`,
      }
    }

    if (typeof result.skipped === 'string') {
      return { stage, failed: false, text: `${stage}: ${result.skipped}` }
    }

    const counts = Object.entries(result)
      .filter(([, value]) => typeof value === 'number')
      .map(([key, value]) => `${String(value)} ${key}`)
      .join(', ')

    const issues: unknown[] = Array.isArray(result.issues) ? result.issues as unknown[] : []
    const unavailable: unknown[] = Array.isArray(result.unavailable) ? result.unavailable as unknown[] : []
    const notes = [...issues, ...unavailable].filter((note): note is string => typeof note === 'string')
    const coverage = result.coverage && typeof result.coverage === 'object' ? ` Coverage: ${Object.entries(result.coverage).map(([key, value]) => `${key}: ${String(value)}`).join(', ')}.` : ''
    return { stage, failed: typeof result.failed === 'number' && result.failed > 0, text: `${stage}: ${counts === '' ? 'nothing new' : counts}.${coverage} ${notes.join(' ')}` }
  })
}

export function ChannelsPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()

  const [verified, setVerified] = useState<Record<string, VerifyResult['data']>>({})
  // What the last pull on each connection brought in, kept per row so the answer
  // sits next to the button that was pressed.
  const [pulled, setPulled] = useState<Record<string, PullOutcome>>({})
  const [pulling, setPulling] = useState(false)
  // What adopting a listing produced, kept per row so the answer — including
  // what still has to be filled in — sits beside the button that was pressed.
  const [adopted, setAdopted] = useState<Record<string, string>>({})

  const accounts = useQuery({
    queryKey: ['channels'],
    queryFn: () => api.get<Paginated<ChannelAccount>>('channels', { per_page: 50 }),
    refetchInterval: (query) => pulling || query.state.data?.data.some((account) => ['queued', 'running'].includes(String(account.last_pull_result?.status))) ? 5000 : query.state.data?.data.some((account) => account.automatic_sync_interval_minutes) ? 30000 : false,
  })

  const available = useQuery({
    queryKey: ['channels-available'],
    queryFn: () => api.get<{ data: AvailableChannel[] }>('channels/available'),
  })

  const health = useQuery({
    queryKey: ['channel-sync-health'],
    queryFn: () => api.get<{ data: SyncHealth }>('channel-sync/health'),
  })

  const mappings = useQuery({
    queryKey: ['channel-listings'],
    queryFn: () => api.get<Paginated<ChannelListing>>('channel-listings', { per_page: 100 }),
  })

  const { options: listings } = useListingOptions()
  const mapDialog = useRecordDialog<never>()
  const connectDialog = useRecordDialog<never>()
  // Carries the whole account, not an id: the form is seeded from its current
  // values, so the record itself is what the dialog needs.
  const settingsDialog = useRecordDialog<ChannelAccount>()

  /*
   * Connecting a channel.
   *
   * The credential is typed into a flat field and nested on the way out,
   * because the API takes a `credentials` object whose keys differ per channel
   * and this form holds scalars. It is write-only on both sides: no endpoint
   * ever returns it, and the row afterwards reports only whether one is stored.
   */
  const connectFields: FieldSpec[] = useMemo(
    () => [
      {
        name: 'channel',
        label: 'Channel',
        type: 'select',
        required: true,
        options: (available.data?.data ?? []).map((entry) => ({
          value: entry.channel,
          label: entry.is_live ? entry.name : `${entry.name} (simulated)`,
        })),
        hint: 'A channel marked simulated exercises the whole sync path against a local stand-in. Nothing reaches a guest.',
      },
      {
        name: 'name',
        label: 'What to call it',
        type: 'text',
        required: true,
        placeholder: 'Hostex',
        hint: 'Yours, not theirs. It appears on every mapping and sync record.',
      },
      {
        name: 'access_token',
        label: 'Access token',
        type: 'text',
        hint: 'Hostex issues one under Workplace → Open API. It is shown once there and stored encrypted here; no screen ever shows it again.',
      },
      {
        name: 'commission_percent',
        label: 'Commission %',
        type: 'number',
        hint: 'What the channel keeps. Stored as basis points so it cannot drift by a rounding.',
      },
      {
        name: 'collects_payment',
        label: 'The channel collects the guest’s money',
        type: 'checkbox',
        hint: 'Decides whether a booking here produces cash you hold or money the channel owes you. Wrong, it misstates the bank balance by every booking they send.',
      },
      {
        name: 'import_reservations',
        label: 'Import bookings',
        type: 'checkbox',
        hint: 'On by default. This is what connecting is for.',
      },
      { name: 'auto_import_properties', label: 'Automatically create new Hostex properties', type: 'checkbox', hint: 'Create local drafts and fill available details, photos and calendars during import. Existing mappings are reused; nothing is published.' },
      {
        name: 'sync_messages',
        label: 'Import guest messages',
        type: 'checkbox',
        hint: 'Off by default. Turning it on moves your inbox here, which is a change of habit as much as a setting.',
      },
    ],
    [available.data],
  )

  const connect = useMutation({
    mutationFn: (values: RecordValues) => {
      const { access_token: token, commission_percent: percent, ...rest } = values

      return api.post<{ data: ChannelAccount }>('channels', {
        ...rest,
        // Percent in, basis points out: 15 becomes 1500. Integer arithmetic all
        // the way down, so a commission cannot drift by a rounding.
        commission_basis_points: Math.round(Number(percent ?? 0) * 100),
        ...(typeof token === 'string' && token.trim() !== ''
          ? { credentials: { access_token: token.trim() } }
          : {}),
      })
    },
    onSuccess: (result) => {
      connectDialog.close()
      void queryClient.invalidateQueries({ queryKey: ['channels'] })
      void queryClient.invalidateQueries({ queryKey: ['channels-available'] })
      if (result.data?.channel === 'hostex' && result.data.is_connected && result.data.auto_import_properties) {
        pull.mutate(result.data)
      }
    },
  })

  /*
   * Changing what an existing connection does.
   *
   * The two push toggles live here and nowhere else, so turning on outbound
   * sync is a separate, deliberate act after somebody has seen what came in —
   * never something that happens as a side effect of connecting.
   */
  const settingsFields: FieldSpec[] = useMemo(
    () => [
      {
        name: 'name',
        label: 'What to call it',
        type: 'text',
        required: true,
      },
      {
        name: 'access_token',
        label: 'Replace the access token',
        type: 'text',
        hint: 'Leave blank to keep the stored one. Anything typed here replaces it.',
      },
      { name: 'auto_import_properties', label: 'Automatically create new Hostex properties', type: 'checkbox', hint: 'Hostex imports create local drafts, fill supplied fields and import calendars. No outbound sync is enabled.' },
      {
        name: 'import_reservations',
        label: 'Import bookings',
        type: 'checkbox',
      },
      {
        name: 'sync_messages',
        label: 'Import guest messages',
        type: 'checkbox',
      },
      {
        name: 'sync_availability',
        label: 'Push availability out to this channel',
        type: 'checkbox',
        hint: 'Only once this platform’s calendar is right. A channel manager becomes the source of truth the moment you push to it, and an empty calendar means “everything is available” — which re-opens nights that are sold.',
      },
      {
        name: 'sync_rates',
        label: 'Push rates out to this channel',
        type: 'checkbox',
        hint: 'Same rule. Your rates here replace whatever the channel is showing, for every night.',
      },
      {
        name: 'commission_percent',
        label: 'Commission %',
        type: 'number',
      },
      {
        name: 'collects_payment',
        label: 'The channel collects the guest’s money',
        type: 'checkbox',
      },
    ],
    [],
  )

  const saveSettings = useMutation({
    mutationFn: (values: RecordValues) => {
      const { access_token: token, commission_percent: percent, ...rest } = values

      return api.patch(`channels/${settingsDialog.editing?.id ?? ''}`, {
        ...rest,
        ...(percent === undefined
          ? {}
          : { commission_basis_points: Math.round(Number(percent) * 100) }),
        // Absent means keep what is stored. Sending an empty string would clear
        // the credential and silently disconnect the channel.
        ...(typeof token === 'string' && token.trim() !== ''
          ? { credentials: { access_token: token.trim() } }
          : {}),
      })
    },
    onSuccess: () => {
      settingsDialog.close()
      void queryClient.invalidateQueries({ queryKey: ['channels'] })
    },
  })

  const mappingFields: FieldSpec[] = useMemo(
    () => [
      {
        name: 'channel_account_id',
        label: 'Connection',
        type: 'select',
        required: true,
        options: (accounts.data?.data ?? []).map((account) => ({
          value: account.id,
          label: account.name,
        })),
      },
      {
        name: 'listing_id',
        label: 'Our listing',
        type: 'select',
        options: listings,
        required: true,
        emptyHint: (
          <>
            There is nothing here to link yet. Add a property on{' '}
            <Link to="/properties">Properties</Link> — it gets a listing of its own, which
            then appears in this picker.
          </>
        ),
      },
      {
        name: 'external_listing_id',
        label: 'Their listing ID',
        type: 'text',
        required: true,
        hint: 'For Hostex, use the Hostex property ID shown in the imported row. This is different from the Airbnb listing ID.',
      },
      { name: 'external_name', label: 'Their name for it', type: 'text' },
      { name: 'external_url', label: 'Link to the listing', type: 'text', placeholder: 'https://…' },
    ],
    [accounts.data, listings],
  )

  const mapListing = useMutation({
    mutationFn: (values: RecordValues) => api.post('channel-listings', values),
    onSuccess: () => {
      mapDialog.close()
      void queryClient.invalidateQueries()
    },
  })

  const verify = useMutation({
    mutationFn: (account: ChannelAccount) =>
      api.post<VerifyResult>(`channels/${account.id}/verify`),
    onSuccess: (result, account) => {
      setVerified((previous) => ({ ...previous, [account.id]: result.data }))
      void queryClient.invalidateQueries({ queryKey: ['channels'] })
    },
  })

  const push = useMutation({
    mutationFn: (account: ChannelAccount) => api.post(`channels/${account.id}/push`),
    onSuccess: () => {
      void queryClient.invalidateQueries()
      void queryClient.invalidateQueries({ queryKey: ['channel-sync-health'] })
    },
  })

  /*
   * Bring the channel's world in now.
   *
   * The counterpart to "Push now", and the one an operator wants immediately
   * after connecting. The background worker imports Hostex every five minutes.
   * `full` asks for
   * everything, because a connection made this morning has months behind it that
   * no webhook will ever mention.
   */
  const pull = useMutation({
    mutationFn: (account: ChannelAccount) =>
      api.post<{ data: Record<string, unknown> }>(`channels/${account.id}/pull`, { full: true }),
    onMutate: (account) => {
      setPulling(true)
      setPulled((previous) => { const next = { ...previous }; delete next[account.id]; return next })
    },
    onSettled: () => { setPulling(false); void queryClient.invalidateQueries() },
    onSuccess: (result, account) => {
      if (result.data.status !== 'running') setPulled((previous) => ({ ...previous, [account.id]: result.data }))
      void queryClient.invalidateQueries()
      void queryClient.invalidateQueries({ queryKey: ['channels'] })
    },
  })

  /*
   * Make a property out of a listing the channel discovered.
   *
   * The way out of the dead end a first connection used to end in: a row saying
   * "not mapped to anything" and nothing to map it to. The channel already sent
   * the name, type, address, capacity, currency and rate — this builds the
   * property from that rather than asking somebody to retype it.
   */
  const adopt = useMutation({
    mutationFn: (mapping: ChannelListing) =>
      api.post<{ message: string; needs: string | null }>(
        `channel-listings/${mapping.id}/adopt`,
        {},
      ),
    onSuccess: (result, mapping) => {
      setAdopted((previous) => ({ ...previous, [mapping.id]: result.message }))
      void queryClient.invalidateQueries()
      void queryClient.invalidateQueries({ queryKey: ['properties'] })
    },
  })

  const pushMapping = useMutation({
    mutationFn: (mapping: ChannelListing) =>
      api.post(`channel-listings/${mapping.id}/push`, { what: 'both' }),
    onSuccess: () => {
      void queryClient.invalidateQueries()
      void queryClient.invalidateQueries({ queryKey: ['channel-sync-health'] })
    },
  })

  // Connection rows carry the simulation flag from the catalogue, keyed by
  // channel, so the warning sits on the connection an operator is looking at
  // rather than only in a list further down the page.
  const catalogue = new Map(
    (available.data?.data ?? []).map((entry) => [entry.channel, entry]),
  )

  const summary = health.data?.data
  const failed =
    verify.error ?? push.error ?? pull.error ?? pushMapping.error ?? connect.error ?? saveSettings.error ?? adopt.error

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Channels</h1>
          <div className="page-header__subtitle">
            {summary
              ? `${formatNumber(summary.listings_behind)} listing(s) behind · ${formatNumber(
                  summary.listings_failing,
                )} failing · ${formatNumber(summary.retries_waiting)} earlier retryable failure(s)`
              : 'Loading…'}
          </div>
        </div>
      </div>

      {connectDialog.isOpen && (
        <RecordDialog
          title="Connect a channel"
          description="Bring a channel manager or OTA into Habitat. A new connection imports and does not push: nothing you have here reaches the channel until you turn that on afterwards, having seen what came in."
          fields={connectFields}
          submitLabel="Connect"
          initial={{ import_reservations: true, auto_import_properties: true, sync_messages: false, collects_payment: false }}
          pending={connect.isPending}
          error={connect.error}
          onSubmit={(values) => connect.mutate(values)}
          onClose={connectDialog.close}
        />
      )}

      {settingsDialog.isOpen && settingsDialog.editing !== null && (
        <RecordDialog
          title={`${settingsDialog.editing.name} settings`}
          description="What this connection does in each direction. Importing is safe; pushing replaces what the channel is showing."
          fields={settingsFields}
          submitLabel="Save"
          initial={{
            name: settingsDialog.editing.name,
            import_reservations: settingsDialog.editing.import_reservations,
            auto_import_properties: settingsDialog.editing.auto_import_properties ?? false,
            sync_messages: settingsDialog.editing.sync_messages,
            sync_availability: settingsDialog.editing.sync_availability,
            sync_rates: settingsDialog.editing.sync_rates,
            commission_percent: settingsDialog.editing.commission_percent,
            collects_payment: settingsDialog.editing.collects_payment,
          }}
          pending={saveSettings.isPending}
          error={saveSettings.error}
          onSubmit={(values) => saveSettings.mutate(values)}
          onClose={settingsDialog.close}
        />
      )}

      {mapDialog.isOpen && (
        <RecordDialog
          title="Link a listing"
          description="Tie one of your listings to the ID the channel knows it by. Without that pairing nothing can be matched up — an imported booking has no property to land on."
          fields={mappingFields}
          submitLabel="Link it"
          pending={mapListing.isPending}
          error={mapListing.error}
          onSubmit={(values) => mapListing.mutate(values)}
          onClose={mapDialog.close}
        />
      )}

      {failed !== null && failed !== undefined && (
        <div className="notice notice--error" role="alert">
          {failed instanceof ApiError ? failed.message : 'That action could not be completed.'}
        </div>
      )}

      <section className="card mb-3">
        <header className="card__header row row--between">
          <h2>Connections</h2>
          {can('channels.manage') && (
            <button type="button" className="btn btn--sm" onClick={connectDialog.create}>
              <Plug size={14} aria-hidden /> Connect a channel
            </button>
          )}
        </header>

        <QueryState
          isLoading={accounts.isLoading}
          error={accounts.error}
          isEmpty={(accounts.data?.data.length ?? 0) === 0}
          emptyTitle="No channels connected"
          emptyBody="Nothing is being distributed yet."
        >
          <div className="table-wrap">
          <table className="data">
            <thead>
              <tr>
                <th>Channel</th>
                <th>Status</th>
                <th className="numeric">Listings</th>
                <th className="numeric">Commission</th>
                <th>Money</th>
                <th>Last successful pull</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {(accounts.data?.data ?? []).map((account) => {
                const entry = catalogue.get(account.channel)
                const result = verified[account.id]

                return (
                  <tr key={account.id}>
                    <td>
                      <div className="strong">{account.name}</div>
                      <div className="small faint">{entry?.name ?? account.channel}</div>
                    </td>

                    <td>
                      <div className="row">
                        <Chip
                          label={account.status}
                          colour={account.is_connected ? 'emerald' : 'zinc'}
                        />

                        {/* The load-bearing one. Shown on every row whose
                            adapter is a simulation, connected or not. */}
                        {entry?.is_live === false && (
                          <Chip label="Simulated" colour="amber" />
                        )}

                        {!account.has_credentials && (
                          <Chip label="No credentials" colour="zinc" />
                        )}

                        {/* Which direction this connection runs. Worth a chip
                            rather than a settings screen somebody has to open:
                            pushing is the direction that can overwrite a live
                            calendar, so it should be visible at a glance. */}
                        {account.sync_availability || account.sync_rates ? (
                          <Chip
                            label={
                              account.sync_availability && account.sync_rates
                                ? 'Pushes dates and rates'
                                : account.sync_availability
                                  ? 'Pushes dates'
                                  : 'Pushes rates'
                            }
                            colour="amber"
                          />
                        ) : (
                          <Chip label={account.channel === 'hostex' ? 'Bulk push off' : 'Import only'} colour="sky" />
                        )}
                      </div>

                      {account.channel === 'hostex' && !account.sync_availability && !account.sync_rates && (
                        <div className="small faint mt-1">Imports run automatically. Enabled property-agent actions can still push the specific change you request.</div>
                      )}

                      {entry?.is_live === false && entry.simulation_reason !== null && (
                        <div className="small faint mt-1">{entry.simulation_reason}</div>
                      )}

                      {account.last_error !== null && (
                        <div className="small danger mt-1">{account.last_error}</div>
                      )}

                      {result !== undefined && (
                        <div className={result.successful ? 'small mt-1' : 'small danger mt-1'}>
                          {result.message}
                          {result.is_simulated && ' (answered by a local simulation)'}
                        </div>
                      )}

                      {/* Per stage, because one failing stage does not stop the
                          others and a single line would hide that. */}
                      {account.automatic_sync_interval_minutes && <div className="small mt-1">Automatic import every {account.automatic_sync_interval_minutes} minutes. Pull now requests an earlier refresh.</div>}
                      {(pulled[account.id] ?? account.last_pull_result) !== undefined &&
                        describePull(latestPull(pulled[account.id], account.last_pull_result)).map((line) => (
                          <div key={line.stage} className={line.failed ? 'small danger mt-1' : 'small mt-1'}>
                            {line.text}
                          </div>
                        ))}
                      {account.last_pull_attempted_at && <div className="small faint mt-1">Last attempted: {new Date(account.last_pull_attempted_at).toLocaleString()}</div>}
                      {account.last_pull_result?.trigger === 'automatic' && <div className="small faint">Started automatically in the background</div>}
                      {account.channel === 'hostex' && can('channels.manage') && <HostexSyncDiagnostics accountId={account.id} />}
                      {typeof account.last_pull_result?.completed_at === 'string' && <div className="small faint">{account.last_pull_result.status === 'partial' ? 'Completed with failures' : 'Completed'}: {new Date(account.last_pull_result.completed_at).toLocaleString()}</div>}
                    </td>

                    <td className="numeric">{account.listings_count ?? 0}</td>
                    <td className="numeric">{account.commission_percent.toFixed(2)}%</td>

                    <td>
                      {/* Who holds the guest's money decides whether a booking
                          produces cash or a receivable, so it is stated rather
                          than left to the commission column to imply. */}
                      {account.collects_payment ? (
                        <Chip label="Channel collects" colour="sky" />
                      ) : (
                        <Chip label="We collect" colour="slate" />
                      )}
                    </td>

                    <td className="small faint">
                      {(account.last_pull_succeeded_at ?? account.last_synced_at) !== null
                        ? new Date(account.last_pull_succeeded_at ?? account.last_synced_at ?? '').toLocaleString()
                        : 'Never'}
                    </td>

                    <td>
                      <div className="row">
                        {can('channels.manage') && (
                          <button
                            type="button"
                            className="btn btn--ghost btn--sm"
                            onClick={() => verify.mutate(account)}
                            disabled={verify.isPending}
                          >
                            Verify
                          </button>
                        )}

                        {can('channels.sync') && (
                          <button
                            type="button"
                            className="btn btn--ghost btn--sm"
                            onClick={() => push.mutate(account)}
                            disabled={push.isPending || (!account.sync_availability && !account.sync_rates)}
                            title={!account.sync_availability && !account.sync_rates ? 'Bulk outbound rate and availability sync is off.' : undefined}
                          >
                            Push now
                          </button>
                        )}

                        {can('channels.sync') && (
                          <button
                            type="button"
                            className="btn btn--ghost btn--sm"
                            onClick={() => pull.mutate(account)}
                            disabled={pull.isPending}
                          >
                            {pull.isPending ? 'Pulling…' : 'Pull now'}
                          </button>
                        )}

                        {can('channels.manage') && (
                          <button
                            type="button"
                            className="btn btn--ghost btn--sm"
                            onClick={() => settingsDialog.edit(account)}
                          >
                            <Settings2 size={14} aria-hidden /> Settings
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
          </div>
        </QueryState>
      </section>

      <section className="card mb-3">
        <header className="card__header row row--between">
          <div>
            <h2>Mapped listings</h2>
            <span className="small faint">
              What each channel has been told, and how far behind it is.
            </span>
          </div>

          {can('channels.manage') && (
            <button type="button" className="btn btn--sm btn--primary" onClick={mapDialog.create}>
              <Link2 size={15} aria-hidden /> Link a listing
            </button>
          )}
        </header>

        <QueryState
          isLoading={mappings.isLoading}
          error={mappings.error}
          isEmpty={(mappings.data?.data.length ?? 0) === 0}
          emptyTitle="Nothing mapped"
          emptyBody="No listing is published to a channel yet."
        >
          <div className="table-wrap">
          <table className="data">
            <thead>
              <tr>
                <th>Listing</th>
                <th>Channel</th>
                <th>State</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {(mappings.data?.data ?? []).map((mapping) => (
                <tr key={mapping.id}>
                  <td>
                    <div className="strong">{mapping.external_name ?? '—'}</div>
                    <div className="mono small faint">{mapping.external_listing_id}</div>

                    {/* A listing nothing here matches. Offering to build the
                        property from what the channel already told us is the
                        difference between a platform that imports and one that
                        shows you a row you cannot act on. */}
                    {mapping.property_id === null && (
                      <div className="stack stack--tight mt-1">
                        <span className="small faint">Not linked to one of your properties yet.</span>
                        {can('channels.map') && (
                          <button
                            type="button"
                            className="btn btn--sm"
                            disabled={adopt.isPending}
                            onClick={() => adopt.mutate(mapping)}
                          >
                            <Plus size={14} aria-hidden /> Create a property from this
                          </button>
                        )}
                      </div>
                    )}

                    {adopted[mapping.id] !== undefined && (
                      <div className="small mt-1">{adopted[mapping.id]}</div>
                    )}
                  </td>

                  <td>{mapping.account?.name ?? '—'}</td>

                  <td>
                    <div className="row">
                      <Chip
                        label={mapping.status}
                        colour={mapping.is_active ? 'emerald' : 'zinc'}
                      />

                      {/* "Behind" is the answer to "why is it still bookable
                          there?", so it is a chip rather than a timestamp the
                          reader has to subtract. */}
                      {mapping.availability_dirty && (
                        <Chip label="Availability behind" colour="amber" />
                      )}
                      {mapping.rates_dirty && <Chip label="Rates behind" colour="amber" />}

                      {mapping.is_failing && (
                        <Chip
                          label={`Failing ×${mapping.consecutive_failures}`}
                          colour="rose"
                        />
                      )}
                    </div>

                    {mapping.last_error !== null && (
                      <div className="small danger mt-1">{mapping.last_error}</div>
                    )}
                  </td>

                  <td>
                    {can('channels.sync') && (
                      <button
                        type="button"
                        className="btn btn--ghost btn--sm"
                        onClick={() => pushMapping.mutate(mapping)}
                        disabled={pushMapping.isPending || !(accounts.data?.data.find((account) => account.id === mapping.channel_account_id)?.sync_availability || accounts.data?.data.find((account) => account.id === mapping.channel_account_id)?.sync_rates)}
                        title="Push uses this connection’s outbound availability and rate settings."
                      >
                        Push
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
        </QueryState>
      </section>

      <section className="card">
        <header className="card__header">
          <h2>What this platform can connect to</h2>
        </header>

        <QueryState isLoading={available.isLoading} error={available.error}>
          <div className="table-wrap">
          <table className="data">
            <thead>
              <tr>
                <th>Channel</th>
                <th>Integration</th>
                <th>Capabilities</th>
              </tr>
            </thead>
            <tbody>
              {(available.data?.data ?? []).map((entry) => (
                <tr key={entry.channel}>
                  <td>
                    <span className="strong">{entry.name}</span>
                    {entry.connected && (
                      <span className="ml-2">
                        <Chip label="Connected" colour="emerald" />
                      </span>
                    )}
                  </td>

                  <td>
                    {entry.is_live ? (
                      <Chip label="Live" colour="emerald" />
                    ) : (
                      <>
                        <Chip label="Simulated" colour="amber" />
                        <div className="small faint mt-1">{entry.simulation_reason}</div>
                      </>
                    )}
                  </td>

                  <td className="small faint">{entry.capabilities.join(', ') || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
        </QueryState>
      </section>
    </>
  )
}
