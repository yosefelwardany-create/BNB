import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { Paginated, SupportSession, TenantPlan } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { formatDate, formatMoney, formatNumber, formatPercent } from '@/lib/format'
import { useAuth } from '@/lib/auth'

/**
 * What this organization is on, and who has looked at it.
 *
 * The customer's side of everything the platform console can do. A platform that
 * meters a customer and caps what they do without showing them their plan or
 * their usage produces a refusal out of nowhere, and the customer's only recourse
 * is a support ticket asking what happened. These are the same numbers the
 * operator sees, from the same service.
 */
export function SubscriptionPage() {
  const { canAny } = useAuth()

  const plan = useQuery({
    queryKey: ['tenant-plan'],
    queryFn: () => api.get<{ data: TenantPlan; meta: { support_email: string | null } }>(
      'organization/plan',
    ),
  })

  const sessions = useQuery({
    queryKey: ['tenant-support-sessions'],
    queryFn: () =>
      api.get<Paginated<SupportSession>>('organization/support-sessions', { per_page: 25 }),
    enabled: canAny(['organization.view', 'users.view']),
  })

  const data = plan.data?.data

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Subscription</h1>
          <div className="page-header__subtitle">
            {data ? data.status_label : 'Loading…'}
          </div>
        </div>
      </div>

      <QueryState isLoading={plan.isLoading} error={plan.error}>
        {data?.trial_has_expired === true && (
          <div className="notice notice--warning">
            Your trial ended on {formatDate(data.trial_ends_at)}.
            {plan.data?.meta.support_email !== null && (
              <> Get in touch at {plan.data?.meta.support_email}.</>
            )}
          </div>
        )}

        <section className="card mb-3">
          <header className="card__header">
            <h2>{data?.plan?.name ?? 'No plan'}</h2>
            {data?.plan !== null && data?.plan !== undefined && (
              <span className="strong">
                {formatMoney(data.plan.price)} / {data.plan.billing_interval}
              </span>
            )}
          </header>

          <div className="card__body">
            {data?.is_metered === false ? (
              /* Said plainly rather than left to an absent plan to imply.
                 Nothing is capped, and this is not a free tier that might
                 quietly cut off. */
              <p className="mt-0">
                Nothing on your account is metered. There are no limits on properties,
                listings, seats or bookings, and every feature is available.
              </p>
            ) : (
              <>
                {data?.plan?.description !== null && (
                  <p className="mt-0">{data?.plan?.description}</p>
                )}

                {data?.trial_ends_at !== null && data?.trial_has_expired === false && (
                  <p className="small faint">Trial runs until {formatDate(data?.trial_ends_at)}.</p>
                )}

                <h3>What you are using</h3>

                <div className="table-wrap">
                  <table className="data">
                    <thead>
                      <tr>
                        <th>Limit</th>
                        <th className="numeric">In use</th>
                        <th className="numeric">Included</th>
                        <th />
                      </tr>
                    </thead>
                    <tbody>
                      {Object.entries(data?.usage ?? {}).map(([key, row]) => (
                        <tr key={key}>
                          <td className="small">
                            {key.replace('max_', '').replace(/_/g, ' ')}
                          </td>
                          <td className="numeric">{formatNumber(row.used)}</td>
                          <td className="numeric">
                            {row.limit === null ? (
                              <span className="faint">Unlimited</span>
                            ) : (
                              formatNumber(row.limit)
                            )}
                          </td>
                          <td>
                            {row.limit !== null && row.used >= row.limit ? (
                              <Chip label="At your limit" colour="amber" />
                            ) : row.limit !== null ? (
                              <span className="small faint">
                                {formatPercent((row.used / row.limit) * 100)} used
                              </span>
                            ) : null}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}

            <h3>Features</h3>

            <div className="row wrap">
              {Object.entries(data?.features ?? {}).map(([key, row]) => (
                <Chip
                  key={key}
                  label={key.replace(/_/g, ' ')}
                  colour={row.enabled ? 'emerald' : 'zinc'}
                />
              ))}
            </div>
          </div>
        </section>

        {canAny(['organization.view', 'users.view']) && (
          <section className="card">
            <header className="card__header">
              <h2>Who has looked at your account</h2>
            </header>

            <QueryState isLoading={sessions.isLoading} error={sessions.error}>
              <div className="card__body">
                <p className="small faint mt-0">
                  {/* The customer's own record. This is what makes the platform's
                      support access defensible rather than a back door. */}
                  When our support team needs to see what you see, it is recorded here with
                  a reason. Those sessions are read-only — nothing in your account can be
                  changed through one.
                </p>

                {(sessions.data?.data.length ?? 0) === 0 ? (
                  <p className="small">Nobody from our team has looked inside your account.</p>
                ) : (
                  <div className="table-wrap">
                    <table className="data">
                      <thead>
                        <tr>
                          <th>When</th>
                          <th>Who</th>
                          <th>Why</th>
                          <th className="numeric">Pages read</th>
                        </tr>
                      </thead>
                      <tbody>
                        {(sessions.data?.data ?? []).map((session) => (
                          <tr key={session.id}>
                            <td className="small faint nowrap">
                              {session.started_at === null
                                ? '—'
                                : new Date(session.started_at).toLocaleString()}
                              {session.is_open && (
                                <div className="mt-1">
                                  <Chip label="In progress" colour="amber" />
                                </div>
                              )}
                            </td>
                            <td className="small">{session.operator?.email ?? '—'}</td>
                            <td className="small wrap">{session.reason}</td>
                            <td className="numeric">{formatNumber(session.request_count)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </QueryState>
          </section>
        )}
      </QueryState>
    </>
  )
}
