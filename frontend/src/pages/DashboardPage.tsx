import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '@/api/client'
import type { Paginated, Reservation } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { useAuth } from '@/lib/auth'
import { addDays, formatDate, formatMoney, toDateInput } from '@/lib/format'

/**
 * The operational overview: who is arriving, who is leaving, and what is owed.
 *
 * Every figure here is a live query against the API. Nothing on this screen is
 * illustrative.
 */
export function DashboardPage() {
  const { session, can } = useAuth()
  const today = toDateInput(new Date())
  const horizon = toDateInput(addDays(new Date(), 7))

  const arrivals = useQuery({
    queryKey: ['reservations', 'arrivals', today],
    queryFn: () =>
      api.get<Paginated<Reservation>>('reservations', {
        from: today,
        to: horizon,
        status: 'confirmed,checked_in',
        sort: 'check_in_date',
        per_page: 50,
      }),
    enabled: can('reservations.view'),
  })

  const unpaid = useQuery({
    queryKey: ['reservations', 'unpaid'],
    queryFn: () =>
      api.get<Paginated<Reservation>>('reservations', {
        unpaid_only: true,
        status: 'confirmed,checked_in,checked_out',
        sort: 'check_in_date',
        per_page: 20,
      }),
    enabled: can('reservations.view'),
  })

  const arriving = (arrivals.data?.data ?? []).filter((r) => r.stay.check_in_date >= today)
  const departing = (arrivals.data?.data ?? []).filter((r) => r.stay.check_out_date >= today)
  const owed = unpaid.data?.data ?? []

  const totalOwed = owed.reduce((sum, r) => sum + r.financials.balance_due.amount, 0)
  const currency = owed[0]?.financials.balance_due.currency ?? session?.organization.base_currency ?? 'USD'

  if (!can('reservations.view')) {
    return (
      <div className="empty">
        <div className="empty__title">Welcome, {session?.user.first_name}</div>
        <p>Your role does not include reservations. Use the menu to reach the areas you work in.</p>
      </div>
    )
  }

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Today</h1>
          <div className="page-header__subtitle">
            {formatDate(today)} · {session?.organization.timezone}
          </div>
        </div>
      </div>

      <div className="grid grid--stats mb-2" style={{ marginBottom: 20 }}>
        <div className="card">
          <div className="card__body">
            <div className="stat__label">Arrivals, next 7 days</div>
            <div className="stat__value">{arrivals.isLoading ? '—' : arriving.length}</div>
          </div>
        </div>

        <div className="card">
          <div className="card__body">
            <div className="stat__label">Departures, next 7 days</div>
            <div className="stat__value">{arrivals.isLoading ? '—' : departing.length}</div>
          </div>
        </div>

        <div className="card">
          <div className="card__body">
            <div className="stat__label">Outstanding balances</div>
            <div className="stat__value">
              {unpaid.isLoading
                ? '—'
                : formatMoney({ amount: totalOwed, currency, formatted: (totalOwed / 100).toFixed(2) })}
            </div>
            <div className="stat__meta">{owed.length} booking(s)</div>
          </div>
        </div>
      </div>

      <div className="grid grid--two">
        <section className="card">
          <div className="card__header">
            <h2>Upcoming arrivals</h2>
            <Link to="/reservations" className="small">
              All reservations
            </Link>
          </div>

          <QueryState
            isLoading={arrivals.isLoading}
            error={arrivals.error}
            isEmpty={arriving.length === 0}
            emptyTitle="No arrivals in the next week"
          >
            <div className="table-wrap">
              <table className="data">
                <thead>
                  <tr>
                    <th>Guest</th>
                    <th>Arrives</th>
                    <th>Nights</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {arriving.slice(0, 10).map((reservation) => (
                    <tr key={reservation.id}>
                      <td>
                        <div className="strong">{reservation.guest?.display_name ?? 'Unnamed guest'}</div>
                        <div className="mono faint">{reservation.confirmation_code}</div>
                      </td>
                      <td className="nowrap">{formatDate(reservation.stay.check_in_date)}</td>
                      <td className="numeric">{reservation.stay.nights}</td>
                      <td>
                        <Chip label={reservation.status_label} colour={reservation.status_colour} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </QueryState>
        </section>

        <section className="card">
          <div className="card__header">
            <h2>Awaiting payment</h2>
          </div>

          <QueryState
            isLoading={unpaid.isLoading}
            error={unpaid.error}
            isEmpty={owed.length === 0}
            emptyTitle="Nothing outstanding"
            emptyBody="Every confirmed booking has been paid in full."
          >
            <div className="table-wrap">
              <table className="data">
                <thead>
                  <tr>
                    <th>Guest</th>
                    <th>Arrives</th>
                    <th className="numeric">Owed</th>
                  </tr>
                </thead>
                <tbody>
                  {owed.slice(0, 10).map((reservation) => (
                    <tr key={reservation.id}>
                      <td>
                        <div className="strong">{reservation.guest?.display_name ?? 'Unnamed guest'}</div>
                        <div className="mono faint">{reservation.confirmation_code}</div>
                      </td>
                      <td className="nowrap">{formatDate(reservation.stay.check_in_date)}</td>
                      <td className="numeric strong">
                        {formatMoney(reservation.financials.balance_due)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </QueryState>
        </section>
      </div>
    </>
  )
}
