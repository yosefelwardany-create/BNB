import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { Paginated, SupportSession } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { formatNumber } from '@/lib/format'

/**
 * Every time the platform looked inside a customer's account.
 *
 * This log is what makes impersonation defensible. It is shown here to the
 * operator and, unchanged, to the customer at /organization/support-sessions —
 * same rows, same reasons. A support session nobody can audit is a back door with
 * better manners.
 */
export function PlatformSessionsPage() {
  const queryClient = useQueryClient()

  const list = useQuery({
    queryKey: ['platform-sessions'],
    queryFn: () =>
      api.get<Paginated<SupportSession>>('platform/impersonations', { per_page: 50 }),
    placeholderData: keepPreviousData,
  })

  const end = useMutation({
    mutationFn: (session: SupportSession) =>
      api.delete(`platform/impersonations/${session.id}`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['platform-sessions'] }),
  })

  const sessions = list.data?.data ?? []
  const open = sessions.filter((s) => s.is_open)

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Support sessions</h1>
          <div className="page-header__subtitle">
            {list.data
              ? `${formatNumber(list.data.meta.total)} recorded · ${open.length} open`
              : 'Loading…'}
          </div>
        </div>
      </div>

      {open.length > 0 && (
        <div className="notice notice--warning">
          {open.length} session(s) are open right now. Each one is somebody able to read a
          customer&rsquo;s account until it expires.
        </div>
      )}

      {end.error !== null && (
        <div className="notice notice--error" role="alert">
          {end.error instanceof ApiError ? end.error.message : 'That session could not be ended.'}
        </div>
      )}

      <QueryState
        isLoading={list.isLoading}
        error={list.error}
        isEmpty={sessions.length === 0}
        emptyTitle="No support sessions"
        emptyBody="Nobody has looked inside a customer's account."
      >
        <section className="card">
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Organization</th>
                  <th>Operator</th>
                  <th>Reason</th>
                  <th>When</th>
                  <th className="numeric">Read</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {sessions.map((session) => (
                  <tr key={session.id}>
                    <td>
                      <div className="strong">{session.organization?.name ?? '—'}</div>
                      <div className="small faint">
                        as {session.viewed_as?.email ?? 'unknown'}
                      </div>
                    </td>

                    <td className="small">{session.operator?.email ?? '—'}</td>

                    <td className="small wrap">{session.reason}</td>

                    <td className="small faint nowrap">
                      {session.started_at === null
                        ? '—'
                        : new Date(session.started_at).toLocaleString()}
                      <div className="row mt-1">
                        {session.is_open ? (
                          <Chip label="Open" colour="amber" />
                        ) : (
                          <Chip label={session.ended_reason ?? 'ended'} colour="zinc" />
                        )}
                        {/* Stated on every row. The guarantee is the point. */}
                        <Chip label="Read-only" colour="slate" />
                      </div>
                    </td>

                    <td className="numeric">{formatNumber(session.request_count)}</td>

                    <td>
                      {session.is_open && (
                        <button
                          type="button"
                          className="btn btn--danger btn--sm"
                          onClick={() => end.mutate(session)}
                          disabled={end.isPending}
                        >
                          End now
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </QueryState>
    </>
  )
}
