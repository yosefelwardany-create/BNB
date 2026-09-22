import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type {
  RevenueDay,
  RevenuePace,
  RevenueProperty,
  RevenueSource,
  RevenueSummary,
} from '@/api/types'
import { QueryState } from '@/components/QueryState'
import { addDays, formatDate, formatMoney, formatNumber, formatPercent, toDateInput } from '@/lib/format'

/**
 * Revenue.
 *
 * Every figure is computed from reservation nights at request time, which is
 * why this screen and the reservations list can never disagree. Occupancy, ADR
 * and RevPAR are shown together rather than separately: ADR alone rewards
 * turning guests away, occupancy alone rewards giving rooms away, and only the
 * two read together say whether a month went well.
 */
export function RevenuePage() {
  const [from, setFrom] = useState(toDateInput(addDays(new Date(), -29)))
  const [to, setTo] = useState(toDateInput(new Date()))

  const window = { from, to }

  const summary = useQuery({
    queryKey: ['revenue-summary', window],
    queryFn: () => api.get<{ data: RevenueSummary }>('revenue/summary', window),
    placeholderData: keepPreviousData,
  })

  const daily = useQuery({
    queryKey: ['revenue-daily', window],
    queryFn: () => api.get<{ data: RevenueDay[] }>('revenue/daily', window),
    placeholderData: keepPreviousData,
  })

  const sources = useQuery({
    queryKey: ['revenue-sources', window],
    queryFn: () => api.get<{ data: RevenueSource[] }>('revenue/by-source', window),
    placeholderData: keepPreviousData,
  })

  const properties = useQuery({
    queryKey: ['revenue-properties', window],
    queryFn: () => api.get<{ data: RevenueProperty[] }>('revenue/by-property', window),
    placeholderData: keepPreviousData,
  })

  // Pace looks forward on its own window, because "how is the next quarter
  // filling" is a different question from "how did last month go" and sharing
  // one date range would answer neither.
  const pace = useQuery({
    queryKey: ['revenue-pace'],
    queryFn: () => api.get<{ data: RevenuePace }>('revenue/pace'),
  })

  const figures = summary.data?.data
  const days = daily.data?.data ?? []

  // The chart is drawn from the same rows the table shows, so a bar can never
  // disagree with the number beside it.
  const peak = days.reduce((highest, day) => Math.max(highest, day.nights_sold), 0)

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Revenue</h1>
          <div className="page-header__subtitle">
            {figures
              ? `${formatDate(figures.period.from)} – ${formatDate(figures.period.to)}`
              : 'Loading…'}
          </div>
        </div>

        <div className="row">
          <input
            type="date"
            value={from}
            max={to}
            onChange={(event) => setFrom(event.target.value)}
            style={{ width: 'auto' }}
            aria-label="From"
          />
          <span className="faint">–</span>
          <input
            type="date"
            value={to}
            min={from}
            onChange={(event) => setTo(event.target.value)}
            style={{ width: 'auto' }}
            aria-label="To"
          />
        </div>
      </div>

      <QueryState isLoading={summary.isLoading} error={summary.error}>
        <div className="grid grid--stats mb-3">
          <div className="card card__body">
            <div className="stat__label">Occupancy</div>
            <div className="stat__value">
              {figures ? formatPercent(figures.occupancy_rate) : '—'}
            </div>
            <div className="stat__meta">
              {figures
                ? `${formatNumber(figures.nights_sold)} of ${formatNumber(
                    figures.nights_available,
                  )} nights`
                : ''}
            </div>
          </div>

          <div className="card card__body">
            <div className="stat__label">ADR</div>
            <div className="stat__value">{formatMoney(figures?.adr)}</div>
            <div className="stat__meta">Average rate per night sold</div>
          </div>

          <div className="card card__body">
            <div className="stat__label">RevPAR</div>
            <div className="stat__value">{formatMoney(figures?.revpar)}</div>
            <div className="stat__meta">Per night available, sold or not</div>
          </div>

          <div className="card card__body">
            <div className="stat__label">Accommodation revenue</div>
            <div className="stat__value">
              {formatMoney(figures?.accommodation_revenue)}
            </div>
            <div className="stat__meta">
              {/* Named, because it is not the total a guest paid: fees and tax
                  are excluded, and a reader comparing this with a statement
                  needs to know that before they raise it as a discrepancy. */}
              Room revenue only — excludes fees and tax
            </div>
          </div>

          <div className="card card__body">
            <div className="stat__label">Bookings</div>
            <div className="stat__value">
              {figures ? formatNumber(figures.reservations) : '—'}
            </div>
            <div className="stat__meta">
              {figures ? `${figures.average_stay_nights.toFixed(1)} nights on average` : ''}
            </div>
          </div>
        </div>
      </QueryState>

      <section className="card mb-3">
        <header className="card__header">
          <h2>On the books</h2>
          <span className="small faint">
            {pace.data
              ? `${formatDate(pace.data.data.period.from)} – ${formatDate(
                  pace.data.data.period.to,
                )}, as at ${formatDate(pace.data.data.as_at)}`
              : ''}
          </span>
        </header>

        <QueryState isLoading={pace.isLoading} error={pace.error}>
          <div className="card__body">
            <div className="grid grid--stats">
              <div>
                <div className="stat__label">Revenue booked</div>
                <div className="stat__value">
                  {formatMoney(pace.data?.data.revenue_on_the_books)}
                </div>
              </div>
              <div>
                <div className="stat__label">Occupancy booked</div>
                <div className="stat__value">
                  {pace.data ? formatPercent(pace.data.data.occupancy_on_the_books) : '—'}
                </div>
              </div>
              <div>
                <div className="stat__label">Bookings</div>
                <div className="stat__value">
                  {pace.data ? formatNumber(pace.data.data.reservations_on_the_books) : '—'}
                </div>
              </div>
              <div>
                <div className="stat__label">Average lead time</div>
                <div className="stat__value">
                  {pace.data?.data.average_lead_time_days === null ||
                  pace.data?.data.average_lead_time_days === undefined
                    ? '—'
                    : `${Math.round(pace.data.data.average_lead_time_days)} days`}
                </div>
              </div>
            </div>
          </div>
        </QueryState>
      </section>

      <div className="grid grid--two mb-3">
        <section className="card">
          <header className="card__header">
            <h2>By source</h2>
          </header>

          <QueryState
            isLoading={sources.isLoading}
            error={sources.error}
            isEmpty={(sources.data?.data.length ?? 0) === 0}
            emptyTitle="No bookings in this period"
          >
            <div className="table-wrap">
              <table className="data">
                <thead>
                  <tr>
                    <th>Source</th>
                    <th className="numeric">Bookings</th>
                    <th className="numeric">Nights</th>
                    <th className="numeric">Revenue</th>
                    <th className="numeric">Share</th>
                  </tr>
                </thead>
                <tbody>
                  {(sources.data?.data ?? []).map((row) => (
                    <tr key={row.source}>
                      <td className="strong">{row.source}</td>
                      <td className="numeric">{formatNumber(row.reservations)}</td>
                      <td className="numeric">{formatNumber(row.nights_sold)}</td>
                      <td className="numeric">{formatMoney(row.accommodation_revenue)}</td>
                      <td className="numeric">{formatPercent(row.share_of_revenue)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </QueryState>
        </section>

        <section className="card">
          <header className="card__header">
            <h2>By property</h2>
          </header>

          <QueryState
            isLoading={properties.isLoading}
            error={properties.error}
            isEmpty={(properties.data?.data.length ?? 0) === 0}
            emptyTitle="No bookings in this period"
          >
            <div className="table-wrap">
              <table className="data">
                <thead>
                  <tr>
                    <th>Property</th>
                    <th className="numeric">Occupancy</th>
                    <th className="numeric">ADR</th>
                    <th className="numeric">RevPAR</th>
                    <th className="numeric">Revenue</th>
                  </tr>
                </thead>
                <tbody>
                  {(properties.data?.data ?? []).map((row) => (
                    <tr key={row.property_id}>
                      <td className="strong truncate">{row.property_name}</td>
                      <td className="numeric">{formatPercent(row.occupancy_rate)}</td>
                      <td className="numeric">{formatMoney(row.adr)}</td>
                      <td className="numeric">{formatMoney(row.revpar)}</td>
                      <td className="numeric">{formatMoney(row.accommodation_revenue)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </QueryState>
        </section>
      </div>

      <section className="card">
        <header className="card__header">
          <h2>Day by day</h2>
        </header>

        <QueryState
          isLoading={daily.isLoading}
          error={daily.error}
          isEmpty={days.length === 0}
          emptyTitle="Nothing in this period"
        >
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Nights sold</th>
                  <th className="numeric">Occupancy</th>
                  <th className="numeric">ADR</th>
                  <th className="numeric">Revenue</th>
                </tr>
              </thead>
              <tbody>
                {days.map((day) => (
                  <tr key={day.date}>
                    <td className="nowrap">{formatDate(day.date)}</td>
                    <td>
                      <div className="bar">
                        <div
                          className="bar__fill"
                          style={{
                            width: peak > 0 ? `${(day.nights_sold / peak) * 100}%` : '0%',
                          }}
                        />
                        <span className="bar__label">{formatNumber(day.nights_sold)}</span>
                      </div>
                    </td>
                    <td className="numeric">{formatPercent(day.occupancy_rate)}</td>
                    <td className="numeric">{formatMoney(day.adr)}</td>
                    <td className="numeric">{formatMoney(day.accommodation_revenue)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </QueryState>
      </section>
    </>
  )
}
