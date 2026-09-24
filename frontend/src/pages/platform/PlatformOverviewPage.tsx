import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '@/api/client'
import type { PlatformOverview } from '@/api/types'
import { QueryState } from '@/components/QueryState'
import { formatNumber } from '@/lib/format'

/**
 * The platform at a glance.
 *
 * The number that leads is expired trials, not total customers. A count of
 * tenants is a vanity figure that changes slowly; a list of trials that ran out
 * and nobody acted on is a list of conversations somebody owes a customer today.
 */
export function PlatformOverviewPage() {
  const overview = useQuery({
    queryKey: ['platform-overview'],
    queryFn: () => api.get<{ data: PlatformOverview }>('platform/overview'),
  })

  const growth = useQuery({
    queryKey: ['platform-growth'],
    queryFn: () =>
      api.get<{ data: { month: string; created: number }[] }>('platform/growth', { months: 12 }),
  })

  const data = overview.data?.data
  const series = growth.data?.data ?? []
  const peak = series.reduce((highest, row) => Math.max(highest, row.created), 0)

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Overview</h1>
          <div className="page-header__subtitle">
            {data ? `As at ${new Date(data.generated_at).toLocaleString()}` : 'Loading…'}
          </div>
        </div>
      </div>

      <QueryState isLoading={overview.isLoading} error={overview.error}>
        {data !== undefined && data.organizations.expired_trials > 0 && (
          <div className="notice notice--warning">
            <strong>
              {formatNumber(data.organizations.expired_trials)} trial(s) have expired
            </strong>{' '}
            and are still on a trial status. Each one is a customer waiting to hear
            something.{' '}
            <Link to="/platform/tenants?expired_trials=1">Show them</Link>
          </div>
        )}

        <div className="grid grid--stats mb-3">
          <Stat
            label="Organizations"
            value={data ? formatNumber(data.organizations.total) : '—'}
            meta={
              data ? `${formatNumber(data.organizations.new_this_month)} new this month` : ''
            }
          />
          <Stat
            label="Properties under management"
            value={data ? formatNumber(data.portfolio.properties) : '—'}
            meta={data ? `${formatNumber(data.portfolio.units)} unit(s)` : ''}
          />
          <Stat
            label="Published listings"
            value={data ? formatNumber(data.portfolio.published_listings) : '—'}
            meta="Live on at least one surface"
          />
          <Stat
            label="Bookings this month"
            value={data ? formatNumber(data.trading.reservations_this_month) : '—'}
            meta={
              data
                ? `${formatNumber(data.trading.nights_sold_this_month)} night(s) sold`
                : ''
            }
          />
          <Stat
            label="People"
            value={data ? formatNumber(data.users.total) : '—'}
            meta={
              data
                ? `${formatNumber(data.users.active_last_30_days)} signed in within 30 days`
                : ''
            }
          />
          <Stat
            label="Platform administrators"
            value={data ? formatNumber(data.users.platform_admins) : '—'}
            meta="People who can reach this console"
          />
        </div>

        <div className="grid grid--two mb-3">
          <section className="card">
            <header className="card__header">
              <h2>By status</h2>
            </header>
            <div className="table-wrap">
              <table className="data">
                <tbody>
                  {Object.entries(data?.organizations.by_status ?? {}).map(([status, count]) => (
                    <tr key={status}>
                      <td className="strong">{status.replace('_', ' ')}</td>
                      <td className="numeric">{formatNumber(count)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          <section className="card">
            <header className="card__header">
              <h2>What our customers transacted</h2>
              <span className="small faint">{data?.customer_transaction_volume.period}</span>
            </header>

            <div className="card__body">
              {/* Named carefully. This is what customers moved, which is not
                  what they pay us, and presenting the first as the second
                  overstates the business by orders of magnitude. */}
              <p className="small faint mt-0">
                Money our customers took, not platform revenue. Shown per currency —
                adding euros to dollars gives a number that is wrong in both.
              </p>

              {(data?.customer_transaction_volume.by_currency.length ?? 0) === 0 ? (
                <p className="small faint">Nothing captured this month.</p>
              ) : (
                <div className="table-wrap">
                  <table className="data">
                    <thead>
                      <tr>
                        <th>Currency</th>
                        <th className="numeric">Payments</th>
                        <th className="numeric">Amount</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(data?.customer_transaction_volume.by_currency ?? []).map((row) => (
                        <tr key={row.currency}>
                          <td className="strong">{row.currency}</td>
                          <td className="numeric">{formatNumber(row.payments)}</td>
                          <td className="numeric">
                            {new Intl.NumberFormat('en-GB', {
                              style: 'currency',
                              currency: row.currency,
                            }).format(row.amount / 100)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </section>
        </div>
      </QueryState>

      <section className="card">
        <header className="card__header">
          <h2>Sign-ups by month</h2>
        </header>

        <QueryState isLoading={growth.isLoading} error={growth.error}>
          <div className="table-wrap">
            <table className="data">
              <tbody>
                {series.map((row) => (
                  <tr key={row.month}>
                    <td className="nowrap mono small">{row.month}</td>
                    <td>
                      <div className="bar">
                        <div
                          className="bar__fill"
                          style={{ width: peak > 0 ? `${(row.created / peak) * 100}%` : '0%' }}
                        />
                        <span className="bar__label">{row.created}</span>
                      </div>
                    </td>
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

function Stat({ label, value, meta }: { label: string; value: string; meta?: string }) {
  return (
    <div className="card card__body">
      <div className="stat__label">{label}</div>
      <div className="stat__value">{value}</div>
      {meta !== undefined && meta !== '' && <div className="stat__meta">{meta}</div>}
    </div>
  )
}
