import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
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
export function ChannelsPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()

  const [verified, setVerified] = useState<Record<string, VerifyResult['data']>>({})

  const accounts = useQuery({
    queryKey: ['channels'],
    queryFn: () => api.get<Paginated<ChannelAccount>>('channels', { per_page: 50 }),
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
      void queryClient.invalidateQueries({ queryKey: ['channel-listings'] })
      void queryClient.invalidateQueries({ queryKey: ['channel-sync-health'] })
    },
  })

  const pushMapping = useMutation({
    mutationFn: (mapping: ChannelListing) =>
      api.post(`channel-listings/${mapping.id}/push`, { what: 'both' }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['channel-listings'] })
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
  const failed = verify.error ?? push.error ?? pushMapping.error

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Channels</h1>
          <div className="page-header__subtitle">
            {summary
              ? `${formatNumber(summary.listings_behind)} listing(s) behind · ${formatNumber(
                  summary.listings_failing,
                )} failing · ${formatNumber(summary.retries_waiting)} retry(s) waiting`
              : 'Loading…'}
          </div>
        </div>
      </div>

      {failed !== null && failed !== undefined && (
        <div className="notice notice--error" role="alert">
          {failed instanceof ApiError ? failed.message : 'That action could not be completed.'}
        </div>
      )}

      <section className="card mb-3">
        <header className="card__header">
          <h2>Connections</h2>
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
                <th>Last sync</th>
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
                      </div>

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
                      {account.last_synced_at !== null
                        ? new Date(account.last_synced_at).toLocaleString()
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
                            disabled={push.isPending}
                          >
                            Push now
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
        <header className="card__header">
          <h2>Mapped listings</h2>
          <span className="small faint">
            What each channel has been told, and how far behind it is.
          </span>
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
                        disabled={pushMapping.isPending}
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
