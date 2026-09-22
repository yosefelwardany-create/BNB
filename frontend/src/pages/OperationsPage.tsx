import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { TaskBoard, Task } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { toDateInput, addDays } from '@/lib/format'
import { useAuth } from '@/lib/auth'

/**
 * The day's work.
 *
 * A board rather than a list, because the question somebody has at eight in
 * the morning is "what is happening today and what is not going to happen".
 * The columns come from the server already grouped: re-deriving them here
 * would eventually disagree with the reservations screen about what counts as
 * overdue.
 */
export function OperationsPage() {
  const { can, session } = useAuth()
  const queryClient = useQueryClient()

  const [date, setDate] = useState(toDateInput(new Date()))
  const [mine, setMine] = useState(false)

  const board = useQuery({
    queryKey: ['task-board', { date }],
    queryFn: () => api.get<TaskBoard>('tasks/board', { date }),
  })

  const complete = useMutation({
    mutationFn: (task: Task) => api.post(`tasks/${task.id}/complete`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['task-board'] }),
  })

  const start = useMutation({
    mutationFn: (task: Task) => api.post(`tasks/${task.id}/start`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['task-board'] }),
  })

  const board_ = board.data
  const summary = board_?.summary

  // The order a morning is worked through: what nobody has picked up, what is
  // already late, what is coming, and what is done.
  const columns: { key: string; label: string; tasks: Task[] }[] = [
    { key: 'unassigned', label: 'Unassigned', tasks: board_?.data.unassigned ?? [] },
    { key: 'overdue', label: 'Overdue', tasks: board_?.data.overdue ?? [] },
    { key: 'scheduled', label: 'Scheduled', tasks: board_?.data.scheduled ?? [] },
    { key: 'completed', label: 'Completed', tasks: board_?.data.completed ?? [] },
  ]

  const visibleColumns = mine
    ? columns.map((column) => ({
        ...column,
        tasks: column.tasks.filter((task) => task.assigned_to_id === session?.user.id),
      }))
    : columns

  const failed = complete.error ?? start.error

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Operations</h1>
          <div className="page-header__subtitle">
            {summary
              ? `${summary.total} task(s) · ${summary.open} open · ${summary.overdue} overdue`
              : 'Loading…'}
          </div>
        </div>

        <div className="row">
          <button
            type="button"
            className="btn btn--ghost btn--sm"
            onClick={() => setDate(toDateInput(addDays(new Date(date), -1)))}
          >
            ‹ Previous
          </button>
          <input
            type="date"
            value={date}
            onChange={(event) => setDate(event.target.value)}
            style={{ width: 'auto' }}
            aria-label="Board date"
          />
          <button
            type="button"
            className="btn btn--ghost btn--sm"
            onClick={() => setDate(toDateInput(addDays(new Date(date), 1)))}
          >
            Next ›
          </button>
        </div>
      </div>

      <div className="filters">
        <label className="row small">
          <input type="checkbox" checked={mine} onChange={(e) => setMine(e.target.checked)} />
          Only my work
        </label>
      </div>

      {failed !== null && failed !== undefined && (
        <div className="notice notice--error" role="alert">
          {/* A blocked completion is usually a checklist item or a missing
              photograph, and the server says which — so it is shown verbatim
              rather than replaced with "could not complete". */}
          {failed instanceof ApiError ? failed.message : 'That action could not be completed.'}
        </div>
      )}

      <QueryState
        isLoading={board.isLoading}
        error={board.error}
        isEmpty={visibleColumns.every((column) => column.tasks.length === 0)}
        emptyTitle="Nothing scheduled"
        emptyBody="No work is due on this day."
      >
        <div className="board">
          {visibleColumns.map((column) => (
            <section key={column.key} className="board__column">
              <header className="board__header">
                <span>{column.label}</span>
                <span className="chip chip--slate">{column.tasks.length}</span>
              </header>

              <div className="board__body">
                {column.tasks.map((task) => (
                  <article key={task.id} className="task-card">
                    <div className="row row--between">
                      <span className="mono small">{task.reference}</span>
                      <Chip label={task.priority_label} colour={task.priority_colour} />
                    </div>

                    <div className="strong mt-1">{task.title}</div>

                    <div className="small faint">
                      {task.property?.name ?? '—'}
                      {task.due_at !== null && (
                        <>
                          {' · '}
                          {new Date(task.due_at).toLocaleTimeString([], {
                            hour: '2-digit',
                            minute: '2-digit',
                          })}
                        </>
                      )}
                    </div>

                    {task.is_overdue && (
                      <div className="mt-1">
                        {/* Stated on the card rather than only as a colour:
                            a clean finished after the guest arrived was
                            completed and was also a failure. */}
                        <Chip label="Overdue" colour="rose" />
                      </div>
                    )}

                    {task.breaches_sla && (
                      <div className="mt-1">
                        <Chip label="Past its response time" colour="amber" />
                      </div>
                    )}

                    <div className="row mt-2">
                      {/* Offered only where the server's own lifecycle says
                          it is available, rather than by guessing from the
                          status here and then being refused. */}
                      {task.allowed_transitions.includes('in_progress') && can('tasks.update') && (
                        <button
                          type="button"
                          className="btn btn--ghost btn--sm"
                          onClick={() => start.mutate(task)}
                          disabled={start.isPending}
                        >
                          Start
                        </button>
                      )}

                      {task.allowed_transitions.includes('completed') &&
                        can('tasks.complete') && (
                          <button
                            type="button"
                            className="btn btn--sm"
                            onClick={() => complete.mutate(task)}
                            disabled={complete.isPending}
                          >
                            Complete
                          </button>
                        )}

                      {! task.is_assigned && (
                        <span className="small faint">Unassigned</span>
                      )}
                    </div>
                  </article>
                ))}

                {column.tasks.length === 0 && (
                  <div className="small faint" style={{ padding: '0.75rem' }}>
                    Nothing here.
                  </div>
                )}
              </div>
            </section>
          ))}
        </div>
      </QueryState>
    </>
  )
}
