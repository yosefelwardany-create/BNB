import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type {
  Paginated,
  ReportDefinition,
  ReportDestination,
  ReportDestinationOption,
  SavedReport,
} from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { formatDate } from '@/lib/format'
import { useAuth } from '@/lib/auth'

/**
 * Saved reports and where they go.
 *
 * A schedule used to mean email and nothing else, so an operator who wanted
 * last month's occupancy in their own system was told to receive an attachment
 * and forward it by hand — which is how a figure ends up retyped, and how a
 * retyped figure ends up wrong.
 *
 * Two things this screen refuses to hide:
 *
 *  - **`last_error`.** The question somebody actually has about a scheduled
 *    report is why it stopped arriving, and a silence they mistake for
 *    "nothing to say this month" is the failure worth preventing.
 *  - **A schedule with nowhere to go.** It runs, produces nothing anybody
 *    sees, and looks identical to one that is broken.
 */
export function ReportSchedules({ report }: { report: ReportDefinition | null }) {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [creating, setCreating] = useState(false)

  const saved = useQuery({
    queryKey: ['saved-reports'],
    queryFn: () => api.get<Paginated<SavedReport>>('reports/saved', { per_page: 50 }),
  })

  const destinations = useQuery({
    queryKey: ['report-destinations'],
    queryFn: () => api.get<{ data: ReportDestinationOption[] }>('reports/destinations'),
  })

  const remove = useMutation({
    mutationFn: (item: SavedReport) => api.delete(`reports/saved/${item.id}`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['saved-reports'] }),
  })

  const reports = saved.data?.data ?? []

  return (
    <section className="card mt-3">
      <header className="card__header">
        <h2>Saved and scheduled</h2>

        {can('reports.view') && report !== null && (
          <button
            type="button"
            className="btn btn--sm"
            onClick={() => setCreating((open) => !open)}
          >
            {creating ? 'Cancel' : `Save “${report.name}”`}
          </button>
        )}
      </header>

      {creating && report !== null && (
        <ScheduleForm
          report={report}
          options={destinations.data?.data ?? []}
          onSaved={() => {
            setCreating(false)
            void queryClient.invalidateQueries({ queryKey: ['saved-reports'] })
          }}
        />
      )}

      <div className="card__body">
        <QueryState
          isLoading={saved.isLoading}
          error={saved.error}
          isEmpty={reports.length === 0}
          emptyTitle="Nothing saved yet"
          emptyBody="Save a report to run it again, or to have it delivered on a schedule."
        >
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Report</th>
                  <th>Schedule</th>
                  <th>Goes to</th>
                  <th>Last run</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {reports.map((item) => (
                  <tr key={item.id}>
                    <td>
                      <div className="strong">{item.name}</div>
                      <div className="small faint">{item.report_key}</div>
                    </td>

                    <td>
                      {item.is_scheduled ? (
                        <>
                          <span className="mono small">{item.schedule_cron}</span>
                          <div className="small faint">
                            {item.next_run_at === null
                              ? 'Not scheduled'
                              : `Next ${formatDate(item.next_run_at)}`}
                          </div>
                        </>
                      ) : (
                        <span className="small faint">Run by hand</span>
                      )}
                    </td>

                    <td>
                      <div className="row wrap">
                        {item.destinations.length === 0 ? (
                          // A schedule that delivers nowhere looks exactly
                          // like one that is broken, so it is called out
                          // rather than shown as an empty cell.
                          <Chip label="Nowhere" colour="amber" />
                        ) : (
                          item.destinations.map((destination, index) => (
                            <Chip
                              key={`${destination.type}-${index}`}
                              label={describe(destination)}
                              colour="slate"
                            />
                          ))
                        )}
                      </div>
                    </td>

                    <td>
                      <div className="small">
                        {item.last_run_at === null ? '—' : formatDate(item.last_run_at)}
                      </div>

                      {/* The question somebody actually has. */}
                      {item.last_error !== null && (
                        <div className="small danger wrap">{item.last_error}</div>
                      )}
                    </td>

                    <td>
                      <button
                        type="button"
                        className="btn btn--ghost btn--sm"
                        onClick={() => remove.mutate(item)}
                        disabled={remove.isPending}
                      >
                        Delete
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </QueryState>
      </div>
    </section>
  )
}

function describe(destination: ReportDestination): string {
  if (destination.type === 'email') {
    return `Email · ${(destination.recipients ?? []).length || 'report list'}`
  }

  if (destination.type === 'webhook') {
    try {
      return `Webhook · ${new URL(destination.url ?? '').host}`
    } catch {
      return 'Webhook'
    }
  }

  if (destination.type === 'storage') {
    return destination.retain_days === undefined
      ? 'Stored file'
      : `Stored file · ${destination.retain_days}d`
  }

  return destination.type
}

/**
 * Saving a report, and choosing where its runs go.
 *
 * The destination list comes from the server, so one added there appears here
 * without a frontend change — and this form can never offer one that does not
 * exist.
 */
function ScheduleForm({
  report,
  options,
  onSaved,
}: {
  report: ReportDefinition
  options: ReportDestinationOption[]
  onSaved: () => void
}) {
  const [name, setName] = useState(report.name)
  const [cron, setCron] = useState('')
  const [format, setFormat] = useState('csv')
  const [destinations, setDestinations] = useState<ReportDestination[]>([
    { type: 'email', recipients: [] },
  ])

  const save = useMutation({
    mutationFn: () =>
      api.post('reports/saved', {
        name,
        report_key: report.key,
        parameters: { period: 'last_month' },
        format,
        schedule_cron: cron === '' ? null : cron,
        destinations,
      }),
    onSuccess: onSaved,
  })

  function update(index: number, patch: Partial<ReportDestination>): void {
    setDestinations((current) =>
      current.map((destination, position) =>
        position === index ? { ...destination, ...patch } : destination,
      ),
    )
  }

  const error = save.error instanceof ApiError ? save.error : null

  return (
    <form
      className="card__body"
      onSubmit={(event) => {
        event.preventDefault()
        save.mutate()
      }}
    >
      {error !== null && (
        <div className="notice notice--error" role="alert">
          {error.message}
        </div>
      )}

      <div className="field">
        <label className="field__label" htmlFor="schedule-name">
          Name
        </label>
        <input
          id="schedule-name"
          value={name}
          onChange={(event) => setName(event.target.value)}
          required
        />
      </div>

      <div className="field">
        <label className="field__label" htmlFor="schedule-cron">
          Schedule
        </label>
        <input
          id="schedule-cron"
          value={cron}
          placeholder="0 8 1 * *"
          onChange={(event) => setCron(event.target.value)}
        />
        <span className="field__hint">
          A cron expression in your organization’s timezone. Leave it empty to save the
          report without a schedule.
        </span>
        {error?.fieldError('schedule_cron') !== undefined && (
          <span className="field__error">{error.fieldError('schedule_cron')}</span>
        )}
      </div>

      <div className="field">
        <label className="field__label" htmlFor="schedule-format">
          Format
        </label>
        <select
          id="schedule-format"
          value={format}
          onChange={(event) => setFormat(event.target.value)}
          style={{ width: 'auto' }}
        >
          <option value="csv">CSV</option>
          <option value="json">JSON</option>
        </select>
      </div>

      <h3>Where it goes</h3>

      {destinations.map((destination, index) => (
        <div key={index} className="panel mb-3">
          <div className="row row--between">
            <select
              value={destination.type}
              onChange={(event) =>
                setDestinations((current) =>
                  current.map((row, position) =>
                    position === index ? { type: event.target.value } : row,
                  ),
                )
              }
              style={{ width: 'auto' }}
              aria-label="Destination"
            >
              {options.map((option) => (
                <option key={option.key} value={option.key}>
                  {option.name}
                </option>
              ))}
            </select>

            <button
              type="button"
              className="btn btn--ghost btn--sm"
              onClick={() =>
                setDestinations((current) => current.filter((_, position) => position !== index))
              }
            >
              Remove
            </button>
          </div>

          <p className="small faint">
            {options.find((option) => option.key === destination.type)?.description}
          </p>

          {destination.type === 'email' && (
            <div className="field">
              <label className="field__label" htmlFor={`recipients-${index}`}>
                Email addresses
              </label>
              <input
                id={`recipients-${index}`}
                value={(destination.recipients ?? []).join(', ')}
                placeholder="owner@example.com, accounts@example.com"
                onChange={(event) =>
                  update(index, {
                    recipients: event.target.value
                      .split(',')
                      .map((address) => address.trim())
                      .filter((address) => address !== ''),
                  })
                }
              />
            </div>
          )}

          {destination.type === 'webhook' && (
            <>
              <div className="field">
                <label className="field__label" htmlFor={`url-${index}`}>
                  URL
                </label>
                <input
                  id={`url-${index}`}
                  value={destination.url ?? ''}
                  placeholder="https://example.com/reports"
                  onChange={(event) => update(index, { url: event.target.value })}
                />
                <span className="field__hint">
                  HTTPS only, and not an address inside our own network.
                </span>
              </div>

              <div className="field">
                <label className="field__label" htmlFor={`secret-${index}`}>
                  Signing secret
                </label>
                <input
                  id={`secret-${index}`}
                  type="password"
                  value={destination.secret ?? ''}
                  onChange={(event) => update(index, { secret: event.target.value })}
                />
                <span className="field__hint">
                  At least 16 characters. We sign each payload with it so your receiver can
                  prove the report came from us. It is never shown again.
                </span>
              </div>
            </>
          )}

          {destination.type === 'storage' && (
            <div className="field">
              <label className="field__label" htmlFor={`retain-${index}`}>
                Days to keep
              </label>
              <input
                id={`retain-${index}`}
                type="number"
                min={1}
                value={destination.retain_days ?? 365}
                onChange={(event) => update(index, { retain_days: Number(event.target.value) })}
              />
            </div>
          )}

          {error?.fieldError(`destinations.${index}`) !== undefined && (
            <span className="field__error">{error.fieldError(`destinations.${index}`)}</span>
          )}
        </div>
      ))}

      <div className="row row--between">
        <button
          type="button"
          className="btn btn--ghost btn--sm"
          onClick={() => setDestinations((current) => [...current, { type: 'email' }])}
        >
          Add a destination
        </button>

        <button type="submit" className="btn" disabled={save.isPending}>
          {save.isPending ? 'Saving…' : 'Save report'}
        </button>
      </div>
    </form>
  )
}
