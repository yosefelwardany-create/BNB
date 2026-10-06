import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Info, TriangleAlert } from 'lucide-react'
import { api } from '@/api/client'
import type { ClientFinancials, OwnerSummary } from '@/api/types'
import { QueryState } from '@/components/QueryState'
import { formatDate } from '@/lib/format'

/**
 * How the client's properties have traded, and what is left after the
 * management commission.
 *
 * ## The distinction this screen exists to keep
 *
 * Three figures, always in this order and always labelled: what the
 * properties **earned**, the **management commission** taken from it, and the
 * **revenue after commission**. The last one is a revenue figure. It is not
 * money received, not a payout and not a balance, and the explanation under
 * the figures says so in the server's own words — repeated here rather than
 * paraphrased so the two can never disagree.
 *
 * Per currency, never summed across them. A client with a CAD property and a
 * USD property gets two headline rows, because adding them would be a number
 * that means nothing.
 *
 * Figures carrying a flag are shown, not hidden, and marked as not final:
 * a night with no rate, a refund that is still being resolved, a stay before
 * the agreement started. Hiding them would be a smaller number presented as
 * the truth.
 */
export function OwnerOverviewPage() {
  const financials = useQuery({
    queryKey: ['client-financials', 'year-to-date'],
    queryFn: () => api.get<{ data: ClientFinancials }>('portal/owner/financials'),
  })

  const summary = useQuery({
    queryKey: ['owner-summary'],
    queryFn: () => api.get<{ data: OwnerSummary }>('portal/owner/summary'),
  })

  const money = financials.data?.data
  const trading = summary.data?.data

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Overview</h1>
          <div className="page-header__subtitle">
            {money ? `${formatDate(money.period.from)} to ${formatDate(money.period.to)}` : 'Loading…'}
          </div>
        </div>
      </div>

      <QueryState
        isLoading={financials.isLoading}
        error={financials.error}
        isEmpty={money !== undefined && money.properties.length === 0}
        emptyTitle="No properties yet"
        emptyBody="Once a property is attached to your account it appears here, with how it has been trading."
      >
        {money !== undefined && (
          <>
            {money.has_incomplete_data && (
              <div className="notice notice--warning" role="status">
                <TriangleAlert size={14} aria-hidden /> Some figures below are not final. The rows
                carrying a note say why; the Money screen lists each one.
              </div>
            )}

            {money.totals_by_currency.map((total) => (
              <section className="card mb-3" key={total.currency}>
                <header className="card__header">
                  <h2>Your revenue in {total.currency}</h2>
                  <span className="small faint">
                    {total.properties} propert{total.properties === 1 ? 'y' : 'ies'} · {total.nights_sold}{' '}
                    nights sold
                  </span>
                </header>

                <div className="card__body stack">
                  <div className="row row--wrap gap-3">
                    <Figure
                      label="The properties earned"
                      value={total.revenue_before_commission.formatted}
                      currency={total.currency}
                      note="Before the management commission"
                    />
                    <Figure
                      label={`Management commission${money.commission.rate !== null ? ` (${money.commission.rate}%)` : ''}`}
                      value={total.commission.formatted}
                      currency={total.currency}
                      note="Deducted by the management company"
                    />
                    <Figure
                      label="Revenue after commission"
                      value={total.revenue_after_commission.formatted}
                      currency={total.currency}
                      note={total.is_final ? 'A revenue figure, not a payment' : 'Not final — see Money'}
                    />
                  </div>

                  {/* The server's own wording, so the screen and the API can
                      never say two different things about what this number is. */}
                  <p className="small faint">
                    <Info size={13} aria-hidden /> {money.explanation.revenue_after_commission}
                  </p>
                </div>
              </section>
            ))}

            <section className="card mb-3">
              <header className="card__header">
                <h2>By property</h2>
                <Link to="/money" className="btn btn--ghost btn--sm">
                  Full breakdown
                </Link>
              </header>

              <div className="table-wrap">
                <table className="data">
                  <thead>
                    <tr>
                      <th>Property</th>
                      <th className="numeric">Your share</th>
                      <th className="numeric">Nights sold</th>
                      <th className="numeric">Occupancy</th>
                      <th className="numeric">It earned</th>
                      <th className="numeric">Commission</th>
                      <th className="numeric">After commission</th>
                    </tr>
                  </thead>
                  <tbody>
                    {money.properties.map((row) => (
                      <tr key={`${row.property_id}-${row.currency}`}>
                        <td>
                          <div className="strong">{row.property_name ?? '—'}</div>
                          {!row.is_final && (
                            <div className="small faint">
                              <TriangleAlert size={12} aria-hidden /> Not final
                            </div>
                          )}
                        </td>
                        <td className="numeric">{row.ownership_percentage}%</td>
                        <td className="numeric">
                          {row.nights_sold} of {row.nights_available}
                        </td>
                        <td className="numeric">{occupancy(row.nights_sold, row.nights_available)}</td>
                        <td className="numeric">
                          {row.revenue_before_commission.formatted}{' '}
                          <span className="small faint">{row.currency}</span>
                        </td>
                        <td className="numeric">{row.commission.formatted}</td>
                        <td className="numeric strong">{row.revenue_after_commission.formatted}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          </>
        )}
      </QueryState>

      {trading !== undefined && trading.properties.length > 0 && (
        <section className="card mb-3">
          <header className="card__header">
            <h2>Trading</h2>
          </header>
          <div className="card__body">
            <div className="row row--wrap gap-3">
              <Figure label="Nights sold" value={String(trading.totals.nights_sold)} />
              {/* The server sends a percentage already (75.3 means 75.3%). */}
              <Figure label="Occupancy" value={`${Math.round(trading.totals.occupancy_rate)}%`} />
              <Figure
                label="Average nightly rate"
                value={trading.totals.adr.formatted}
                currency={trading.totals.adr.currency}
              />
            </div>
          </div>
        </section>
      )}
    </>
  )
}

function occupancy(sold: number, available: number): string {
  if (available <= 0) return '—'

  return `${Math.round((sold / available) * 100)}%`
}

function Figure({
  label,
  value,
  currency,
  note,
}: {
  label: string
  value: string
  currency?: string
  note?: string
}) {
  return (
    <div className="stack stack--tight">
      <span className="small faint">{label}</span>
      <span className="strong" style={{ fontSize: '1.35rem', fontVariantNumeric: 'tabular-nums' }}>
        {value}
        {currency !== undefined && <span className="small faint"> {currency}</span>}
      </span>
      {note !== undefined && <span className="small faint">{note}</span>}
    </div>
  )
}
