import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, BedDouble, Bath, ExternalLink, Users } from 'lucide-react'
import { api } from '@/api/client'
import type { ClientProperty } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'

const STATUS_COLOURS: Record<string, string> = {
  active: 'emerald',
  draft: 'zinc',
  inactive: 'slate',
  archived: 'zinc',
}

/**
 * The client's properties, and the details of one.
 *
 * Everything here comes from the portal's property endpoints, which the server
 * shapes for a client: name, address, photos, capacity, nightly rate, where it
 * is listed. Nothing about how the connection to the channel is configured,
 * nothing about the agent running it, no access codes and no internal notes —
 * by construction on the server, not by omission here.
 *
 * Read-only. There is no edit control because there is nothing a client may
 * change: the management company operates the property.
 */
export function OwnerPropertiesPage() {
  const { propertyId } = useParams()

  return propertyId === undefined ? <PropertyList /> : <PropertyDetail id={propertyId} />
}

function PropertyList() {
  const properties = useQuery({
    queryKey: ['client-properties'],
    queryFn: () => api.get<{ data: ClientProperty[] }>('portal/owner/properties'),
  })

  const rows = properties.data?.data ?? []

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Properties</h1>
          <div className="page-header__subtitle">
            {properties.data ? `${rows.length} propert${rows.length === 1 ? 'y' : 'ies'} managed for you` : 'Loading…'}
          </div>
        </div>
      </div>

      <QueryState
        isLoading={properties.isLoading}
        error={properties.error}
        isEmpty={properties.data !== undefined && rows.length === 0}
        emptyTitle="No properties yet"
        emptyBody="Once the management company attaches a property to your account it appears here."
      >
        <div className="property-grid">
          {rows.map((property) => (
            <Link key={property.id} to={`/properties/${property.id}`} className="card property-card">
              <div className="property-card__cover">
                {cover(property) !== null ? (
                  <img
                    src={cover(property) ?? undefined}
                    alt=""
                    loading="lazy"
                    referrerPolicy="no-referrer"
                    style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover' }}
                  />
                ) : (
                  <span className="property-card__initial" aria-hidden="true">
                    {property.display_name.slice(0, 1).toUpperCase()}
                  </span>
                )}
                <Chip label={property.status} colour={STATUS_COLOURS[property.status] ?? 'slate'} />
              </div>
              <div className="card__body">
                <h3 className="property-card__name">{property.display_name}</h3>
                <div className="small faint">{address(property)}</div>
                <div className="property-card__facts small muted">
                  <span>
                    <BedDouble size={14} aria-hidden /> {property.capacity.bedrooms} bedroom
                    {property.capacity.bedrooms === 1 ? '' : 's'} · <Bath size={14} aria-hidden />{' '}
                    {property.capacity.bathrooms} · <Users size={14} aria-hidden /> sleeps{' '}
                    {property.capacity.max_occupancy}
                  </span>
                </div>
                <div className="small">
                  <span className="property-card__rate">{property.pricing.base_rate.formatted}</span>
                  <span className="faint"> {property.currency} per night</span>
                </div>
              </div>
            </Link>
          ))}
        </div>
      </QueryState>
    </>
  )
}

function PropertyDetail({ id }: { id: string }) {
  const property = useQuery({
    queryKey: ['client-property', id],
    queryFn: () => api.get<{ data: ClientProperty }>(`portal/owner/properties/${id}`),
  })

  const data = property.data?.data

  return (
    <>
      <div className="page-header">
        <div>
          <Link to="/properties" className="small faint">
            <ArrowLeft size={13} aria-hidden /> All properties
          </Link>
          <h1>{data?.display_name ?? 'Property'}</h1>
          <div className="page-header__subtitle">{data ? address(data) : 'Loading…'}</div>
        </div>
        {data !== undefined && (
          <Chip label={data.status} colour={STATUS_COLOURS[data.status] ?? 'slate'} />
        )}
      </div>

      <QueryState isLoading={property.isLoading} error={property.error}>
        {data !== undefined && (
          <>
            {(data.photos?.length ?? 0) > 0 && (
              <section className="card mb-3">
                <div className="card__body">
                  <div className="photo-strip">
                    {(data.photos ?? []).map((photo) =>
                      photo.url === null ? null : (
                        <img key={photo.id} src={photo.url} alt={photo.caption ?? ''} loading="lazy" />
                      ),
                    )}
                  </div>
                </div>
              </section>
            )}

            <div className="split">
              <section className="card">
                <header className="card__header">
                  <h2>Details</h2>
                </header>
                <div className="card__body">
                  <dl className="definition">
                    <dt>Type</dt>
                    <dd>{data.property_type_label}</dd>
                    <dt>Sleeps</dt>
                    <dd>
                      {data.capacity.max_occupancy} guests · {data.capacity.bedrooms} bedroom
                      {data.capacity.bedrooms === 1 ? '' : 's'} · {data.capacity.beds} bed
                      {data.capacity.beds === 1 ? '' : 's'} · {data.capacity.bathrooms} bathroom
                      {data.capacity.bathrooms === 1 ? '' : 's'}
                    </dd>
                    <dt>Nightly rate</dt>
                    <dd>
                      {data.pricing.base_rate.formatted} {data.currency}
                      {data.pricing.minimum_nights > 1 && ` · minimum ${data.pricing.minimum_nights} nights`}
                    </dd>
                    <dt>Cleaning fee</dt>
                    <dd>
                      {data.pricing.cleaning_fee.formatted} {data.currency}
                    </dd>
                    <dt>Check-in / out</dt>
                    <dd>
                      {data.arrival.check_in_time ?? '—'} / {data.arrival.check_out_time ?? '—'}
                    </dd>
                    <dt>Timezone</dt>
                    <dd>{data.timezone}</dd>
                    <dt>Listing</dt>
                    <dd>
                      {data.listing.channel_url !== null ? (
                        <a href={data.listing.channel_url} target="_blank" rel="noreferrer">
                          View on the channel <ExternalLink size={12} aria-hidden />
                        </a>
                      ) : (
                        'Not listed on a channel yet'
                      )}
                      {data.listing.channel_status !== null && (
                        <span className="ml-2">
                          <Chip label={data.listing.channel_status} colour="slate" />
                        </span>
                      )}
                    </dd>
                  </dl>
                </div>
              </section>

              <section className="card">
                <header className="card__header">
                  <h2>About</h2>
                </header>
                <div className="card__body stack">
                  {data.content.summary !== null && <p className="strong">{data.content.summary}</p>}
                  {data.content.description !== null ? (
                    <p style={{ whiteSpace: 'pre-wrap' }}>{data.content.description}</p>
                  ) : (
                    <p className="small faint">No description has been written yet.</p>
                  )}
                  {data.content.house_rules !== null && (
                    <>
                      <h3>House rules</h3>
                      <p style={{ whiteSpace: 'pre-wrap' }}>{data.content.house_rules}</p>
                    </>
                  )}
                  {(data.amenities?.length ?? 0) > 0 && (
                    <>
                      <h3>Amenities</h3>
                      <div className="row row--wrap gap-2">
                        {(data.amenities ?? []).map((amenity) => (
                          <Chip key={amenity.id} label={amenity.name} colour="slate" />
                        ))}
                      </div>
                    </>
                  )}
                </div>
              </section>
            </div>
          </>
        )}
      </QueryState>
    </>
  )
}

function cover(property: ClientProperty): string | null {
  const photos = property.photos ?? []

  return (photos.find((photo) => photo.is_cover) ?? photos[0])?.url ?? null
}

function address(property: ClientProperty): string {
  return [property.address.line_1, property.address.city, property.address.country_code]
    .filter((part): part is string => typeof part === 'string' && part !== '')
    .join(', ')
}
