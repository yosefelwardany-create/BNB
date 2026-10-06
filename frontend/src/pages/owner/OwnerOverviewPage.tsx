import { useQuery } from '@tanstack/react-query'
import { Info } from 'lucide-react'
import { api } from '@/api/client'
import type { OwnerSummary } from '@/api/types'
import { QueryState } from '@/components/QueryState'

/**
 * How the owner's properties have traded, and what they are owed.
 *
 * ## The distinction this screen exists to keep
 *
 * Every revenue figure here is **what the property earned**, not what the owner
 * receives. The management fee and the channel's commission come off it, and
 * only the statement itemises them. An owner reading "$12,400" next to their
 * own name will believe that is their money and be short by thousands when the
 * payout lands.
 *
 * So earnings are labelled as the property's throughout, and what they are
 * actually paid is read from the balance — which comes from the last statement
 * they were sent rather than being recomputed here. Two calculations of the
 * same number eventually disagree, and the statement is the document they are
 * paid against.
 *
 * There is deliberately no estimated net on this screen for the same reason.
 */
export function OwnerOverviewPage() {
  const summary = useQuery({
    queryKey: ['owner-summary'],
    queryFn: () => api.get<{ data: OwnerSummary }>('portal/owner/summary'),
  })

  const data = summary.data?.data

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Overview</h1>
          <div className="page-header__subtitle">
            {data
              ? `${data.period.from} to ${data.period.to}`
              : 'Loading…'}
          </div>
        </div>
      </div>

      <QueryState
        isLoading={summary.isLoading}
        error={summary.error}
        isEmpty={data !== undefined && data.properties.length === 0}
        emptyTitle="No properties yet"
        emptyBody="Once a property is attached to you it appears here, with how it has been trading."
      >
        {data !== undefined && (
          <>
            <section className="card mb-3">
              <header className="card__header">
                <h2>What you are owed</h2>
              </header>

              <div className="card__body stack">
                <div className="row row--wrap gap-3">
                  <Figure
                    label={data.balance.is_in_deficit ? 'Carried forward against you' : 'Balance'}
                    value={data.balance.closing_balance.formatted}
                    currency={data.balance.currency}
                    note={
                      data.balance.as_at === null
                        ? 'No statement has been issued yet'
                        : `From your statement to ${data.balance.as_at}`
                    }
                  />
                  <Figure
                    label="Awaiting payout"
                    value={data.balance.awaiting_payout.formatted}
                    currency={data.balance.currency}
                    note="Approved and not yet paid"
                  />
                </div>

                {data.balance.is_in_deficit && (
                  <p className="small faint">
                    That period ended owing the management company. It is carried forward against
                    your next statement rather than invoiced back to you.
                  </p>
                )}
              </div>
            </section>

            <section className="card mb-3">
              <header className="card__header">
                <h2>How your properties traded</h2>
              </header>

              <div className="card__body stack">
                {/*
                  Said before the numbers rather than in a footnote. Somebody
                  reading quickly should not be able to mistake these for their
                  own earnings.
                */}
                <p className="small faint">
                  <Info size={13} aria-hidden /> These are what the properties earned over the
                  period, in your share. Our management fee and the booking channel&rsquo;s
                  commission come off this — your statement itemises both, and the balance above is
                  what you are actually paid.
                </p>

                <div className="row row--wrap gap-3">
                  <Figure
                    label="The properties earned"
                    value={data.totals.accommodation_revenue.formatted}
                    currency={data.totals.accommodation_revenue.currency}
                    note="Before our fee and channel commission"
                  />
                  <Figure label="Nights sold" value={String(data.totals.nights_sold)} />
                  <Figure
                    label="Occupancy"
                    value={`${Math.round(data.totals.occupancy_rate * 100)}%`}
                  />
                  <Figure
                    label="Average nightly rate"
                    value={data.totals.adr.formatted}
                    currency={data.totals.adr.currency}
                  />
                </div>

                <div className="table-wrap">
                  <table className="data">
                    <thead>
                      <tr>
                        <th>Property</th>
                        <th className="numeric">Your share</th>
                        <th className="numeric">Nights sold</th>
                        <th className="numeric">Occupancy</th>
                        <th className="numeric">It earned</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.properties.map((property) => (
                        <tr key={property.property_id}>
                          <td>{property.property_name ?? '—'}</td>
                          <td className="numeric">{property.ownership_percentage}%</td>
                          <td className="numeric">
                            {property.nights_sold} of {property.nights_available}
                          </td>
                          <td className="numeric">
                            {Math.round(property.occupancy_rate * 100)}%
                          </td>
                          <td className="numeric">{property.accommodation_revenue.formatted}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </section>
          </>
        )}
      </QueryState>
    </>
  )
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
