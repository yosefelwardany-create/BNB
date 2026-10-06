import { useQuery } from '@tanstack/react-query'
import { ShieldCheck } from 'lucide-react'
import { api } from '@/api/client'
import type { OwnerSummary, OwnerUpcomingStay } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'

interface UpcomingResponse {
  data: OwnerUpcomingStay[]
  meta?: { notice?: string }
}

/**
 * Who is staying, and when.
 *
 * A guest **count** and no name, because the server sends none. That is not an
 * oversight to work around: an owner is a client of the management company, not
 * a party to the guest's booking, and the guest gave their details to the
 * manager. The server's own notice saying so is surfaced rather than swallowed —
 * an absence nobody explains reads as a bug, and somebody eventually "fixes" it.
 */
export function OwnerStaysPage() {
  const stays = useQuery({
    queryKey: ['owner-upcoming'],
    queryFn: () => api.get<UpcomingResponse>('portal/owner/upcoming'),
  })

  // Property names are not on a stay — only an id — so they come from the
  // summary, which the overview has usually already cached.
  const summary = useQuery({
    queryKey: ['owner-summary'],
    queryFn: () => api.get<{ data: OwnerSummary }>('portal/owner/summary'),
  })

  const rows = stays.data?.data ?? []
  const names = new Map(
    (summary.data?.data.properties ?? []).map((p) => [p.property_id, p.property_name]),
  )

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Stays</h1>
          <div className="page-header__subtitle">Bookings at your properties over the next 90 days</div>
        </div>
      </div>

      <section className="card mb-3">
        <QueryState
          isLoading={stays.isLoading}
          error={stays.error}
          isEmpty={rows.length === 0}
          emptyTitle="Nothing booked yet"
          emptyBody="Bookings at your properties appear here as they come in."
        >
          <div className="card__body stack">
            <p className="small faint">
              <ShieldCheck size={13} aria-hidden />{' '}
              {stays.data?.meta?.notice ??
                'Guest names and contact details are not shown. Guests give those to the management company, not to the owner.'}
            </p>

            <div className="table-wrap">
              <table className="data">
                <thead>
                  <tr>
                    <th>Property</th>
                    <th>Arrives</th>
                    <th>Leaves</th>
                    <th className="numeric">Nights</th>
                    <th className="numeric">Guests</th>
                    <th>Booked through</th>
                    <th />
                    <th className="numeric">It earns</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((stay) => (
                    <tr key={stay.id}>
                      <td>{names.get(stay.property_id) ?? '—'}</td>
                      <td>{stay.check_in_date ?? '—'}</td>
                      <td>{stay.check_out_date ?? '—'}</td>
                      <td className="numeric">{stay.nights}</td>
                      <td className="numeric">{stay.guests}</td>
                      <td>{stay.source ?? '—'}</td>
                      <td>
                        <Chip
                          label={stay.status}
                          colour={stay.status === 'confirmed' ? 'emerald' : 'slate'}
                        />
                      </td>
                      {/* What the stay earns, not what the guest paid and not
                          what the owner receives. */}
                      <td className="numeric">{stay.accommodation_total.formatted}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </QueryState>
      </section>
    </>
  )
}
