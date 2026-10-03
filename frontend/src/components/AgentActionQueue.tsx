import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, ClipboardList, X } from 'lucide-react'
import { useState } from 'react'
import { api } from '@/api/client'
import type { AgentAction } from '@/api/types'
import { Chip } from '@/components/Chip'

/**
 * What the agent wants to do, waiting for somebody to say yes.
 *
 * The screen the whole action design exists for. An agent that could change a
 * calendar the moment it was asked would need no queue, and would be a worse
 * product: "close next weekend" is said by people who have forgotten about a
 * booking, and the reply that matters is a person glancing at the proposal.
 *
 * Three decisions in how this is built:
 *
 * **The consequence is shown, not the arguments alone.** A row reading "Block
 * nights · 6–8 March" tells somebody what will happen and not what it costs. The
 * sentence underneath — an empty calendar is not noticed the way a double
 * booking is — is why this screen is read rather than clicked through.
 *
 * **`is_open` decides whether there are buttons**, not the status. A proposal
 * past its window is still `proposed` until the sweeper runs, and offering
 * Approve on it would let somebody approve a situation that has moved on.
 *
 * **Nothing is hidden once decided.** A rejected proposal stays visible with its
 * reason, because "the agent suggested cancelling that booking and we said no"
 * is the record somebody wants three months later.
 */
const STATUS: Record<
  AgentAction['status'],
  { label: string; colour: 'emerald' | 'amber' | 'rose' | 'slate' }
> = {
  proposed: { label: 'Waiting for you', colour: 'amber' },
  approved: { label: 'Approved', colour: 'emerald' },
  executed: { label: 'Done', colour: 'emerald' },
  rejected: { label: 'Turned down', colour: 'slate' },
  failed: { label: 'Could not complete', colour: 'rose' },
  expired: { label: 'Expired unread', colour: 'slate' },
}

export function AgentActionQueue({ propertyId }: { propertyId: string }) {
  const queryClient = useQueryClient()
  const [rejecting, setRejecting] = useState<string | null>(null)
  const [because, setBecause] = useState('')

  const actions = useQuery({
    queryKey: ['agent-actions', propertyId],
    queryFn: () =>
      api.get<{ data: AgentAction[]; meta: { waiting: number } }>(
        `properties/${propertyId}/agent/actions`,
      ),
  })

  const settled = () => {
    void queryClient.invalidateQueries({ queryKey: ['agent-actions', propertyId] })
    // An approved action writes to the calendar, the inbox or the rate table,
    // and the screen beside this one is usually showing exactly that.
    void queryClient.invalidateQueries({ queryKey: ['agent-activity', propertyId] })
    setRejecting(null)
    setBecause('')
  }

  const approve = useMutation({
    mutationFn: (id: string) => api.post(`properties/${propertyId}/agent/actions/${id}/approve`, {}),
    onSuccess: settled,
  })

  const reject = useMutation({
    mutationFn: (id: string) =>
      api.post(`properties/${propertyId}/agent/actions/${id}/reject`, {
        because: because.trim() === '' ? undefined : because.trim(),
      }),
    onSuccess: settled,
  })

  const rows = actions.data?.data ?? []
  const waiting = actions.data?.meta.waiting ?? 0

  return (
    <section className="card mb-3">
      <header className="card__header row row--between">
        <h2>
          <ClipboardList size={16} aria-hidden /> Waiting for approval
        </h2>
        {waiting > 0 && <Chip label={`${waiting} waiting`} colour="amber" />}
      </header>

      <div className="card__body stack">
        {rows.length === 0 ? (
          <p className="small muted">
            Nothing waiting. When this agent is asked to change something — reply to a guest, close
            nights, change a rate — the proposal appears here first unless you have said that
            particular thing may go ahead on its own.
          </p>
        ) : (
          rows.map((row) => {
            const status = STATUS[row.status] ?? { label: row.status, colour: 'slate' as const }

            return (
              <article key={row.id} className="stack stack--tight bordered p-2">
                <div className="row row--between">
                  <span className="small">
                    <strong>{row.capability_label}</strong> · {row.summary}
                  </span>
                  <Chip label={status.label} colour={status.colour} />
                </div>

                {/* What is at stake, in the server's words. */}
                <p className="small faint">{row.consequence}</p>

                {/* The detail of the proposal, so Approve is not a leap of faith. */}
                {Object.keys(row.arguments).length > 0 && (
                  <dl className="small faint row row--wrap gap-2">
                    {Object.entries(row.arguments).filter(([key]) => !key.startsWith('_')).map(([key, value]) => (
                      <span key={key}>
                        <dt className="inline">{key.replace(/_/g, ' ')}:</dt>{' '}
                        <dd className="inline">{String(value)}</dd>
                      </span>
                    ))}
                  </dl>
                )}

                <p className="small faint">
                  {row.requested_by != null ? `Asked by ${row.requested_by}` : 'Nobody asked — the agent raised this itself'}
                  {row.was_autonomous && ' · ran without being read'}
                  {row.approved_by != null && ` · decided by ${row.approved_by}`}
                  {row.expires_at != null && row.is_open && (
                    <> · expires {new Date(row.expires_at).toLocaleString()}</>
                  )}
                </p>

                {/* The channel's own words, including when it refused. */}
                {row.outcome != null && <p className="small">{row.outcome}</p>}

                {row.is_open && (
                  <div className="stack stack--tight">
                    {rejecting === row.id && (
                      <label className="small stack stack--tight">
                        Why not? (optional, kept on the record)
                        <input
                          type="text"
                          value={because}
                          onChange={(event) => setBecause(event.target.value)}
                          placeholder="Those nights are already sold."
                        />
                      </label>
                    )}

                    <div className="row gap-2">
                      <button
                        type="button"
                        className="btn btn--sm"
                        disabled={approve.isPending || reject.isPending}
                        onClick={() => approve.mutate(row.id)}
                      >
                        <Check size={14} aria-hidden /> Approve
                      </button>
                      <button
                        type="button"
                        className="btn btn--sm btn--ghost"
                        disabled={approve.isPending || reject.isPending}
                        onClick={() =>
                          rejecting === row.id ? reject.mutate(row.id) : setRejecting(row.id)
                        }
                      >
                        <X size={14} aria-hidden />{' '}
                        {rejecting === row.id ? 'Confirm turning it down' : 'Turn it down'}
                      </button>
                    </div>
                  </div>
                )}
              </article>
            )
          })
        )}
      </div>
    </section>
  )
}
