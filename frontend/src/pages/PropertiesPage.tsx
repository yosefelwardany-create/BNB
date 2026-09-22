import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { Paginated, Property } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { formatMoney } from '@/lib/format'

const STATUS_COLOURS: Record<string, string> = {
  active: 'emerald',
  draft: 'slate',
  inactive: 'amber',
  archived: 'zinc',
}

export function PropertiesPage() {
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  const query = useQuery({
    queryKey: ['properties', { search, page }],
    queryFn: () =>
      api.get<Paginated<Property>>('properties', {
        search: search || undefined,
        page,
        per_page: 25,
      }),
    placeholderData: keepPreviousData,
  })

  const properties = query.data?.data ?? []
  const meta = query.data?.meta

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Properties</h1>
          <div className="page-header__subtitle">
            {meta ? `${meta.total} propert${meta.total === 1 ? 'y' : 'ies'}` : 'Loading…'}
          </div>
        </div>
      </div>

      <div className="filters">
        <div className="field">
          <label className="field__label" htmlFor="property-search">
            Search
          </label>
          <input
            id="property-search"
            type="search"
            placeholder="Name, reference or address"
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
          isEmpty={properties.length === 0}
          emptyTitle="No properties yet"
          emptyBody="Add a property to start taking bookings."
        >
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Property</th>
                  <th>Type</th>
                  <th>Location</th>
                  <th className="numeric">Sleeps</th>
                  <th className="numeric">Base rate</th>
                  <th>Timezone</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {properties.map((property) => (
                  <tr key={property.id}>
                    <td>
                      <div className="strong">{property.name}</div>
                      {property.internal_name !== null && (
                        <div className="small faint">{property.internal_name}</div>
                      )}
                    </td>
                    <td className="small muted">{property.property_type_label}</td>
                    <td className="small">
                      {[property.address.city, property.address.country_code]
                        .filter(Boolean)
                        .join(', ') || '—'}
                    </td>
                    <td className="numeric">{property.capacity.max_occupancy}</td>
                    <td className="numeric">{formatMoney(property.pricing.base_rate)}</td>
                    <td className="small faint">{property.timezone}</td>
                    <td>
                      <Chip
                        label={property.status}
                        colour={STATUS_COLOURS[property.status] ?? 'slate'}
                      />
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
