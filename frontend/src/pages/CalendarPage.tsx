import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type { CalendarResponse } from '@/api/types'
import { QueryState } from '@/components/QueryState'
import { RecordDialog } from '@/components/RecordDialog'
import { useRecordDialog } from '@/lib/useRecordDialog'
import type { FieldSpec, RecordValues } from '@/components/RecordDialog'
import { addCalendarDays, dateInTimezone, formatDateRange, toDateInput } from '@/lib/format'
import { useAuth } from '@/lib/auth'
import { usePropertyOptions } from '@/lib/options'

const RANGE_OPTIONS = [
  { days: 14, label: '2 weeks' },
  { days: 30, label: '1 month' },
  { days: 60, label: '2 months' },
]

/**
 * The multi-calendar.
 *
 * One row per listing, one cell per night. Every cell's state comes from the
 * availability engine rather than being derived in the browser, so what an
 * operator sees here is exactly what the booking engine will accept.
 */
export function CalendarPage() {
  const { can, session } = useAuth()
  const calendarClient = useQueryClient()

  const { options: blockProperties } = usePropertyOptions()
  const blockDialog = useRecordDialog<never>()

  const blockFields: FieldSpec[] = useMemo(
    () => [
      { name: 'property_id', label: 'Property', type: 'select', options: blockProperties, required: true },
      {
        name: 'kind',
        label: 'Why',
        type: 'select',
        required: true,
        options: [
          { value: 'owner_stay', label: 'Owner staying' },
          { value: 'maintenance', label: 'Maintenance' },
          { value: 'cleaning', label: 'Cleaning' },
          { value: 'renovation', label: 'Renovation' },
          { value: 'hold', label: 'Held' },
          { value: 'manual', label: 'Blocked by hand' },
        ],
      },
      { name: 'start_date', label: 'From', type: 'date', required: true },
      { name: 'end_date', label: 'To', type: 'date', required: true },
      { name: 'title', label: 'Label', type: 'text' },
      { name: 'notes', label: 'Notes', type: 'textarea' },
    ],
    [blockProperties],
  )

  const createBlock = useMutation({
    mutationFn: (values: RecordValues) => api.post('calendar/blocks', values),
    onSuccess: () => {
      blockDialog.close()
      void calendarClient.invalidateQueries({ queryKey: ['calendar'] })
    },
  })

  /*
   * Unblocking. The endpoint has always been there and nothing called it, so
   * blocking dates was a one-way door: the nights stayed shut for good, every
   * booking across them was refused as "already booked or blocked", and there
   * was no way to find out which block was doing it or undo it.
   *
   * Deleted rather than archived, unlike a listing or a reservation. A block is
   * an intention about empty nights — nobody was sold anything, no money moved,
   * nothing points at it. There is no history in it to keep.
   */
  const removeBlock = useMutation({
    mutationFn: (id: string) => api.delete(`calendar/blocks/${id}`),
    onSuccess: () => void calendarClient.invalidateQueries({ queryKey: ['calendar'] }),
  })

  const today = dateInTimezone(new Date(), session?.organization?.timezone ?? 'UTC')
  const [start, setStart] = useState(today)
  const [span, setSpan] = useState(30)
  // The date under the pointer, so its whole column lights up.
  const [hoverDate, setHoverDate] = useState<string | null>(null)

  const end = useMemo(() => addCalendarDays(start, span), [start, span])

  const query = useQuery({
    queryKey: ['calendar', start, end],
    queryFn: () => api.get<CalendarResponse>('calendar', { from: start, to: end }),
  })

  const rows = query.data?.listings ?? []
  const blocks = query.data?.blocks ?? []

  // The grid already knows every property's name; the block list only carries an
  // id, and "01m3q8…" is not a thing to ask somebody to recognise.
  const propertyNames = useMemo(
    () => new Map((query.data?.listings ?? []).map((row) => [row.property_id, row.property_name])),
    [query.data],
  )

  const dates = useMemo(() => {
    const list: Date[] = []
    for (let index = 0; index < span; index++) {
      list.push(new Date(`${addCalendarDays(start, index)}T12:00:00Z`))
    }

    return list
  }, [start, span])

  /*
   * A booking's guest name, keyed by the dates it covers, so a cell can show who
   * is in the property rather than only that it is sold.
   *
   * Keyed by listing *and* by property. A reservation's listing is nullable — a
   * listing removed outright takes the reference with it — and a row is drawn per
   * listing, so keying on the listing alone meant such a booking matched nothing
   * and its nights were sold with no name on them. The fallback was written and
   * could never fire, which is the kind of dead branch that reads as handled.
   */
  const occupants = useMemo(() => {
    const map = new Map<string, string>()

    for (const reservation of query.data?.reservations ?? []) {
      const cursor = new Date(reservation.check_in_date)
      const until = new Date(reservation.check_out_date)
      const who = reservation.guest_name ?? reservation.display_reference ?? reservation.confirmation_code

      while (cursor < until) {
        const date = toDateInput(cursor)

        if (reservation.listing_id !== null) {
          map.set(`${reservation.listing_id}|${date}`, who)
        }

        // Only as a fallback: a listing's own entry must win where both exist.
        const propertyKey = `property:${reservation.property_id}|${date}`

        if (!map.has(propertyKey)) map.set(propertyKey, who)

        cursor.setUTCDate(cursor.getUTCDate() + 1)
      }
    }

    return map
  }, [query.data])

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Calendar</h1>
          <div className="page-header__subtitle">Availability across every listing on the books</div>
        </div>

        {can('calendar.update') && (
          <button type="button" className="btn btn--primary" onClick={blockDialog.create}>
            <Plus size={16} aria-hidden /> Block dates
          </button>
        )}
      </div>

      {blockDialog.isOpen && (
        <RecordDialog
          title="Block dates"
          description="Dates nobody may book — an owner staying, a repair, a hold. Existing bookings in the range are refused rather than overwritten."
          fields={blockFields}
          submitLabel="Block them"
          pending={createBlock.isPending}
          error={createBlock.error}
          onSubmit={(values) => createBlock.mutate(values)}
          onClose={blockDialog.close}
        />
      )}

      <div className="filters">
        <div className="field">
          <label className="field__label" htmlFor="calendar-start">
            From
          </label>
          <input
            id="calendar-start"
            type="date"
            value={start}
            onChange={(event) => setStart(event.target.value)}
          />
        </div>

        <div className="field">
          <label className="field__label" htmlFor="calendar-span">
            Range
          </label>
          <select
            id="calendar-span"
            value={span}
            onChange={(event) => setSpan(Number(event.target.value))}
          >
            {RANGE_OPTIONS.map((option) => (
              <option key={option.days} value={option.days}>
                {option.label}
              </option>
            ))}
          </select>
        </div>

        <button
          type="button"
          className="btn"
          onClick={() => setStart(dateInTimezone(new Date(), session?.organization?.timezone ?? 'UTC'))}
        >
          Today
        </button>
      </div>

      <div className="card">
        <QueryState
          isLoading={query.isLoading}
          error={query.error}
          isEmpty={rows.length === 0}
          emptyTitle="Nothing to show a calendar for"
          emptyBody="Add a property and it appears here. A listing does not have to be published — a draft shows too, because bookings can be taken against one by hand."
        >
          <div className="calendar" onMouseLeave={() => setHoverDate(null)}>
            <table>
              <thead>
                <tr>
                  <th className="calendar__listing">Listing</th>
                  {dates.map((date) => {
                    const weekend = date.getUTCDay() === 0 || date.getUTCDay() === 6

                    return (
                      <th
                        key={date.toISOString()}
                        className={[
                          'calendar__header',
                          weekend ? 'calendar__day--weekend' : '',
                          toDateInput(date) === today ? 'calendar__day--today' : '',
                          toDateInput(date) === hoverDate ? 'calendar__day--column' : '',
                        ]
                          .filter(Boolean)
                          .join(' ')}
                      >
                        <div>{date.toLocaleDateString('en-GB', { weekday: 'narrow', timeZone: 'UTC' })}</div>
                        <div className="strong">{date.getUTCDate()}</div>
                      </th>
                    )
                  })}
                </tr>
              </thead>

              <tbody>
                {rows.map((row) => {
                  const byDate = new Map(row.days.map((day) => [day.date, day]))

                  return (
                    <tr key={row.listing_id}>
                      <td className="calendar__listing">
                        <div className="strong truncate">{row.property_name}</div>
                        <div className="small faint truncate">{row.listing_name}</div>
                        {/* Said plainly: these nights can be booked by hand but
                            no guest can reach them. */}
                        {row.listing_status !== 'published' && (
                          <div className="small faint">not on sale · {row.listing_status}</div>
                        )}
                        <div className="small faint">
                          {row.summary.occupancy_rate}% occupied
                        </div>
                        <div className="occupancy" aria-hidden="true">
                          <span style={{ width: `${Math.min(100, row.summary.occupancy_rate)}%` }} />
                        </div>
                      </td>

                      {dates.map((date) => {
                        const key = toDateInput(date)
                        const day = byDate.get(key)
                        const weekend = date.getUTCDay() === 0 || date.getUTCDay() === 6
                        const guest =
                          occupants.get(`${row.listing_id}|${key}`) ??
                          occupants.get(`property:${row.property_id}|${key}`)

                        const classes = ['calendar__day']

                        if (weekend) classes.push('calendar__day--weekend')
                        if (key === today) classes.push('calendar__day--today')
                        if (key === hoverDate) classes.push('calendar__day--column')

                        if (day !== undefined) {
                          if (day.sold_units > 0) classes.push('calendar__day--sold')
                          else if (day.manually_blocked || day.blocked_units > 0) {
                            classes.push('calendar__day--blocked')
                          }

                          if (day.closed_to_arrival) classes.push('calendar__day--closed')
                        }

                        const label = day === undefined
                          ? key
                          : [
                              key,
                              day.available ? 'Available' : 'Not available',
                              guest !== undefined ? `Guest: ${guest}` : null,
                              day.total_units > 1
                                ? `${day.remaining_units} of ${day.total_units} free`
                                : null,
                              day.minimum_nights !== null ? `Min ${day.minimum_nights} nights` : null,
                              day.closed_to_arrival ? 'Closed to arrival' : null,
                            ]
                              .filter(Boolean)
                              .join(' · ')

                        return (
                          <td
                            key={key}
                            className={classes.join(' ')}
                            title={label}
                            onMouseEnter={() => setHoverDate(key)}
                          >
                            {day !== undefined && day.total_units > 1
                              ? day.remaining_units
                              : day?.sold_units
                                ? '●'
                                : ''}
                          </td>
                        )
                      })}
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </QueryState>
      </div>

      {/*
        * The blocks behind the shut nights, each with a way out.
        *
        * Listed rather than only shaded into the grid because a blocked cell does
        * not say which block shut it, and "the property is already booked or
        * blocked on those dates" is the refusal a person gets when they try to
        * sell across one. Without this, the only cure for a block put in by
        * mistake was to stop using those dates.
        */}
      {blocks.length > 0 && (
        <div className="card mt-3">
          <div className="card__body stack">
            <h2 className="small strong">Blocked dates in this range</h2>

            <table className="data">
              <tbody>
                {blocks.map((block) => (
                  <tr key={block.id}>
                    <td>{propertyNames.get(block.property_id) ?? '—'}</td>
                    <td className="small muted">{block.label}</td>
                    <td className="nowrap small">
                      {formatDateRange(block.start_date, block.end_date)}
                    </td>
                    <td className="numeric small">
                      {block.nights} night{block.nights === 1 ? '' : 's'}
                    </td>
                    <td>
                      {can('calendar.update') && (
                        <button
                          type="button"
                          className="btn btn--sm btn--ghost"
                          disabled={removeBlock.isPending}
                          onClick={() => removeBlock.mutate(block.id)}
                        >
                          <Trash2 size={14} aria-hidden /> Unblock
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            {removeBlock.error !== null && (
              <p className="field__error small" role="alert">
                {removeBlock.error instanceof ApiError
                  ? removeBlock.error.message
                  : 'Those dates could not be unblocked.'}
              </p>
            )}
          </div>
        </div>
      )}

      <div className="row wrap mt-3 small muted">
        <span className="row">
          <span
            className="calendar__day calendar__day--sold"
            style={{ width: 14, height: 14, display: 'inline-block', borderRadius: 3 }}
          />
          Sold
        </span>
        <span className="row">
          <span
            className="calendar__day calendar__day--blocked"
            style={{ width: 14, height: 14, display: 'inline-block', borderRadius: 3 }}
          />
          Blocked
        </span>
        <span className="row">
          <span
            style={{
              width: 14,
              height: 14,
              display: 'inline-block',
              borderRadius: 3,
              boxShadow: 'inset 0 3px 0 var(--rose)',
              border: '1px solid var(--border)',
            }}
          />
          Closed to arrival
        </span>
      </div>
    </>
  )
}
