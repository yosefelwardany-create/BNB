import { useMemo, useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BedDouble, LayoutGrid, MapPin, Pencil, Plus, Rows3, Users } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type { Amenity, Paginated, Property } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { RecordDialog } from '@/components/RecordDialog'
import { useRecordDialog } from '@/lib/useRecordDialog'
import type { FieldSpec, RecordValues } from '@/components/RecordDialog'
import { Segmented } from '@/components/Segmented'
import { formatMoney } from '@/lib/format'
import { useAuth } from '@/lib/auth'

// The API's own list, so the interface cannot offer a type the server refuses.
const PROPERTY_TYPES = [
  'apartment', 'house', 'villa', 'townhouse', 'condominium', 'studio', 'loft',
  'cabin', 'chalet', 'cottage', 'bungalow', 'serviced_apartment', 'aparthotel',
  'boutique_hotel', 'hotel_room', 'guesthouse', 'bed_and_breakfast', 'hostel',
  'resort', 'farmstay', 'boat', 'other',
].map((value) => ({ value, label: value.replace(/_/g, ' ') }))

const BASE_PROPERTY_FIELDS: FieldSpec[] = [
  { name: 'name', label: 'Name', type: 'text', required: true, placeholder: 'Alfama Terrace Apartment' },
  { name: 'property_type', label: 'Type', type: 'select', options: PROPERTY_TYPES, required: true },
  { name: 'address_line_1', label: 'Address', type: 'text' },
  { name: 'city', label: 'City', type: 'text' },
  { name: 'postal_code', label: 'Postcode', type: 'text' },
  { name: 'country_code', label: 'Country', type: 'text', placeholder: 'PT', hint: 'Two letters.' },
  { name: 'timezone', label: 'Timezone', type: 'text', placeholder: 'Europe/Lisbon' },
  { name: 'max_occupancy', label: 'Sleeps', type: 'number' },
  { name: 'bedrooms', label: 'Bedrooms', type: 'number' },
  { name: 'bathrooms', label: 'Bathrooms', type: 'number' },
  {
    name: 'base_rate',
    label: 'Base rate per night',
    type: 'money',
    hint: 'A property needs a rate above zero before it can be activated.',
  },
  { name: 'check_in_time', label: 'Check-in from', type: 'time' },
  { name: 'check_out_time', label: 'Check-out by', type: 'time' },
  { name: 'minimum_nights', label: 'Minimum nights', type: 'number', hint: 'Set 28 where a licence or a local rule requires long lets.' },
  { name: 'maximum_nights', label: 'Maximum nights', type: 'number' },
  { name: 'cleaning_fee', label: 'Cleaning fee', type: 'money' },
  { name: 'security_deposit', label: 'Security deposit', type: 'money' },

  { name: 'summary', label: 'Summary', type: 'textarea', rows: 2, hint: 'One or two lines, as a guest would see them first.' },
  { name: 'description', label: 'Description', type: 'textarea', rows: 5 },
  { name: 'space_description', label: 'The space', type: 'textarea' },
  { name: 'neighbourhood_description', label: 'The neighbourhood', type: 'textarea' },
  { name: 'transit_description', label: 'Getting around', type: 'textarea' },
  { name: 'house_rules', label: 'House rules', type: 'textarea' },

  { name: 'check_in_method', label: 'How guests get in', type: 'text', placeholder: 'lockbox' },
  { name: 'check_in_instructions', label: 'Arrival instructions', type: 'textarea' },

  /*
   * Encrypted at rest and never included in a list response. They are here
   * because the guest agent's entitlement rules are built around them: these
   * are the facts it withholds from a guest who has not paid, and it cannot
   * withhold what nobody has recorded.
   */
  { name: 'wifi_network', label: 'Wi-Fi network', type: 'text' },
  { name: 'wifi_password', label: 'Wi-Fi password', type: 'text' },
  { name: 'door_code', label: 'Door code', type: 'text' },
  { name: 'access_notes', label: 'Access notes', type: 'textarea' },
]

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
  const { can } = useAuth()
  const queryClient = useQueryClient()

  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [view, setView] = useState<View>(readView)

  const dialog = useRecordDialog<Property>()

  const amenities = useQuery({
    queryKey: ['amenities'],
    queryFn: () => api.get<{ data: Amenity[] }>('amenities', { per_page: 200 }),
    staleTime: 10 * 60 * 1000,
  })

  const fields: FieldSpec[] = useMemo(
    () => [
      ...BASE_PROPERTY_FIELDS,
      {
        name: 'amenity_ids',
        label: 'Amenities',
        type: 'multiselect',
        options: (amenities.data?.data ?? []).map((amenity) => ({
          value: amenity.id,
          label: amenity.name,
        })),
      },
    ],
    [amenities.data],
  )

  /*
   * A list row carries no description, amenities or arrival details — the API
   * leaves them out of a collection on purpose. Editing from the row alone would
   * show every one of those fields empty, which reads as "this property has no
   * description" rather than "this response does not carry it".
   */
  const editing = useQuery({
    queryKey: ['property', dialog.editing?.id],
    queryFn: () => api.get<{ data: Property }>(`properties/${dialog.editing?.id ?? ''}`),
    enabled: dialog.editing !== null,
  })

  const record = dialog.editing === null ? null : (editing.data?.data ?? null)

  const save = useMutation({
    mutationFn: (values: RecordValues) =>
      dialog.editing === null
        ? api.post('properties', values)
        : api.patch(`properties/${dialog.editing.id}`, values),
    onSuccess: () => {
      dialog.close()
      void queryClient.invalidateQueries({ queryKey: ['properties'] })
    },
  })

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

        {can('properties.create') && (
          <button type="button" className="btn btn--primary" onClick={dialog.create}>
            <Plus size={16} aria-hidden /> New property
          </button>
        )}
      </div>

      {/* Held back until the full record has arrived, so the form is never
          seeded from a half-populated row. */}
      {dialog.isOpen && (dialog.editing === null || record !== null) && (
        <RecordDialog
          title={dialog.editing === null ? 'New property' : `Edit ${dialog.editing.name}`}
          description={
            dialog.editing === null
              ? 'Only a name and a type are required. Everything else can be filled in later, and the property stays in draft until it is complete enough to activate.'
              : undefined
          }
          fields={fields}
          initial={record === null ? undefined : toValues(record)}
          submitLabel={dialog.editing === null ? 'Create property' : 'Save changes'}
          pending={save.isPending}
          error={save.error}
          onSubmit={(values) => save.mutate(values)}
          onClose={dialog.close}
        >
          {record !== null && <PhotoUploader property={record} />}
        </RecordDialog>
      )}

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
                <PropertyCard
                  key={property.id}
                  property={property}
                  onEdit={can('properties.update') ? () => dialog.edit(property) : undefined}
                />
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
                    <th />
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
                      <td>
                        {can('properties.update') && (
                          <button
                            type="button"
                            className="btn btn--sm btn--ghost"
                            onClick={() => dialog.edit(property)}
                          >
                            <Pencil size={14} aria-hidden /> Edit
                          </button>
                        )}
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
function PropertyCard({ property, onEdit }: { property: Property; onEdit?: () => void }) {
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
        <div className="property-card__price row row--between">
          <span>
            <span className="property-card__rate">{formatMoney(property.pricing.base_rate)}</span>
            <span className="small faint"> base rate · {property.timezone}</span>
          </span>
          {onEdit !== undefined && (
            <button type="button" className="btn btn--sm btn--ghost" onClick={onEdit}>
              <Pencil size={14} aria-hidden /> Edit
            </button>
          )}
        </div>
      </div>
    </article>
  )
}

/**
 * A property as the form sees it.
 *
 * The API nests for reading — `address.city`, `pricing.base_rate` — and takes a
 * flat body for writing. Flattening here keeps that asymmetry in one place
 * instead of in every field's initial value.
 */
function toValues(property: Property): RecordValues {
  return {
    name: property.name,
    property_type: property.property_type,
    address_line_1: property.address.line_1 ?? '',
    city: property.address.city ?? '',
    postal_code: property.address.postal_code ?? '',
    country_code: property.address.country_code ?? '',
    timezone: property.timezone,
    max_occupancy: property.capacity.max_occupancy,
    bedrooms: property.capacity.bedrooms,
    bathrooms: property.capacity.bathrooms,
    base_rate: property.pricing.base_rate.amount,
    check_in_time: property.arrival?.check_in_time ?? '',
    check_out_time: property.arrival?.check_out_time ?? '',
    house_rules: property.content?.house_rules ?? '',
    summary: property.content?.summary ?? '',
    description: property.content?.description ?? '',
    minimum_nights: property.pricing.minimum_nights,
    maximum_nights: property.pricing.maximum_nights ?? '',
    cleaning_fee: property.pricing.cleaning_fee.amount,
    check_in_method: property.arrival?.check_in_method ?? '',
    check_in_instructions: property.content?.check_in_instructions ?? '',
    // Seeded from what the property already has, so saving without touching
    // them does not strip every amenity off the record.
    amenity_ids: (property.amenities ?? []).map((amenity) => amenity.id),
  }
}

/**
 * Photographs for a property.
 *
 * Only offered while editing, because the upload posts to a property that has to
 * exist first. A listing cannot be published without at least one photograph —
 * that rule is enforced by the server and is not relaxed here, so this is the
 * screen where a property stops being a draft.
 */
function PhotoUploader({ property }: { property: Property }) {
  const queryClient = useQueryClient()
  const [pending, setPending] = useState<File[]>([])

  const photos = useQuery({
    queryKey: ['property-photos', property.id],
    queryFn: () => api.get<{ data: { id: string; url: string; caption: string | null }[] }>(
      `properties/${property.id}/photos`,
    ),
  })

  const upload = useMutation({
    mutationFn: (files: File[]) => {
      const form = new FormData()

      for (const file of files) {
        form.append('photos[]', file)
      }

      return api.post(`properties/${property.id}/photos`, form)
    },
    onSuccess: () => {
      setPending([])
      void queryClient.invalidateQueries({ queryKey: ['property-photos', property.id] })
      void queryClient.invalidateQueries({ queryKey: ['properties'] })
    },
  })

  const existing = photos.data?.data ?? []

  return (
    <div className="field">
      <span className="field__label">Photos</span>

      {existing.length > 0 && (
        <div className="photo-strip">
          {existing.map((photo) => (
            <img key={photo.id} src={photo.url} alt={photo.caption ?? ''} loading="lazy" />
          ))}
        </div>
      )}

      <input
        type="file"
        accept="image/*"
        multiple
        onChange={(event) => setPending(Array.from(event.target.files ?? []))}
      />

      {upload.error !== null && (
        <p className="field__error small" role="alert">
          {upload.error instanceof ApiError ? upload.error.message : 'Those files could not be uploaded.'}
        </p>
      )}

      {pending.length > 0 && (
        <button
          type="button"
          className="btn btn--sm mt-1"
          onClick={() => upload.mutate(pending)}
          disabled={upload.isPending}
        >
          {upload.isPending ? 'Uploading…' : `Upload ${pending.length} photo(s)`}
        </button>
      )}

      <p className="field__hint small faint">
        A listing cannot be published without at least one photograph.
      </p>
    </div>
  )
}
