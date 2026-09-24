import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { motion } from 'motion/react'
import {
  ArrowRight,
  CalendarDays,
  ClipboardList,
  Inbox,
  LogIn,
  LogOut,
  Sparkles,
  Wallet,
  type LucideIcon,
} from 'lucide-react'
import { api } from '@/api/client'
import type { Paginated, Reservation } from '@/api/types'
import { Chip } from '@/components/Chip'
import { CountUp } from '@/components/CountUp'
import { QueryState } from '@/components/QueryState'
import { useAuth } from '@/lib/auth'
import { addDays, formatDate, formatMoney, toDateInput } from '@/lib/format'

/**
 * The operational overview: who is arriving, who is leaving, and what is owed.
 *
 * Every figure here is a live query against the API. Nothing on this screen is
 * illustrative — the week strip and the small bar charts are drawn from the
 * same reservations the tables below list.
 */
export function DashboardPage() {
  const { session, can } = useAuth()
  const today = toDateInput(new Date())
  const horizon = toDateInput(addDays(new Date(), 7))
  const [selectedDay, setSelectedDay] = useState<string | null>(null)

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
  const currency = owed[0]?.financials.balance_due.currency ?? session?.organization?.base_currency ?? 'USD'
  const largestOwed = Math.max(1, ...owed.map((r) => r.financials.balance_due.amount))

  // The next seven days, each with how many arrive and how many leave.
  const week = Array.from({ length: 7 }, (_, offset) => {
    const date = toDateInput(addDays(new Date(), offset))
    return {
      date,
      in: arriving.filter((r) => r.stay.check_in_date === date).length,
      out: departing.filter((r) => r.stay.check_out_date === date).length,
    }
  })
  const busiest = Math.max(1, ...week.map((day) => Math.max(day.in, day.out)))

  const shownArrivals =
    selectedDay === null ? arriving : arriving.filter((r) => r.stay.check_in_date === selectedDay)

  if (!can('reservations.view')) {
    return (
      <>
        <Hero firstName={session?.user.first_name} timezone={session?.organization?.timezone} today={today} />
        <div className="card">
          <div className="empty">
            <div className="empty__title">Welcome, {session?.user.first_name}</div>
            <p>Your role does not include reservations. Use the menu to reach the areas you work in.</p>
          </div>
        </div>
      </>
    )
  }

  return (
    <>
      <Hero firstName={session?.user.first_name} timezone={session?.organization?.timezone} today={today} />

      <div className="grid grid--stats" style={{ marginBottom: 18 }}>
        <Kpi
          icon={LogIn}
          label="Arrivals, next 7 days"
          value={arrivals.isLoading ? '—' : arriving.length}
          series={week.map((day) => day.in)}
        />
        <Kpi
          icon={LogOut}
          label="Departures, next 7 days"
          value={arrivals.isLoading ? '—' : departing.length}
          series={week.map((day) => day.out)}
        />
        <Kpi
          icon={Wallet}
          label="Outstanding balances"
          value={
            unpaid.isLoading
              ? '—'
              : formatMoney({ amount: totalOwed, currency, formatted: (totalOwed / 100).toFixed(2) })
          }
          meta={`${owed.length} booking(s)`}
        />
      </div>

      <section className="card" style={{ marginBottom: 18 }}>
        <div className="card__header">
          <h2>The week ahead</h2>
          <div className="row small muted">
            <span className="legend legend--in">Arriving</span>
            <span className="legend legend--out">Leaving</span>
            {selectedDay !== null && (
              <button type="button" className="btn btn--ghost btn--sm" onClick={() => setSelectedDay(null)}>
                Show every day
              </button>
            )}
          </div>
        </div>
        <div className="week" role="group" aria-label="Filter arrivals by day">
          {week.map((day, index) => {
            const selected = selectedDay === day.date
            const date = new Date(`${day.date}T12:00:00`)

            return (
              <button
                key={day.date}
                type="button"
                className={`week__day${selected ? ' week__day--selected' : ''}${index === 0 ? ' week__day--today' : ''}`}
                aria-pressed={selected}
                onClick={() => setSelectedDay(selected ? null : day.date)}
                title={`${day.in} arriving, ${day.out} leaving`}
              >
                <span className="week__name">
                  {index === 0 ? 'Today' : date.toLocaleDateString('en-GB', { weekday: 'short' })}
                </span>
                <span className="week__date">{date.getDate()}</span>
                <span className="week__bars" aria-hidden="true">
                  <motion.span
                    className="week__bar week__bar--in"
                    initial={{ scaleY: 0 }}
                    animate={{ scaleY: day.in / busiest }}
                    transition={{ delay: 0.2 + index * 0.05, type: 'spring', stiffness: 140, damping: 18 }}
                  />
                  <motion.span
                    className="week__bar week__bar--out"
                    initial={{ scaleY: 0 }}
                    animate={{ scaleY: day.out / busiest }}
                    transition={{ delay: 0.25 + index * 0.05, type: 'spring', stiffness: 140, damping: 18 }}
                  />
                </span>
                <span className="week__counts">
                  <span>{day.in}</span>
                  <span>{day.out}</span>
                </span>
              </button>
            )
          })}
        </div>
      </section>

      <div className="grid grid--two">
        <section className="card">
          <div className="card__header">
            <h2>
              {selectedDay === null ? 'Upcoming arrivals' : `Arriving ${formatDate(selectedDay)}`}
            </h2>
            <Link to="/reservations" className="small">
              All reservations
            </Link>
          </div>

          <QueryState
            isLoading={arrivals.isLoading}
            error={arrivals.error}
            isEmpty={shownArrivals.length === 0}
            emptyTitle={selectedDay === null ? 'No arrivals in the next week' : 'Nobody arrives that day'}
          >
            <div className="table-wrap">
              <table className="data">
                <thead>
                  <tr>
                    <th>Guest</th>
                    <th>Arrives</th>
                    <th className="numeric">Nights</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {shownArrivals.slice(0, 10).map((reservation) => (
                    <tr key={reservation.id}>
                      <td>
                        <div className="row">
                          <span className="avatar avatar--sm" aria-hidden="true">
                            {(reservation.guest?.display_name ?? '?').slice(0, 1).toUpperCase()}
                          </span>
                          <div style={{ minWidth: 0 }}>
                            <div className="strong">{reservation.guest?.display_name ?? 'Unnamed guest'}</div>
                            <div className="mono faint">{reservation.confirmation_code}</div>
                          </div>
                        </div>
                      </td>
                      <td className="nowrap">
                        {formatDate(reservation.stay.check_in_date)}
                        <div className="small faint">
                          {reservation.stay.days_until_arrival === 0
                            ? 'Today'
                            : reservation.stay.days_until_arrival === 1
                              ? 'Tomorrow'
                              : `In ${reservation.stay.days_until_arrival} days`}
                        </div>
                      </td>
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
                        <div className="owed-bar" aria-hidden="true">
                          <span
                            style={{
                              transform: `scaleX(${reservation.financials.balance_due.amount / largestOwed})`,
                            }}
                          />
                        </div>
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

function greeting(hour: number): string {
  if (hour < 5) return 'Good evening'
  if (hour < 12) return 'Good morning'
  if (hour < 18) return 'Good afternoon'
  return 'Good evening'
}

/** The time where the organization is, ticking once a minute. */
function useClock(timezone: string | undefined) {
  const [now, setNow] = useState(() => new Date())

  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 30_000)
    return () => window.clearInterval(timer)
  }, [])

  let time: string
  let hour: number
  try {
    time = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: timezone }).format(now)
    hour = Number(
      new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hourCycle: 'h23', timeZone: timezone }).format(now),
    )
  } catch {
    time = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' }).format(now)
    hour = now.getHours()
  }

  return { time, hour }
}

function Hero({
  firstName,
  timezone,
  today,
}: {
  firstName: string | undefined
  timezone: string | undefined
  today: string
}) {
  const { can, canAny } = useAuth()
  const { time, hour } = useClock(timezone)

  const actions: { to: string; label: string; icon: LucideIcon; allowed: boolean }[] = [
    { to: '/calendar', label: 'Check availability', icon: CalendarDays, allowed: can('calendar.view') },
    { to: '/reservations', label: 'Find a booking', icon: ClipboardList, allowed: can('reservations.view') },
    { to: '/inbox', label: 'Reply to guests', icon: Inbox, allowed: canAny(['messages.view', 'messages.send']) },
    { to: '/operations', label: "Today's tasks", icon: Sparkles, allowed: can('tasks.view') },
  ]

  return (
    <section className="hero">
      <div className="hero__leaves" aria-hidden="true" />
      <div className="hero__text">
        <div className="hero__eyebrow">
          {formatDate(today)} · {timezone}
        </div>
        <h1>
          {greeting(hour)}, {firstName}
        </h1>
        <p className="hero__lede">Here is who is arriving, who is leaving, and what is still owed.</p>

        <nav className="hero__actions" aria-label="Quick links">
          {actions
            .filter((action) => action.allowed)
            .map((action) => (
              <Link key={action.to} to={action.to} className="hero__action">
                <action.icon size={16} aria-hidden />
                {action.label}
                <ArrowRight size={14} className="hero__action-arrow" aria-hidden />
              </Link>
            ))}
        </nav>
      </div>

      <div className="hero__clock" aria-label={`Local time ${time}`}>
        <span className="hero__clock-time">{time}</span>
        <span className="hero__clock-label">local time</span>
      </div>
    </section>
  )
}

function Kpi({
  icon: Icon,
  label,
  value,
  meta,
  series,
}: {
  icon: LucideIcon
  label: string
  value: string | number
  meta?: string
  series?: number[]
}) {
  const peak = Math.max(1, ...(series ?? []))

  return (
    <div className="card tilt">
      <div className="card__body">
        <div className="stat__label">
          <span className="stat__icon" aria-hidden="true">
            <Icon size={16} />
          </span>
          {label}
        </div>
        <div className="row row--between" style={{ alignItems: 'flex-end' }}>
          <div>
            <div className="stat__value">
              <CountUp value={value} />
            </div>
            {meta !== undefined && <div className="stat__meta">{meta}</div>}
          </div>
          {series !== undefined && (
            <div className="sparkbars" aria-hidden="true">
              {series.map((point, index) => (
                <motion.span
                  key={index}
                  initial={{ scaleY: 0 }}
                  animate={{ scaleY: Math.max(0.08, point / peak) }}
                  transition={{ delay: 0.3 + index * 0.04, type: 'spring', stiffness: 160, damping: 16 }}
                />
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
