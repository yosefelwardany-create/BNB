import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { ChannelAccount, Paginated, SyncJob } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { addCalendarDays, formatDate, formatDateRange } from '@/lib/format'

/** One message to a channel, however many room types travelled in it. */
interface Push {
  taskId: string
  kind: string
  accountId: string | null
  sentAt: string | null
  rooms: string[]
  from: string | null
  to: string | null
  failed: boolean
}

const KIND_LABELS: Record<string, string> = {
  availability: 'Availability',
  rates: 'Prices and restrictions',
}

/**
 * The pushes a channel gave an id to, newest first, one row per id.
 *
 * Channex batches every room type of a property into one message, so several
 * mappings' records quote the same task id; grouped here, a row is one call —
 * which is what its certification form asks to be quoted, test by test.
 */
function group(jobs: SyncJob[]): Push[] {
  const pushes = new Map<string, Push>()

  for (const job of jobs) {
    if (job.external_reference === null) continue
    const key = `${job.kind}|${job.external_reference}`
    const push = pushes.get(key) ?? {
      taskId: job.external_reference,
      kind: job.kind,
      accountId: job.channel_account_id,
      sentAt: job.started_at ?? job.created_at,
      rooms: [],
      from: job.range_start,
      to: job.range_end,
      failed: false,
    }

    if (job.listing_name && !push.rooms.includes(job.listing_name)) push.rooms.push(job.listing_name)
    if (job.range_start !== null && (push.from === null || job.range_start < push.from)) push.from = job.range_start
    if (job.range_end !== null && (push.to === null || job.range_end > push.to)) push.to = job.range_end
    push.failed = push.failed || job.status === 'failed'
    pushes.set(key, push)
  }

  return [...pushes.values()]
}

/** First to last night sent. The record's end is the morning after the last. */
function nights(push: Push): string {
  if (push.from === null || push.to === null) return '—'
  const last = addCalendarDays(push.to, -1)

  return last <= push.from ? formatDate(push.from) : formatDateRange(push.from, last)
}

export function ChannelPushLog({ accounts }: { accounts: ChannelAccount[] }) {
  const [copied, setCopied] = useState<string | null>(null)

  const jobs = useQuery({
    queryKey: ['channel-sync-jobs'],
    queryFn: () => api.get<Paginated<SyncJob>>('channel-sync/jobs', { with_reference: 1, direction: 'push', per_page: 100 }),
  })

  const names = new Map(accounts.map((account) => [account.id, account.name]))
  const pushes = group(jobs.data?.data ?? [])

  async function copy(taskId: string) {
    try {
      await navigator.clipboard.writeText(taskId)
      setCopied(taskId)
    } catch {
      setCopied(null)
    }
  }

  return (
    <section className="card mb-3">
      <header className="card__header row row--between">
        <div>
          <h2>Recent pushes</h2>
          <span className="small faint">
            Each call the channel accepted, with the task id it gave back. Channex’s certification form asks for one per test.
          </span>
        </div>
        <button type="button" className="btn btn--ghost btn--sm" onClick={() => void jobs.refetch()} disabled={jobs.isFetching}>
          {jobs.isFetching ? 'Refreshing…' : 'Refresh'}
        </button>
      </header>

      <QueryState
        isLoading={jobs.isLoading}
        error={jobs.error}
        isEmpty={pushes.length === 0}
        emptyTitle="Nothing pushed yet"
        emptyBody="Change a price or availability, or press Full sync, and the call appears here with its task id."
      >
        <div className="table-wrap">
          <table className="data">
            <thead>
              <tr>
                <th>Sent</th>
                <th>What</th>
                <th>Room types</th>
                <th>Nights</th>
                <th>Task id</th>
              </tr>
            </thead>
            <tbody>
              {pushes.map((push) => (
                <tr key={`${push.kind}|${push.taskId}`}>
                  <td className="small">
                    <div>{push.sentAt === null ? '—' : new Date(push.sentAt).toLocaleString()}</div>
                    {push.accountId !== null && names.has(push.accountId) && <div className="faint">{names.get(push.accountId)}</div>}
                  </td>
                  <td>
                    <div className="row">
                      {KIND_LABELS[push.kind] ?? push.kind}
                      {push.failed && <Chip label="Partly refused" colour="rose" />}
                    </div>
                  </td>
                  <td className="small">{push.rooms.length === 0 ? '—' : push.rooms.join(', ')}</td>
                  <td className="small">{nights(push)}</td>
                  <td>
                    <div className="row">
                      <span className="mono small">{push.taskId}</span>
                      <button type="button" className="btn btn--ghost btn--sm" onClick={() => void copy(push.taskId)} aria-label={`Copy task id ${push.taskId}`}>
                        {copied === push.taskId ? 'Copied' : 'Copy'}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </QueryState>
    </section>
  )
}
