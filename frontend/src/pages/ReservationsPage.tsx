import { useState } from 'react'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { Paginated, Reservation } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { formatDateRange, formatMoney } from '@/lib/format'

const STATUSES = [
  { value: '', label: 'All statuses' },
  { value: 'inquiry,quote', label: 'Enquiries' },
  { value: 'tentative', label: 'Held' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'checked_in', label: 'In house' },
  { value: 'checked_out', label: 'Departed' },
  { value: 'cancelled,no_show', label: 'Cancelled' },
]

export function ReservationsPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)

  const query = useQuery({
    queryKey: ['reservations', { search, status, page }],
    queryFn: () =>
      api.get<Paginated<Reservation>>('reservations', {
        search: search || undefined,
        status: status || undefined,
        page,
        per_page: 25,
        sort: 'check_in_date',
      }),
    // Keeping the previous page visible while the next loads avoids the table
    // collapsing to a spinner on every keystroke.
    placeholderData: keepPreviousData,
  })

  const reservations = query.data?.data ?? []
  const meta = query.data?.meta

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Reservations</h1>
          <div className="page-header__subtitle">
            {meta ? `${meta.total} booking(s)` : 'Loading…'}
          </div>
        </div>
      </div>

      <div className="filters">
        <div className="field">
          <label className="field__label" htmlFor="search">
            Search
          </label>
          <input
            id="search"
            type="search"
            placeholder="Code, guest name or email"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
          />
        </div>

        <div className="field">
          <label className="field__label" htmlFor="status">
            Status
          </label>
          <select
            id="status"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value)
              setPage(1)
            }}
          >
            {STATUSES.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>
      </div>

      <div className="card">
        <QueryState
          isLoading={query.isLoading}
          error={query.error}
          isEmpty={reservations.length === 0}
          emptyTitle="No reservations match"
          emptyBody="Try widening the filters."
        >
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Guest</th>
                  <th>Stay</th>
                  <th className="numeric">Nights</th>
                  <th className="numeric">Guests</th>
                  <th>Source</th>
                  <th>Status</th>
                  <th className="numeric">Total</th>
                  <th className="numeric">Owed</th>
                </tr>
              </thead>
              <tbody>
                {reservations.map((reservation) => (
                  <tr key={reservation.id}>
                    <td className="mono">{reservation.confirmation_code}</td>
                    <td className="truncate" style={{ maxWidth: 180 }}>
                      {reservation.guest?.display_name ?? '—'}
                    </td>
                    <td className="nowrap">
                      {formatDateRange(reservation.stay.check_in_date, reservation.stay.check_out_date)}
                    </td>
                    <td className="numeric">{reservation.stay.nights}</td>
                    <td className="numeric">{reservation.guests.total}</td>
                    <td className="small muted">{reservation.source}</td>
                    <td>
                      <Chip label={reservation.status_label} colour={reservation.status_colour} />
                    </td>
                    <td className="numeric">{formatMoney(reservation.financials.grand_total)}</td>
                    <td className="numeric">
                      {reservation.financials.balance_due.amount > 0 ? (
                        <span className="strong">{formatMoney(reservation.financials.balance_due)}</span>
                      ) : (
                        <span className="faint">Paid</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </QueryState>

        {meta !== undefined && meta.last_page > 1 && (
          <div className="card__footer row row--between">
            <span className="small muted">
              Page {meta.current_page} of {meta.last_page}
            </span>
            <div className="row">
              <button
                type="button"
                className="btn btn--sm"
                disabled={meta.current_page <= 1}
                onClick={() => setPage((current) => current - 1)}
              >
                Previous
              </button>
              <button
                type="button"
                className="btn btn--sm"
                disabled={meta.current_page >= meta.last_page}
                onClick={() => setPage((current) => current + 1)}
              >
                Next
              </button>
            </div>
          </div>
        )}
      </div>
    </>
  )
}
