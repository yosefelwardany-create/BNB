import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { api, ApiError } from '@/api/client'
import type {
  Paginated,
  Plan,
  PlatformTenant,
  SupportSession,
  TenantDetailMeta,
} from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { formatDate, formatNumber, formatPercent } from '@/lib/format'

type Action = 'suspend' | 'reinstate' | 'cancel' | 'support' | null

/**
 * The tenants on the platform.
 *
 * Every destructive action on this screen asks for a reason before it will
 * proceed, because the reason is recorded in the customer's own audit trail and
 * they are entitled to read it. A suspension with no stated cause is the support
 * ticket nobody can answer.
 */
export function PlatformTenantsPage() {
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()

  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [action, setAction] = useState<Action>(null)
  const [reason, setReason] = useState('')

  const expiredOnly = params.get('expired_trials') === '1'

  const list = useQuery({
    queryKey: ['platform-tenants', { search, page, expiredOnly }],
    queryFn: () =>
      api.get<Paginated<PlatformTenant>>('platform/organizations', {
        search: search || undefined,
        expired_trials: expiredOnly ? true : undefined,
        page,
        per_page: 25,
      }),
    placeholderData: keepPreviousData,
  })

  const detail = useQuery({
    queryKey: ['platform-tenant', selectedId],
    queryFn: () =>
      api.get<{ data: PlatformTenant; meta: TenantDetailMeta }>(
        `platform/organizations/${selectedId}`,
      ),
    enabled: selectedId !== null,
  })

  const plans = useQuery({
    queryKey: ['platform-plans'],
    queryFn: () => api.get<{ data: Plan[] }>('platform/plans'),
  })

  const lifecycle = useMutation({
    mutationFn: ({ id, act, why }: { id: string; act: 'suspend' | 'reinstate' | 'cancel'; why: string }) =>
      api.post<{ message: string }>(`platform/organizations/${id}/${act}`, { reason: why }),
    onSuccess: () => {
      setAction(null)
      setReason('')
      void queryClient.invalidateQueries({ queryKey: ['platform-tenants'] })
      void queryClient.invalidateQueries({ queryKey: ['platform-tenant'] })
    },
  })

  const changePlan = useMutation({
    mutationFn: ({ id, planId }: { id: string; planId: string | null }) =>
      api.post<{ message: string; meta: { breaches: Record<string, { used: number; limit: number }> } }>(
        `platform/organizations/${id}/plan`,
        { plan_id: planId },
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['platform-tenants'] })
      void queryClient.invalidateQueries({ queryKey: ['platform-tenant'] })
    },
  })

  const notes = useMutation({
    mutationFn: ({ id, text }: { id: string; text: string }) =>
      api.patch(`platform/organizations/${id}`, { platform_notes: text }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['platform-tenant'] }),
  })

  const impersonate = useMutation({
    mutationFn: ({ id, why }: { id: string; why: string }) =>
      api.post<{ data: { token: string; expires_at: string; session: SupportSession } }>(
        `platform/organizations/${id}/impersonate`,
        { reason: why },
      ),
  })

  const tenants = list.data?.data ?? []
  const meta = list.data?.meta
  const tenant = detail.data?.data
  const usage = detail.data?.meta

  const failed = lifecycle.error ?? changePlan.error ?? notes.error ?? impersonate.error

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Tenants</h1>
          <div className="page-header__subtitle">
            {meta ? `${formatNumber(meta.total)} organization(s)` : 'Loading…'}
          </div>
        </div>

        <div className="row">
          <button
            type="button"
            className={expiredOnly ? 'btn btn--sm' : 'btn btn--ghost btn--sm'}
            onClick={() => {
              setParams(expiredOnly ? {} : { expired_trials: '1' })
              setPage(1)
            }}
          >
            Expired trials
          </button>
        </div>
      </div>

      <div className="filters">
        <div className="field">
          <label className="field__label" htmlFor="tenant-search">
            Search
          </label>
          <input
            id="tenant-search"
            type="search"
            value={search}
            placeholder="Name, slug or contact email"
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
          />
        </div>
      </div>

      {failed !== null && failed !== undefined && (
        <div className="notice notice--error" role="alert">
          {failed instanceof ApiError ? failed.message : 'That action could not be completed.'}
        </div>
      )}

      {changePlan.data !== undefined &&
        Object.keys(changePlan.data.meta.breaches).length > 0 && (
          <div className="notice notice--warning">
            {/* Reported rather than refused, so a commercial decision can
                complete. They keep what they have and cannot add more. */}
            This organization is now over its limits:{' '}
            {Object.entries(changePlan.data.meta.breaches)
              .map(([key, b]) => `${key.replace('max_', '')} ${b.used}/${b.limit}`)
              .join(', ')}
            . Nothing was deleted — they keep what they have and cannot add more.
          </div>
        )}

      {impersonate.data !== undefined && (
        <div className="notice notice--warning">
          <div className="strong">Read-only support session started.</div>
          <p className="small">
            Viewing as {impersonate.data.data.session.viewed_as?.email}. Expires{' '}
            {new Date(impersonate.data.data.expires_at).toLocaleString()}. Every write is
            refused with this token, and the customer has a record of it.
          </p>
          <pre className="secret">{impersonate.data.data.token}</pre>
        </div>
      )}

      <div className="split">
        <section className="card">
          <QueryState
            isLoading={list.isLoading}
            error={list.error}
            isEmpty={tenants.length === 0}
            emptyTitle="No organizations"
            emptyBody="Nothing matches this filter."
          >
            <div className="table-wrap">
              <table className="data">
                <thead>
                  <tr>
                    <th>Organization</th>
                    <th>Status</th>
                    <th>Plan</th>
                    <th className="numeric">Seats</th>
                  </tr>
                </thead>
                <tbody>
                  {tenants.map((row) => (
                    <tr
                      key={row.id}
                      onClick={() => setSelectedId(row.id)}
                      className={row.id === selectedId ? 'is-selected' : undefined}
                      style={{ cursor: 'pointer' }}
                    >
                      <td>
                        <div className="strong">{row.name}</div>
                        <div className="small faint">{row.contact_email ?? row.slug}</div>
                      </td>

                      <td>
                        <Chip
                          label={row.status_label}
                          colour={statusColour(row.status)}
                        />
                        {row.trial_has_expired && (
                          <div className="mt-1">
                            <Chip label="Trial expired" colour="amber" />
                          </div>
                        )}
                      </td>

                      <td className="small">
                        {row.plan?.name ?? <span className="faint">Unmetered</span>}
                      </td>

                      <td className="numeric">{row.users_count ?? 0}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {meta !== undefined && meta.last_page > 1 && (
              <div className="card__footer row row--between">
                <span className="small faint">
                  Page {meta.current_page} of {meta.last_page}
                </span>
                <div className="row">
                  <button
                    type="button"
                    className="btn btn--ghost btn--sm"
                    disabled={meta.current_page <= 1}
                    onClick={() => setPage((p) => p - 1)}
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    className="btn btn--ghost btn--sm"
                    disabled={meta.current_page >= meta.last_page}
                    onClick={() => setPage((p) => p + 1)}
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
          </QueryState>
        </section>

        <section className="card">
          {selectedId === null ? (
            <div className="empty">
              <div className="empty__title">No organization selected</div>
              <p>Choose one to see its plan, usage and history.</p>
            </div>
          ) : (
            <QueryState isLoading={detail.isLoading} error={detail.error}>
              <header className="card__header">
                <div>
                  <h2>{tenant?.name}</h2>
                  <div className="small faint">
                    {tenant?.legal_name ?? tenant?.slug} · {tenant?.base_currency} ·{' '}
                    {tenant?.timezone}
                  </div>
                </div>
                <Chip
                  label={tenant?.status_label ?? ''}
                  colour={statusColour(tenant?.status ?? '')}
                />
              </header>

              <div className="card__body">
                {tenant?.suspension_reason !== null && tenant?.suspension_reason !== undefined && (
                  <div className="notice notice--warning">
                    <strong>Suspended.</strong> {tenant.suspension_reason}
                  </div>
                )}

                <h3 className="mt-0">Plan</h3>

                <div className="row wrap">
                  <select
                    value={tenant?.plan_id ?? ''}
                    onChange={(event) =>
                      changePlan.mutate({
                        id: selectedId,
                        planId: event.target.value === '' ? null : event.target.value,
                      })
                    }
                    style={{ width: 'auto' }}
                    aria-label="Plan"
                  >
                    <option value="">Unmetered (no plan)</option>
                    {(plans.data?.data ?? []).map((plan) => (
                      <option key={plan.id} value={plan.id}>
                        {plan.name}
                      </option>
                    ))}
                  </select>

                  {tenant?.trial_ends_at !== null && (
                    <span className="small faint">
                      Trial ends {formatDate(tenant?.trial_ends_at)}
                    </span>
                  )}
                </div>

                <h3>Usage against the plan</h3>

                <div className="table-wrap">
                  <table className="data">
                    <thead>
                      <tr>
                        <th>Limit</th>
                        <th className="numeric">Used</th>
                        <th className="numeric">Cap</th>
                        <th />
                      </tr>
                    </thead>
                    <tbody>
                      {Object.entries(usage?.usage ?? {}).map(([key, row]) => (
                        <tr key={key}>
                          <td className="small">{key.replace('max_', '').replace(/_/g, ' ')}</td>
                          <td className="numeric">{formatNumber(row.used)}</td>
                          <td className="numeric">
                            {/* Null is unlimited, and says so rather than
                                showing a blank a reader has to interpret. */}
                            {row.limit === null ? (
                              <span className="faint">Unlimited</span>
                            ) : (
                              formatNumber(row.limit)
                            )}
                          </td>
                          <td>
                            {row.limit !== null && row.used > row.limit && (
                              <Chip label="Over" colour="rose" />
                            )}
                            {row.at_limit && row.limit !== null && row.used === row.limit && (
                              <Chip label="At limit" colour="amber" />
                            )}
                            {row.limit !== null && row.used < row.limit && (
                              <span className="small faint">
                                {formatPercent((row.used / row.limit) * 100)}
                              </span>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <h3>Features</h3>

                <div className="row wrap">
                  {Object.entries(usage?.features ?? {}).map(([key, row]) => (
                    <Chip
                      key={key}
                      label={`${key.replace(/_/g, ' ')}${row.source === 'override' ? ' (override)' : ''}`}
                      colour={row.enabled ? 'emerald' : 'zinc'}
                    />
                  ))}
                </div>

                <h3>Activity</h3>

                <div className="table-wrap">
                  <table className="data">
                    <tbody>
                      {Object.entries(usage?.counts ?? {}).map(([key, count]) => (
                        <tr key={key}>
                          <td className="small">{key.replace(/_/g, ' ')}</td>
                          <td className="numeric">{formatNumber(count)}</td>
                        </tr>
                      ))}
                      <tr>
                        <td className="small">last activity</td>
                        <td className="numeric small faint">
                          {usage?.last_activity_at === null || usage === undefined
                            ? '—'
                            : new Date(usage.last_activity_at).toLocaleString()}
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <h3>Private notes</h3>
                <p className="small faint mt-0">
                  Visible only in this console. The customer never sees it.
                </p>

                <textarea
                  rows={3}
                  defaultValue={tenant?.platform_notes ?? ''}
                  onBlur={(event) =>
                    notes.mutate({ id: selectedId, text: event.target.value })
                  }
                />

                <h3>Actions</h3>

                {action === null ? (
                  <div className="row wrap">
                    {tenant?.status !== 'suspended' && tenant?.status !== 'cancelled' && (
                      <button
                        type="button"
                        className="btn btn--danger btn--sm"
                        onClick={() => setAction('suspend')}
                      >
                        Suspend
                      </button>
                    )}

                    {tenant?.status === 'suspended' && (
                      <button
                        type="button"
                        className="btn btn--sm"
                        onClick={() => setAction('reinstate')}
                      >
                        Reinstate
                      </button>
                    )}

                    {tenant?.status !== 'cancelled' && (
                      <button
                        type="button"
                        className="btn btn--danger btn--sm"
                        onClick={() => setAction('cancel')}
                      >
                        Cancel
                      </button>
                    )}

                    <button
                      type="button"
                      className="btn btn--ghost btn--sm"
                      onClick={() => setAction('support')}
                    >
                      Open support session
                    </button>
                  </div>
                ) : (
                  <form
                    onSubmit={(event) => {
                      event.preventDefault()

                      if (reason.trim() === '') return

                      if (action === 'support') {
                        impersonate.mutate({ id: selectedId, why: reason })
                        setAction(null)
                        setReason('')

                        return
                      }

                      lifecycle.mutate({ id: selectedId, act: action, why: reason })
                    }}
                  >
                    <div className="field">
                      <label className="field__label" htmlFor="reason">
                        {action === 'support'
                          ? 'Why do you need to look inside this account?'
                          : `Why? (${action})`}
                      </label>
                      <input
                        id="reason"
                        type="text"
                        value={reason}
                        autoFocus
                        onChange={(event) => setReason(event.target.value)}
                      />
                      <span className="field__hint">
                        {/* The reason is the customer's, not an internal note. */}
                        Recorded in this customer’s own audit trail, where they can read it.
                        {action === 'cancel' &&
                          ' Cancelling ends access and deletes nothing — every record is kept.'}
                        {action === 'support' &&
                          ' The session is read-only and lasts 30 minutes.'}
                      </span>
                    </div>

                    <div className="row">
                      <button
                        type="button"
                        className="btn btn--ghost btn--sm"
                        onClick={() => {
                          setAction(null)
                          setReason('')
                        }}
                      >
                        Cancel
                      </button>
                      <button
                        type="submit"
                        className={action === 'support' ? 'btn btn--sm' : 'btn btn--danger btn--sm'}
                        disabled={
                          lifecycle.isPending || impersonate.isPending || reason.trim() === ''
                        }
                      >
                        {lifecycle.isPending || impersonate.isPending
                          ? 'Working…'
                          : action === 'support'
                            ? 'Start read-only session'
                            : `Confirm ${action}`}
                      </button>
                    </div>
                  </form>
                )}
              </div>
            </QueryState>
          )}
        </section>
      </div>
    </>
  )
}

function statusColour(status: string): string {
  return (
    {
      active: 'emerald',
      trial: 'sky',
      past_due: 'amber',
      suspended: 'rose',
      cancelled: 'zinc',
    }[status] ?? 'slate'
  )
}
