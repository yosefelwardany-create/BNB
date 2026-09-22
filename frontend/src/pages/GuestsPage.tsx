import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { Guest, Paginated } from '@/api/types'
import { QueryState } from '@/components/QueryState'
import { formatDate, formatMoney } from '@/lib/format'

export function GuestsPage() {
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  const query = useQuery({
    queryKey: ['guests', { search, page }],
    queryFn: () =>
      api.get<Paginated<Guest>>('guests', {
        search: search || undefined,
        page,
        per_page: 25,
      }),
    placeholderData: keepPreviousData,
  })

  const guests = query.data?.data ?? []
  const meta = query.data?.meta

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Guests</h1>
          <div className="page-header__subtitle">
            {meta ? `${meta.total} profile(s)` : 'Loading…'}
          </div>
        </div>
      </div>

      <div className="filters">
        <div className="field">
          <label className="field__label" htmlFor="guest-search">
            Search
          </label>
          <input
            id="guest-search"
            type="search"
            placeholder="Name, email or phone"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
          />
        </div>
      </div>

      <div className="card">
        <QueryState
          isLoading={query.isLoading}
          error={query.error}
          isEmpty={guests.length === 0}
          emptyTitle="No guests yet"
          emptyBody="Guest profiles are created automatically with each booking."
        >
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Guest</th>
                  <th>Contact</th>
                  <th className="numeric">Stays</th>
                  <th className="numeric">Nights</th>
                  <th className="numeric">Lifetime value</th>
                  <th>Last stay</th>
                </tr>
              </thead>
              <tbody>
                {guests.map((guest) => (
                  <tr key={guest.id}>
                    <td>
                      <div className="strong">{guest.display_name}</div>
                      {guest.stats.is_returning && (
                        <span className="chip chip--indigo" style={{ marginTop: 3 }}>
                          Returning
                        </span>
                      )}
                    </td>
                    <td className="small">
                      <div className="truncate" style={{ maxWidth: 200 }}>
                        {guest.email ?? '—'}
                      </div>
                      <div className="faint">{guest.phone ?? ''}</div>
                    </td>
                    <td className="numeric">{guest.stats.reservations}</td>
                    <td className="numeric">{guest.stats.nights}</td>
                    <td className="numeric">{formatMoney(guest.stats.lifetime_value)}</td>
                    <td className="nowrap small">{formatDate(guest.stats.last_stay_date)}</td>
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
