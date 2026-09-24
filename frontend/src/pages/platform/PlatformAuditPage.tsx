import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { PlatformAuditRow } from '@/api/types'
import { QueryState } from '@/components/QueryState'
import { formatNumber } from '@/lib/format'

interface Page<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    notice?: string
  }
}

interface TenantAuditRow {
  id: string
  organization: string | null
  action: string
  actor_type: string
  actor_label: string | null
  description: string | null
  created_at: string | null
}

type Trail = 'platform' | 'tenants'

/**
 * Two audit trails, kept apart.
 *
 * "What did we do" and "what happened inside a customer's account" are different
 * questions with different audiences, and merging them makes every row ambiguous
 * about whose action it was. So they are two tabs over two tables, not one
 * combined feed.
 */
export function PlatformAuditPage() {
  const [trail, setTrail] = useState<Trail>('platform')
  const [action, setAction] = useState('')
  const [page, setPage] = useState(1)

  const platform = useQuery({
    queryKey: ['platform-audit', { action, page }],
    queryFn: () =>
      api.get<Page<PlatformAuditRow>>('platform/audit/platform', {
        action: action || undefined,
        page,
        per_page: 50,
      }),
    enabled: trail === 'platform',
    placeholderData: keepPreviousData,
  })

  const tenants = useQuery({
    queryKey: ['tenant-audit', { action, page }],
    queryFn: () =>
      api.get<Page<TenantAuditRow>>('platform/audit/tenants', {
        action: action || undefined,
        page,
        per_page: 50,
      }),
    enabled: trail === 'tenants',
    placeholderData: keepPreviousData,
  })

  const active = trail === 'platform' ? platform : tenants
  const meta = active.data?.meta

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Audit</h1>
          <div className="page-header__subtitle">
            {meta ? `${formatNumber(meta.total)} entr(ies)` : 'Loading…'}
          </div>
        </div>

        <div className="row">
          {(['platform', 'tenants'] as Trail[]).map((option) => (
            <button
              key={option}
              type="button"
              className={trail === option ? 'btn btn--sm' : 'btn btn--ghost btn--sm'}
              onClick={() => {
                setTrail(option)
                setPage(1)
              }}
            >
              {option === 'platform' ? 'What we did' : 'Inside customer accounts'}
            </button>
          ))}
        </div>
      </div>

      <div className="filters">
        <div className="field">
          <label className="field__label" htmlFor="audit-action">
            Action starts with
          </label>
          <input
            id="audit-action"
            type="search"
            value={action}
            placeholder="platform. · organization. · reservation."
            onChange={(event) => {
              setAction(event.target.value)
              setPage(1)
            }}
          />
        </div>
      </div>

      {meta?.notice !== undefined && (
        <div className="notice notice--info">{meta.notice}</div>
      )}

      <QueryState
        isLoading={active.isLoading}
        error={active.error}
        isEmpty={(active.data?.data.length ?? 0) === 0}
        emptyTitle="Nothing recorded"
      >
        <section className="card">
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Action</th>
                  <th>Who</th>
                  <th>Organization</th>
                  <th>What</th>
                </tr>
              </thead>
              <tbody>
                {trail === 'platform'
                  ? (platform.data?.data ?? []).map((row) => (
                      <tr key={row.id}>
                        <td className="small faint nowrap">
                          {row.created_at === null
                            ? '—'
                            : new Date(row.created_at).toLocaleString()}
                        </td>
                        <td className="mono small">{row.action}</td>
                        <td className="small">{row.actor_email ?? '—'}</td>
                        {/* The copied name, not a join: these rows outlive the
                            organization they refer to. */}
                        <td className="small">{row.organization_name ?? '—'}</td>
                        <td className="small wrap">{row.description ?? '—'}</td>
                      </tr>
                    ))
                  : (tenants.data?.data ?? []).map((row) => (
                      <tr key={row.id}>
                        <td className="small faint nowrap">
                          {row.created_at === null
                            ? '—'
                            : new Date(row.created_at).toLocaleString()}
                        </td>
                        <td className="mono small">{row.action}</td>
                        <td className="small">
                          {row.actor_label ?? '—'}
                          <div className="faint">{row.actor_type}</div>
                        </td>
                        <td className="small">{row.organization ?? '—'}</td>
                        <td className="small wrap">{row.description ?? '—'}</td>
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
        </section>
      </QueryState>
    </>
  )
}
