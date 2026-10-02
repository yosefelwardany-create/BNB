import { useQuery } from '@tanstack/react-query'
import { History, ShieldAlert } from 'lucide-react'
import { api } from '@/api/client'
import type { AgentActivity } from '@/api/types'
import { Chip } from '@/components/Chip'

/**
 * What this property's agent has actually done.
 *
 * The question it exists to answer is not "is it working" but "did anything go
 * out without a person reading it", so that count is stated at the top rather
 * than left to be counted off the rows. Everything else is secondary: an
 * operator reading this at speed should be able to stop reading as soon as that
 * number is zero.
 *
 * Nothing here is editable. An activity row is what happened.
 */
const KINDS: Record<AgentActivity['kind'], { label: string; colour: 'emerald' | 'amber' | 'rose' | 'slate' }> = {
  asked: { label: 'Asked', colour: 'slate' },
  answered: { label: 'Answered', colour: 'emerald' },
  drafted: { label: 'Drafted', colour: 'slate' },
  held: { label: 'Held for a person', colour: 'amber' },
  failed: { label: 'No answer', colour: 'rose' },
  expired: { label: 'Timed out', colour: 'slate' },
}

export function AgentActivityLog({ propertyId }: { propertyId: string }) {
  const activity = useQuery({
    queryKey: ['agent-activity', propertyId],
    queryFn: () =>
      api.get<{ data: AgentActivity[]; meta: { autonomous_count: number } }>(
        `properties/${propertyId}/agent/activity`,
      ),
  })

  const rows = activity.data?.data ?? []
  const autonomous = activity.data?.meta.autonomous_count ?? 0

  return (
    <section className="card mb-3">
      <header className="card__header row row--between">
        <h2>
          <History size={16} aria-hidden /> What the agent did
        </h2>
        {rows.length > 0 && (
          <span className="small faint">
            {autonomous === 0
              ? 'Nothing went out unread'
              : `${autonomous} went out without a person reading it`}
          </span>
        )}
      </header>

      <div className="card__body stack">
        {rows.length === 0 ? (
          <p className="small muted">
            Nothing yet. Every question put to this agent and every answer it gives is recorded
            here, including the ones nobody was watching.
          </p>
        ) : (
          rows.map((row) => {
            const kind = KINDS[row.kind] ?? { label: row.kind, colour: 'slate' as const }

            return (
              <div key={row.id} className="row row--between bordered p-2">
                <span className="stack stack--tight">
                  <span className="small">{row.summary}</span>
                  <span className="small faint">
                    {row.agent_name ?? 'The agent'}
                    {row.occurred_at !== null && <> · {new Date(row.occurred_at).toLocaleString()}</>}
                    {row.actor !== undefined && row.actor !== null && <> · asked by {row.actor}</>}
                  </span>
                </span>

                <span className="row">
                  {row.is_autonomous && (
                    <span className="small" title="This could have reached a guest unread">
                      <ShieldAlert size={13} aria-hidden /> unread
                    </span>
                  )}
                  <Chip label={kind.label} colour={kind.colour} />
                </span>
              </div>
            )
          })
        )}
      </div>
    </section>
  )
}
