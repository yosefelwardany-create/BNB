import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { BedDouble, LayoutGrid, MapPin, Rows3, Users } from 'lucide-react'
import { api } from '@/api/client'
import type { Paginated, Property } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { Segmented } from '@/components/Segmented'
import { formatMoney } from '@/lib/format'

type View = 'cards' | 'table'

const VIEW_KEY = 'habitat.properties.view'

function readView(): View {
  try {
    return localStorage.getItem(VIEW_KEY) === 'table' ? 'table' : 'cards'
  } catch {
    return 'cards'
  }
}

const STATUS_COLOURS: Record<string, string> = {
  active: 'emerald',
  draft: 'slate',
  inactive: 'amber',
  archived: 'zinc',
}

export function PropertiesPage() {
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [view, setView] = useState<View>(readView)

  function changeView(next: View) {
    setView(next)
    try {
      localStorage.setItem(VIEW_KEY, next)
    } catch {
      // Remembered for this visit only.
    }
  }

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
        <div className="filters__end">
          <Segmented
            label="Layout"
            value={view}
            onChange={changeView}
            options={[
              { value: 'cards', label: 'Cards', icon: LayoutGrid },
              { value: 'table', label: 'Table', icon: Rows3 },
            ]}
          />
        </div>
      </div>

      <div className={view === 'cards' ? 'card card--bare' : 'card'}>
        <QueryState
          isLoading={query.isLoading}
          error={query.error}
          isEmpty={properties.length === 0}
          emptyTitle="No properties yet"
          emptyBody="Add a property to start taking bookings."
        >
          {view === 'cards' ? (
            <div className="property-grid stagger">
              {properties.map((property) => (
                <PropertyCard key={property.id} property={property} />
              ))}
            </div>
          ) : (
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
          )}
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

/** A property as a card: a cover drawn from its name, and the essentials. */
function PropertyCard({ property }: { property: Property }) {
  const location = [property.address.city, property.address.country_code].filter(Boolean).join(', ')
  // A stable gradient angle per property, so covers are told apart at a glance.
  const seed = [...property.id].reduce((sum, char) => sum + char.charCodeAt(0), 0)

  return (
    <article className="card property-card tilt">
      <div
        className="property-card__cover"
        style={{ '--seed': `${(seed % 9) * 20}deg` } as React.CSSProperties}
      >
        <span className="property-card__initial" aria-hidden="true">
          {property.name.slice(0, 1).toUpperCase()}
        </span>
        <Chip label={property.status} colour={STATUS_COLOURS[property.status] ?? 'slate'} />
      </div>
      <div className="card__body">
        <div className="small faint">{property.property_type_label}</div>
        <h3 className="property-card__name">{property.name}</h3>
        {property.internal_name !== null && <div className="small faint">{property.internal_name}</div>}
        <div className="property-card__facts small muted">
          <span>
            <MapPin size={14} aria-hidden /> {location || '—'}
          </span>
          <span>
            <Users size={14} aria-hidden /> Sleeps {property.capacity.max_occupancy}
          </span>
          <span>
            <BedDouble size={14} aria-hidden /> {property.capacity.bedrooms} bedroom
            {property.capacity.bedrooms === 1 ? '' : 's'}
          </span>
        </div>
        <div className="property-card__price">
          <span className="property-card__rate">{formatMoney(property.pricing.base_rate)}</span>
          <span className="small faint"> base rate · {property.timezone}</span>
        </div>
      </div>
    </article>
  )
}
