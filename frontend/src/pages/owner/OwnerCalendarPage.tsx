import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { api } from '@/api/client'
import { QueryState } from '@/components/QueryState'

interface CalendarDay {
  date: string
  available: boolean
  sold_units: number
  blocked_units: number
  total_units: number
}

interface CalendarListing {
  listing_id: string
  listing_name: string
  property_id: string
  property_name: string | null
  days: CalendarDay[]
}

/**
 * A month at a glance, per property.
 *
 * Built from the staff calendar endpoint, which already filters to the
 * properties this login may see — the same restriction that governs every other
 * list — so an owner gets their own and nothing else without a second endpoint
 * to keep in step.
 *
 * Read-only by construction: there is nothing to click. An owner closing a night
 * here would be writing to a calendar that a channel manager treats as the
 * truth, and the consequences of that reach a guest's booking.
 *
 * A night is one of three things, and the legend says which: sold, closed, or
 * free. "Closed" covers an owner's own stay and maintenance alike, because from
 * the calendar's side they are the same fact — the night cannot be sold.
 */
export function OwnerCalendarPage() {
  const [month, setMonth] = useState(() => startOfMonth(new Date()))

  const from = toDateString(month)
  const to = toDateString(endOfMonth(month))

  const calendar = useQuery({
    queryKey: ['owner-calendar', from, to],
    queryFn: () => api.get<{ data: CalendarListing[] }>('calendar', { from, to }),
  })

  const listings = calendar.data?.data ?? []
  const days = useMemo(() => daysOfMonth(month), [month])

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Calendar</h1>
          <div className="page-header__subtitle">
            {month.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}
          </div>
        </div>

        <div className="row gap-2">
          <button
            type="button"
            className="btn btn--ghost btn--sm"
            onClick={() => setMonth((m) => addMonths(m, -1))}
          >
            <ChevronLeft size={14} aria-hidden /> Previous
          </button>
          <button type="button" className="btn btn--ghost btn--sm" onClick={() => setMonth(startOfMonth(new Date()))}>
            This month
          </button>
          <button
            type="button"
            className="btn btn--ghost btn--sm"
            onClick={() => setMonth((m) => addMonths(m, 1))}
          >
            Next <ChevronRight size={14} aria-hidden />
          </button>
        </div>
      </div>

      <section className="card mb-3">
        <QueryState
          isLoading={calendar.isLoading}
          error={calendar.error}
          isEmpty={listings.length === 0}
          emptyTitle="Nothing to show"
          emptyBody="Once a property is attached to you, its calendar appears here."
        >
          <div className="card__body stack">
            <div className="row row--wrap gap-3 small faint">
              <span><i className="owner-cal__key owner-cal__key--sold" aria-hidden /> Sold</span>
              <span><i className="owner-cal__key owner-cal__key--closed" aria-hidden /> Closed</span>
              <span><i className="owner-cal__key owner-cal__key--free" aria-hidden /> Free</span>
            </div>

            <div className="table-wrap">
              <table className="data owner-cal">
                <thead>
                  <tr>
                    <th>Property</th>
                    {days.map((day) => (
                      <th key={day} className="owner-cal__head">
                        {Number(day.slice(-2))}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {listings.map((listing) => {
                    const byDate = new Map(listing.days.map((d) => [d.date, d]))

                    return (
                      <tr key={listing.listing_id}>
                        <td>{listing.property_name ?? listing.listing_name}</td>
                        {days.map((day) => {
                          const cell = byDate.get(day)
                          const state = stateOf(cell)

                          return (
                            <td
                              key={day}
                              className={`owner-cal__cell owner-cal__cell--${state}`}
                              title={`${day} · ${LABELS[state]}`}
                            >
                              <span className="sr-only">{LABELS[state]}</span>
                            </td>
                          )
                        })}
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </div>
        </QueryState>
      </section>
    </>
  )
}

type DayState = 'sold' | 'closed' | 'free' | 'unknown'

const LABELS: Record<DayState, string> = {
  sold: 'Sold',
  closed: 'Closed',
  free: 'Free',
  unknown: 'No information',
}

/**
 * Sold beats closed.
 *
 * A night can be both — a booking exists and the calendar is also closed — and
 * the owner cares that it sold. Reporting it as closed would read as a night
 * nobody wanted.
 */
function stateOf(day: CalendarDay | undefined): DayState {
  if (day === undefined) return 'unknown'
  if (day.sold_units > 0) return 'sold'
  if (day.blocked_units > 0 || !day.available) return 'closed'

  return 'free'
}

function startOfMonth(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), 1)
}

function endOfMonth(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth() + 1, 0)
}

function addMonths(date: Date, by: number): Date {
  return new Date(date.getFullYear(), date.getMonth() + by, 1)
}

/** Local date parts, not toISOString: that shifts the day across a timezone. */
function toDateString(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${date.getFullYear()}-${month}-${day}`
}

function daysOfMonth(month: Date): string[] {
  const last = endOfMonth(month).getDate()

  return Array.from({ length: last }, (_, i) =>
    toDateString(new Date(month.getFullYear(), month.getMonth(), i + 1)),
  )
}
