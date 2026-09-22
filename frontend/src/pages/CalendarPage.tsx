import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { CalendarResponse } from '@/api/types'
import { QueryState } from '@/components/QueryState'
import { addDays, toDateInput } from '@/lib/format'

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
  const [start, setStart] = useState(() => toDateInput(new Date()))
  const [span, setSpan] = useState(30)

  const end = useMemo(() => toDateInput(addDays(new Date(start), span)), [start, span])

  const query = useQuery({
    queryKey: ['calendar', start, end],
    queryFn: () => api.get<CalendarResponse>('calendar', { from: start, to: end }),
  })

  const rows = query.data?.listings ?? []

  const dates = useMemo(() => {
    const list: Date[] = []
    const from = new Date(start)

    for (let index = 0; index < span; index++) {
      list.push(addDays(from, index))
    }

    return list
  }, [start, span])

  // A booking's guest name, keyed by the dates it covers, so a cell can show
  // who is in the property rather than only that it is sold.
  const occupants = useMemo(() => {
    const map = new Map<string, string>()

    for (const reservation of query.data?.reservations ?? []) {
      const cursor = new Date(reservation.check_in_date)
      const until = new Date(reservation.check_out_date)

      while (cursor < until) {
        map.set(
          `${reservation.listing_id ?? reservation.property_id}|${toDateInput(cursor)}`,
          reservation.guest_name ?? reservation.confirmation_code,
        )
        cursor.setDate(cursor.getDate() + 1)
      }
    }

    return map
  }, [query.data])

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Calendar</h1>
          <div className="page-header__subtitle">Availability across every published listing</div>
        </div>
      </div>

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
          onClick={() => setStart(toDateInput(new Date()))}
        >
          Today
        </button>
      </div>

      <div className="card">
        <QueryState
          isLoading={query.isLoading}
          error={query.error}
          isEmpty={rows.length === 0}
          emptyTitle="No published listings"
          emptyBody="Publish a listing to see its calendar here."
        >
          <div className="calendar">
            <table>
              <thead>
                <tr>
                  <th className="calendar__listing">Listing</th>
                  {dates.map((date) => {
                    const weekend = date.getDay() === 0 || date.getDay() === 6

                    return (
                      <th
                        key={date.toISOString()}
                        className={`calendar__header${weekend ? ' calendar__day--weekend' : ''}`}
                      >
                        <div>{date.toLocaleDateString('en-GB', { weekday: 'narrow' })}</div>
                        <div className="strong">{date.getDate()}</div>
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
                        <div className="small faint">
                          {row.summary.occupancy_rate}% occupied
                        </div>
                      </td>

                      {dates.map((date) => {
                        const key = toDateInput(date)
                        const day = byDate.get(key)
                        const weekend = date.getDay() === 0 || date.getDay() === 6
                        const guest = occupants.get(`${row.listing_id}|${key}`)

                        const classes = ['calendar__day']

                        if (weekend) classes.push('calendar__day--weekend')

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
                          <td key={key} className={classes.join(' ')} title={label}>
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
