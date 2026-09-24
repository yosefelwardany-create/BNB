import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { PlatformHealth, ProviderHealth } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { formatNumber } from '@/lib/format'

/**
 * Whether the platform is working.
 *
 * The integrations section is the one that does not exist on other dashboards: a
 * queue with no backlog and a channel adapter that has never spoken to a channel
 * look identical everywhere else. This is where the platform operator finds out
 * how much of their own product is a simulation.
 */
export function PlatformHealthPage() {
  const health = useQuery({
    queryKey: ['platform-health'],
    queryFn: () => api.get<{ data: PlatformHealth }>('platform/health'),
    refetchInterval: 30_000,
  })

  const data = health.data?.data

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Health</h1>
          <div className="page-header__subtitle">
            {data ? `As at ${new Date(data.generated_at).toLocaleTimeString()}` : 'Loading…'}
          </div>
        </div>
      </div>

      <QueryState isLoading={health.isLoading} error={health.error}>
        {data?.database.reachable === false && (
          <div className="notice notice--error" role="alert">
            The database is not reachable: {data.database.error}
          </div>
        )}

        {(data?.database.pending_migrations ?? 0) > 0 && (
          <div className="notice notice--warning">
            {/* The symptom of a half-finished deploy is a scatter of unrelated
                500s, so it is named here rather than left to be guessed. */}
            <strong>{data?.database.pending_migrations} migration(s) have not run.</strong> A
            deploy may have half-finished; the application will be expecting columns the
            database does not have.
          </div>
        )}

        <section className="card mb-3">
          <header className="card__header">
            <h2>Integrations</h2>
            <span className="small faint">What is real and what is standing in for it</span>
          </header>

          <div className="card__body">
            <div className="grid grid--two">
              <Provider label="Payments" provider={data?.integrations.payments} />
              <Provider label="Messaging" provider={data?.integrations.messaging} />
              <Provider label="Smart locks" provider={data?.integrations.locks} />
              <Provider label="Assisted drafting" provider={data?.integrations.ai} />
            </div>

            <h3>Channels</h3>

            <div className="table-wrap">
              <table className="data">
                <thead>
                  <tr>
                    <th>Channel</th>
                    <th>Integration</th>
                    <th className="numeric">Connected accounts</th>
                  </tr>
                </thead>
                <tbody>
                  {(data?.integrations.channels ?? []).map((channel) => (
                    <tr key={channel.channel}>
                      <td className="strong">{channel.name}</td>
                      <td>
                        {channel.is_live ? (
                          <Chip label="Live" colour="emerald" />
                        ) : (
                          <>
                            <Chip label="Simulated" colour="amber" />
                            <div className="small faint mt-1">{channel.simulation_reason}</div>
                          </>
                        )}
                      </td>
                      <td className="numeric">
                        {formatNumber(channel.connected_accounts)}
                        {/* How much is riding on a simulation. Ninety accounts
                            on a simulated adapter is a very different fact from
                            none. */}
                        {!channel.is_live && channel.connected_accounts > 0 && (
                          <div className="small danger">riding on a simulation</div>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <div className="grid grid--two">
          <section className="card">
            <header className="card__header">
              <h2>Queues</h2>
            </header>
            <div className="table-wrap">
              <table className="data">
                <tbody>
                  <tr>
                    <td>Driver</td>
                    <td className="numeric mono small">{data?.queues.driver}</td>
                  </tr>
                  <tr>
                    <td>Waiting</td>
                    <td className="numeric">
                      {data?.queues.depth_visible === true
                        ? formatNumber(data.queues.database_depth ?? 0)
                        : /* A depth of nought on a Redis queue means the table
                             is empty, not the queue — so it says so. */
                          <span className="faint small">not visible on this driver</span>}
                    </td>
                  </tr>
                  <tr>
                    <td>Failed, last 24 hours</td>
                    <td className="numeric">
                      {formatNumber(data?.queues.failed_last_24h ?? 0)}
                    </td>
                  </tr>
                  <tr>
                    <td>Failed, all time</td>
                    <td className="numeric">{formatNumber(data?.queues.failed_total ?? 0)}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section className="card">
            <header className="card__header">
              <h2>Webhooks</h2>
              <span className="small faint">last {data?.webhooks.window_hours}h</span>
            </header>
            <div className="table-wrap">
              <table className="data">
                <tbody>
                  {Object.entries(data?.webhooks.by_status ?? {}).map(([status, count]) => (
                    <tr key={status}>
                      <td>{status}</td>
                      <td className="numeric">{formatNumber(count)}</td>
                    </tr>
                  ))}
                  <tr>
                    <td>Endpoints unhealthy</td>
                    <td className="numeric">
                      {formatNumber(data?.webhooks.endpoints_unhealthy ?? 0)}
                    </td>
                  </tr>
                  <tr>
                    <td>Endpoints disabled</td>
                    <td className="numeric">
                      {formatNumber(data?.webhooks.endpoints_disabled ?? 0)}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section className="card">
            <header className="card__header">
              <h2>Channel sync</h2>
              <span className="small faint">last {data?.channels.window_hours}h</span>
            </header>
            <div className="table-wrap">
              <table className="data">
                <thead>
                  <tr>
                    <th>Status</th>
                    <th className="numeric">Jobs</th>
                    <th className="numeric">Simulated</th>
                  </tr>
                </thead>
                <tbody>
                  {Object.entries(data?.channels.jobs ?? {}).map(([status, row]) => (
                    <tr key={status}>
                      <td>{status}</td>
                      <td className="numeric">{formatNumber(row.total)}</td>
                      <td className="numeric">{formatNumber(row.simulated)}</td>
                    </tr>
                  ))}
                  <tr>
                    <td>Listings behind</td>
                    <td className="numeric" colSpan={2}>
                      {formatNumber(data?.channels.listings_behind ?? 0)}
                    </td>
                  </tr>
                  <tr>
                    <td>Accounts errored</td>
                    <td className="numeric" colSpan={2}>
                      {formatNumber(data?.channels.accounts_errored ?? 0)}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section className="card">
            <header className="card__header">
              <h2>Database</h2>
            </header>
            <div className="table-wrap">
              <table className="data">
                <tbody>
                  <tr>
                    <td>Reachable</td>
                    <td className="numeric">
                      {data?.database.reachable === true ? (
                        <Chip label="Yes" colour="emerald" />
                      ) : (
                        <Chip label="No" colour="rose" />
                      )}
                    </td>
                  </tr>
                  <tr>
                    <td>Size</td>
                    <td className="numeric">
                      {data?.database.size_bytes === undefined
                        ? '—'
                        : `${(data.database.size_bytes / 1024 / 1024).toFixed(1)} MB`}
                    </td>
                  </tr>
                  <tr>
                    <td>Pending migrations</td>
                    <td className="numeric">{data?.database.pending_migrations ?? '—'}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </QueryState>
    </>
  )
}

function Provider({ label, provider }: { label: string; provider?: ProviderHealth }) {
  return (
    <div className="panel mt-0">
      <div className="row row--between">
        <span className="strong">{label}</span>
        {provider?.is_live === true ? (
          <Chip label="Live" colour="emerald" />
        ) : (
          <Chip label="Simulated" colour="amber" />
        )}
      </div>

      <div className="small faint">{provider?.name ?? '—'}</div>

      {provider?.simulation_reason !== null && provider?.simulation_reason !== undefined && (
        <p className="small wrap">{provider.simulation_reason}</p>
      )}
    </div>
  )
}
