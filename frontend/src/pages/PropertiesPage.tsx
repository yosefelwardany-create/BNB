import { HostexPropertyDetails } from '@/components/HostexDetails'
import { useMemo, useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createPortal } from 'react-dom'
import { Link } from 'react-router-dom'
import { AgentChatDrawer } from '@/components/AgentChatDrawer'
import { AgentAvatar } from '@/components/AgentAvatar'
import {
  BedDouble,
  BookOpen,
  LayoutGrid,
  LifeBuoy,
  MapPin,
  Pencil,
  Plus,
  Rows3,
  Trash2,
  Users,
} from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type { Amenity, Listing, Paginated, Property } from '@/api/types'
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
  // Address, occupancy and rate are what the server checks before a property may
  // go on sale, so the form says so rather than leaving it to be discovered when
  // activation is refused.
  { name: 'address_line_1', label: 'Address', type: 'text', hint: 'Needed before the property can go on sale.' },
  { name: 'city', label: 'City', type: 'text' },
  { name: 'postal_code', label: 'Postcode', type: 'text' },
  { name: 'country_code', label: 'Country', type: 'text', placeholder: 'PT', hint: 'Two letters.' },
  { name: 'timezone', label: 'Timezone', type: 'text', placeholder: 'Europe/Lisbon' },
  { name: 'max_occupancy', label: 'Sleeps', type: 'number', hint: 'A booking for more guests than this is refused.' },
  { name: 'bedrooms', label: 'Bedrooms', type: 'number' },
  { name: 'bathrooms', label: 'Bathrooms', type: 'number' },
  { name: 'beds', label: 'Beds', type: 'number' },
  {
    name: 'base_rate',
    label: 'Base rate per night',
    type: 'money',
    hint: 'A property needs a rate above zero before it can be activated.',
  },
  { name: 'check_in_time', label: 'Check-in from', type: 'time' },
  { name: 'check_in_until', label: 'Check-in until', type: 'time' },
  { name: 'check_out_time', label: 'Check-out by', type: 'time' },
  { name: 'minimum_nights', label: 'Minimum nights', type: 'number', hint: 'Set 28 where a licence or a local rule requires long lets.' },
  { name: 'maximum_nights', label: 'Maximum nights', type: 'number' },
  { name: 'cleaning_fee', label: 'Cleaning fee', type: 'money' },
  { name: 'security_deposit', label: 'Security deposit', type: 'money' },
  { name: 'extra_guest_fee', label: 'Extra guest fee per night', type: 'money' },
  { name: 'extra_guest_after', label: 'Guests included in base rate', type: 'number' },
  { name: 'instant_book', label: 'Instant booking', type: 'checkbox' },

  { name: 'summary', label: 'Summary', type: 'textarea', rows: 2, hint: 'One or two lines, as a guest would see them first.' },
  { name: 'description', label: 'Description', type: 'textarea', rows: 5 },
  { name: 'space_description', label: 'The space', type: 'textarea' },
  { name: 'neighbourhood_description', label: 'The neighbourhood', type: 'textarea' },
  { name: 'transit_description', label: 'Getting around', type: 'textarea' },
  { name: 'house_rules', label: 'House rules', type: 'textarea' },

  { name: 'check_in_method', label: 'How guests get in', type: 'text', placeholder: 'lockbox' },
  { name: 'check_in_instructions', label: 'Arrival instructions', type: 'textarea' },
  { name: 'check_out_instructions', label: 'Departure instructions', type: 'textarea' },
]

/**
 * The arrival secrets, kept apart from the rest of the form.
 *
 * Only offered when creating, or when editing a property whose response carried
 * them — which is to say, to somebody whose role may see them. A colleague
 * without that permission gets no `access` key at all, and showing them four
 * empty boxes would invite them to overwrite a door code they cannot read.
 *
 * Encrypted at rest. They are here because the guest agent's entitlement rules
 * are built around them: these are the facts it withholds from a guest who has
 * not paid, and it cannot withhold what nobody has recorded.
 */
const ACCESS_FIELDS: FieldSpec[] = [
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

  const fields: FieldSpec[] = useMemo(
    () => [
      ...BASE_PROPERTY_FIELDS.map((field) => field.type === 'money' && record?.hostex
        ? { ...field, label: `${field.label} (${record.currency})` }
        : field),
      // Offered when creating, and when editing a property whose response
      // carried them. Absent means the signed-in person's role may not see
      // credentials, and four empty boxes would invite them to overwrite a door
      // code they cannot read.
      ...(record === null || record.access !== undefined ? ACCESS_FIELDS : []),
      {
        name: 'amenity_ids',
        label: 'Amenities',
        type: 'multiselect',
        hint: record?.hostex && record.hostex.amenities_status !== 'imported'
          ? 'Hostex has not supplied a complete, readable amenities list. Unchecked items are unknown, not confirmed absent. You can select verified amenities here; your edits are preserved.'
          : undefined,
        options: (amenities.data?.data ?? []).map((amenity) => ({
          value: amenity.id,
          label: amenity.name,
        })),
      },
    ],
    [amenities.data, record],
  )

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
              ? 'Only a name and a type are required. Everything else can be filled in later. It is created with a listing of its own, so it appears in the booking form straight away, and stays a draft until you activate it — reopen it to see what activation still needs.'
              : record?.hostex?.missing_fields?.length
                ? `Imported details fill these fields automatically. These fields could not yet be imported from Hostex: ${record.hostex.missing_fields.map((field) => field.replaceAll('_', ' ')).join(', ')}. Review those values before publishing.`
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
          {record !== null && (
            <>
              <PhotoUploader property={record} />
              <HostexPropertyDetails property={record} />
              <ReadinessPanel property={record} />
              <ListingsPanel property={record} />
            </>
          )}
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

function PropertyImage({ url, caption }: { url: string | null; caption: string | null }) {
  const [broken, setBroken] = useState(false)
  return broken || !url ? <span className="small muted">Photo unavailable. Pull again to refresh the source URL.</span>
    : <img src={url} alt={caption ?? 'Property photo'} loading="lazy" referrerPolicy="no-referrer" onError={() => setBroken(true)} />
}

/** A property as a card: a cover drawn from its name, and the essentials. */
function PropertyCard({ property, onEdit }: { property: Property; onEdit?: () => void }) {
  const [brokenCoverUrls, setBrokenCoverUrls] = useState<string[]>([])
  const photos = property.photos?.filter((photo) => photo.url && !brokenCoverUrls.includes(photo.url)) ?? []
  const cover = photos.find((photo) => photo.is_cover) ?? photos[0]
  const location = [property.address.city, property.address.country_code].filter(Boolean).join(', ') || property.address.line_1
  // A stable gradient angle per property, so covers are told apart at a glance.
  const seed = [...property.id].reduce((sum, char) => sum + char.charCodeAt(0), 0)

  return (
    <article className="card property-card tilt">
      <div
        className="property-card__cover"
        style={{ '--seed': `${(seed % 9) * 20}deg` } as React.CSSProperties}
      >
        {cover?.url && <img src={cover.url} alt={cover.caption ?? property.name} loading="lazy" referrerPolicy="no-referrer" onError={() => setBrokenCoverUrls((urls) => cover.url ? [...urls, cover.url] : urls)} style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover' }} />}
        {!cover?.url && <span className="property-card__initial" aria-hidden="true">
          {property.name.slice(0, 1).toUpperCase()}
        </span>}
        <Chip label={property.status} colour={STATUS_COLOURS[property.status] ?? 'slate'} />
      </div>
      <div className="card__body">
        <div className="small faint">{property.property_type_label}</div>
        <h3 className="property-card__name">{property.name}</h3>

        <PropertyAgent property={property} />
        <PropertyHelpers property={property} />
        {property.hostex && <span className="small muted">Connected to Hostex</span>}
        {!cover?.url && brokenCoverUrls.length > 0 && <p className="small muted">Photos could not be loaded. Refresh the imported photos or upload a replacement.</p>}

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
            <span className="small faint"> base rate · {property.hostex?.missing_fields?.includes('timezone') ? 'Timezone needs review' : property.timezone}</span>
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
 * Who manages this property, and the way in to them.
 *
 * The card leads with the agent because that is how the people running these
 * flats think about them — Alex on the third floor, David in the annexe — and
 * the name is faster to find than the address. A picture where there is one,
 * the initial of the bot's name where there is not, and nothing at all where no
 * agent has been named: a stray letter over an unnamed agent would imply one is
 * there.
 */
function PropertyAgent({ property }: { property: Property }) {
  const agent = property.agent
  const [chatting, setChatting] = useState(false)

  if (agent === undefined || (agent.name === null && !agent.enabled)) {
    return (
      <p className="small faint property-card__agent">
        <Link to={`/agent?property=${property.id}`}>Give this property an agent</Link>
      </p>
    )
  }

  const reachable = agent.can_answer || agent.can_be_asked_later

  return (
    <>
      {/*
        The whole block is the way in to a conversation, not a link to a
        settings page. Somebody clicking a face and a name expects to talk to
        it; sending them to the screen where providers and gates are configured
        is like opening the settings app to send a text. Editing lives on the
        Agents screen and is reachable from inside the chat.
      */}
      <button
        type="button"
        className="property-card__agent property-card__agent--button row"
        onClick={() => setChatting(true)}
        aria-haspopup="dialog"
      >
        <AgentAvatar url={agent.avatar_url} initial={agent.initial} />

        <span className="stack stack--tight property-card__agent-text">
          <span className="property-card__agent-name">
            {agent.name ?? 'This property’s agent'}
          </span>
          <span className="small faint">
            {!agent.enabled
              ? 'Drafts only — not turned on'
              : agent.is_simulated
                ? 'Demo mode — test replies only'
                : reachable
                ? 'Ask it anything about this place'
                : 'Enabled — connection setup needed'}
          </span>
        </span>
      </button>

      {chatting && <AgentChatDrawer property={property} onClose={() => setChatting(false)} />}
    </>
  )
}

/**
 * Who to call about this property.
 *
 * On the card because the moment it is needed — a boiler at midnight — is not a
 * moment for navigating. Undefined rather than zero is left silent: a list that
 * nobody has filled in and a list that was never asked for look the same to a
 * reader, and only one of them is worth prompting about.
 */
function PropertyHelpers({ property }: { property: Property }) {
  const count = property.helpers_count
  const knowledge = property.agent?.knowledge_base_url ?? null

  if (count === undefined && knowledge === null) {
    return null
  }

  /*
   * One quiet line under the agent, not two buttons beside it.
   *
   * The knowledge link used to sit on the agent row and pushed itself off the
   * edge of the card on a narrow column. Neither of these is the thing somebody
   * came to the card for, so they read as footnotes and wrap like text.
   */
  return (
    <p className="small faint property-card__helpers">
      {count !== undefined && (
        <Link to={`/agent?property=${property.id}`}>
          <LifeBuoy size={13} aria-hidden />{' '}
          {count === 0
            ? 'No one to call yet'
            : `${count} ${count === 1 ? 'person' : 'people'} to call`}
        </Link>
      )}

      {knowledge !== null && (
        <a
          href={knowledge}
          target="_blank"
          // noreferrer as well as noopener: the target page should not be told
          // which screen of this platform sent somebody to it.
          rel="noopener noreferrer"
        >
          <BookOpen size={13} aria-hidden /> Knowledge base
        </a>
      )}
    </p>
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
    beds: property.capacity.beds,
    base_rate: property.pricing.base_rate.amount,
    check_in_time: property.arrival?.check_in_time ?? '',
    check_in_until: property.arrival?.check_in_until ?? '',
    check_out_time: property.arrival?.check_out_time ?? '',
    minimum_nights: property.pricing.minimum_nights,
    maximum_nights: property.pricing.maximum_nights ?? '',
    cleaning_fee: property.pricing.cleaning_fee.amount,
    security_deposit: property.pricing.security_deposit?.amount ?? '',
    extra_guest_fee: property.pricing.extra_guest_fee?.amount ?? '',
    extra_guest_after: property.pricing.extra_guest_after ?? '',
    instant_book: property.pricing.instant_book,

    summary: property.content?.summary ?? '',
    description: property.content?.description ?? '',
    space_description: property.content?.space_description ?? '',
    neighbourhood_description: property.content?.neighbourhood_description ?? '',
    transit_description: property.content?.transit_description ?? '',
    house_rules: property.content?.house_rules ?? '',
    check_in_method: property.arrival?.check_in_method ?? '',
    check_in_instructions: property.content?.check_in_instructions ?? '',
    check_out_instructions: property.content?.check_out_instructions ?? '',

    /*
     * The arrival secrets, which this function used to leave out entirely.
     *
     * That was the bug behind "these fields don't persist". Every one of them was
     * stored and returned correctly; the form simply never read them, so editing
     * a property showed Wi-Fi, door code and access notes blank however many
     * times they had been filled in. Indistinguishable from data loss, and worse
     * than a cosmetic fault: somebody re-types a door code they think was lost,
     * or concludes the guest agent has no arrival details to withhold.
     *
     * `?? ''` only ever applies where the API really sent null. Where `access` is
     * absent — a colleague whose role may not see credentials — the fields are
     * omitted from the form instead, so saving cannot blank what they cannot read.
     */
    ...(property.access === undefined
      ? {}
      : {
          wifi_network: property.access.wifi_network ?? '',
          wifi_password: property.access.wifi_password ?? '',
          door_code: property.access.door_code ?? '',
          access_notes: property.access.access_notes ?? '',
        }),

    // Seeded from what the property already has, so saving without touching
    // them does not strip every amenity off the record.
    amenity_ids: (property.amenities ?? []).map((amenity) => amenity.id),
  }
}

/**
 * Whether a property may go on sale, and the button that puts it there.
 *
 * A property is created as a draft, and a draft is refused by the availability
 * engine: booking one comes back "the property is draft rather than active". The
 * endpoints to check readiness and to activate have always been there, and
 * nothing in the interface called either — so a property added by hand stayed a
 * draft permanently, and the first booking against it failed with a message
 * naming a state the person had no way to leave.
 *
 * The server lists what is missing, so this shows the server's own list rather
 * than a second copy of the rules that could drift from it.
 */
function ReadinessPanel({ property }: { property: Property }) {
  const { can } = useAuth()
  const queryClient = useQueryClient()

  const readiness = useQuery({
    queryKey: ['property-readiness', property.id],
    queryFn: () =>
      api.get<{ ready: boolean; blockers: string[]; status: string }>(
        `properties/${property.id}/readiness`,
      ),
  })

  const change = useMutation({
    mutationFn: (action: 'activate' | 'deactivate') =>
      api.post(`properties/${property.id}/${action}`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['property-readiness', property.id] })
      void queryClient.invalidateQueries({ queryKey: ['property', property.id] })
      void queryClient.invalidateQueries({ queryKey: ['properties'] })
    },
  })

  const state = readiness.data
  const isActive = (state?.status ?? property.status) === 'active'

  return (
    <div className="field">
      <span className="field__label">Availability</span>

      {isActive ? (
        <p className="small muted">This property is active and can be booked.</p>
      ) : state?.ready === true ? (
        <p className="small muted">Ready to go on sale. Bookings are refused until it does.</p>
      ) : (
        <ul className="small muted">
          {(state?.blockers ?? []).map((blocker) => (
            <li key={blocker}>{blocker}</li>
          ))}
        </ul>
      )}

      {can('properties.update') && (
        <button
          type="button"
          className={isActive ? 'btn btn--sm mt-1' : 'btn btn--sm btn--primary mt-1'}
          disabled={change.isPending || (!isActive && state?.ready !== true)}
          onClick={() => change.mutate(isActive ? 'deactivate' : 'activate')}
        >
          {change.isPending ? 'Saving…' : isActive ? 'Take off sale' : 'Activate'}
        </button>
      )}

      {change.error !== null && (
        <p className="field__error small" role="alert">
          {change.error instanceof ApiError
            ? change.error.message
            : 'That property could not be changed.'}
        </p>
      )}
    </div>
  )
}

/**
 * The listings a property is sold through.
 *
 * Worth a panel of its own because the distinction is otherwise invisible and
 * bites immediately: a booking, a calendar row, a rate plan and a channel
 * mapping all attach to a *listing*, not to a property. A property whose
 * listings nobody could see or add was a property that appeared everywhere and
 * could be used for nothing.
 *
 * Every property now gets one when it is created, so this is usually a single
 * row. It is here for the two things that single row cannot do by itself: going
 * on sale, and being joined by a second listing when one place is let more than
 * one way — a whole house and its rooms, say.
 */
function ListingsPanel({ property }: { property: Property }) {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [name, setName] = useState('')
  const [editing, setEditing] = useState<Listing | null>(null)
  const [removing, setRemoving] = useState<Listing | null>(null)
  const [reason, setReason] = useState('')

  const listings = useQuery({
    queryKey: ['property-listings', property.id],
    queryFn: () =>
      api.get<{ data: Listing[] }>(`properties/${property.id}/listings`, {
        // Archived ones too, which the endpoint otherwise leaves out. Removing a
        // listing here archives it, so this is where it has to be possible to see
        // what was removed and put it back.
        status: 'draft,published,paused,archived',
      }),
  })

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['property-listings', property.id] })
    // The pickers on the reservation and channel forms read the same records.
    void queryClient.invalidateQueries({ queryKey: ['listings'] })
    // And removing the last listing takes the property off sale, so the panel
    // above this one is no longer telling the truth either.
    void queryClient.invalidateQueries({ queryKey: ['property-readiness', property.id] })
    void queryClient.invalidateQueries({ queryKey: ['property', property.id] })
    void queryClient.invalidateQueries({ queryKey: ['properties'] })
  }

  const add = useMutation({
    mutationFn: () => api.post(`properties/${property.id}/listings`, { name }),
    onSuccess: () => {
      setName('')
      refresh()
    },
  })

  const setStatus = useMutation({
    mutationFn: ({ listing, action }: { listing: Listing; action: 'publish' | 'pause' | 'restore' }) =>
      api.post(`listings/${listing.id}/${action}`, {}),
    onSuccess: refresh,
  })

  const rows = listings.data?.data ?? []
  const live = rows.filter((listing) => listing.status !== 'archived')

  /*
   * Whether removing this listing means taking the property off sale as well.
   *
   * The server refuses to archive the last listing of a property that is still
   * on sale, for a good reason: everything downstream takes a listing, so the
   * property would vanish from every picker while still reading as active.
   *
   * But almost every property here has exactly one listing, so as first built
   * this made the Remove button refuse every single time — a guard is not a
   * feature, and "the server will refuse" is not an answer to somebody who
   * wants the thing gone. What they mean by removing the only listing is that
   * they do not want the property on sale, so the dialog offers precisely that
   * and does both, in an order where the risky half cannot happen alone.
   */
  const lastOnSale = live.length === 1 && property.status === 'active'

  const remove = useMutation({
    mutationFn: async (listing: Listing) => {
      const because = reason.trim() === '' ? undefined : { reason: reason.trim() }

      // Off sale first. If this is refused the listing is untouched, which is
      // the right way round — the reverse could leave a live property with
      // nothing to book, which is the state all of this exists to prevent.
      if (lastOnSale) {
        await api.post(`properties/${property.id}/deactivate`, because ?? {})
      }

      return api.delete(`listings/${listing.id}`, because)
    },
    onSuccess: () => {
      setRemoving(null)
      setReason('')
      refresh()
    },
  })

  return (
    <div className="field">
      <span className="field__label">Listings</span>

      {editing !== null && (
        <ListingDialog
          listing={editing}
          property={property}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            refresh()
          }}
        />
      )}

      {rows.length === 0 ? (
        <p className="small muted">
          This property has no listing, so it cannot be booked. Adding one below fixes
          that — or run <code>properties:ensure-listings</code> to do it for every property
          at once.
        </p>
      ) : (
        <table className="data">
          <tbody>
            {rows.map((listing) => {
              const archived = listing.status === 'archived'

              return (
                <tr key={listing.id} className={archived ? 'faint' : undefined}>
                  <td>
                    {listing.title || listing.name}
                    {listing.is_primary && <span className="small faint"> · primary</span>}
                  </td>
                  <td className="small muted">{listing.status}</td>
                  <td className="numeric small">{formatMoney(listing.pricing.base_rate)}</td>
                  <td>
                    <div className="row">
                      {archived
                        ? can('listings.delete') && (
                            <button
                              type="button"
                              className="btn btn--sm btn--ghost"
                              disabled={setStatus.isPending}
                              onClick={() => setStatus.mutate({ listing, action: 'restore' })}
                            >
                              Bring back
                            </button>
                          )
                        : (
                            <>
                              {can('listings.update') && (
                                <button
                                  type="button"
                                  className="btn btn--sm btn--ghost"
                                  onClick={() => setEditing(listing)}
                                >
                                  <Pencil size={14} aria-hidden /> Edit
                                </button>
                              )}

                              {can('listings.publish') && (
                                <button
                                  type="button"
                                  className="btn btn--sm btn--ghost"
                                  disabled={setStatus.isPending}
                                  onClick={() =>
                                    setStatus.mutate({
                                      listing,
                                      action: listing.status === 'published' ? 'pause' : 'publish',
                                    })
                                  }
                                >
                                  {listing.status === 'published' ? 'Pause' : 'Publish'}
                                </button>
                              )}

                              {can('listings.delete') && (
                                <button
                                  type="button"
                                  className="btn btn--sm btn--ghost"
                                  onClick={() => {
                                    setRemoving(listing)
                                    setReason('')
                                  }}
                                >
                                  <Trash2 size={14} aria-hidden /> Remove
                                </button>
                              )}
                            </>
                          )}
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      )}

      {/*
        * Asked rather than done, because it is outward-facing: it pulls the
        * listing off whatever channel it is on. Inline rather than a browser
        * confirm, so it can say what actually happens to the bookings — which is
        * nothing, and is the thing somebody about to press it wants to know.
        */}
      {removing !== null && (
        <div className="notice notice--warning stack" role="alertdialog" aria-label="Remove listing">
          <p className="small">
            Take <strong>{removing.title || removing.name}</strong> off the books? Its
            bookings, statements and published history stay exactly as they are, and you
            can bring it back from this panel.
            {lastOnSale && (
              <>
                {' '}
                It is the only listing <strong>{property.name}</strong> has, so the
                property comes off sale at the same time — otherwise it would stay listed
                as active with nothing anybody could book. You can put it back on sale
                from Availability above.
              </>
            )}
          </p>

          <input
            type="text"
            aria-label="Why it is being removed"
            placeholder="Why (optional — it goes in the audit log)"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
          />

          <div className="row">
            <button
              type="button"
              className="btn btn--sm"
              onClick={() => setRemoving(null)}
              disabled={remove.isPending}
            >
              Cancel
            </button>
            <button
              type="button"
              className="btn btn--sm btn--danger"
              onClick={() => remove.mutate(removing)}
              disabled={remove.isPending}
            >
              {remove.isPending
                ? 'Removing…'
                : lastOnSale
                  ? 'Take off sale and remove'
                  : 'Take it off the books'}
            </button>
          </div>

          {remove.error !== null && (
            <p className="field__error small" role="alert">
              {remove.error instanceof ApiError
                ? remove.error.message
                : 'That listing could not be removed.'}
            </p>
          )}
        </div>
      )}

      {/* The server's own sentence, which names what is missing — a photo, a
          description, an inactive property — rather than a generic refusal. */}
      {setStatus.error !== null && (
        <p className="field__error small" role="alert">
          {setStatus.error instanceof ApiError
            ? setStatus.error.message
            : 'That listing could not be changed.'}
        </p>
      )}

      {can('listings.create') && (
        <div className="row mt-1">
          <input
            type="text"
            aria-label="New listing name"
            placeholder="Another way to let this place"
            value={name}
            onChange={(event) => setName(event.target.value)}
          />
          <button
            type="button"
            className="btn btn--sm"
            disabled={name.trim() === '' || add.isPending}
            onClick={() => add.mutate()}
          >
            {add.isPending ? 'Adding…' : 'Add listing'}
          </button>
        </div>
      )}

      {add.error !== null && (
        <p className="field__error small" role="alert">
          {add.error instanceof ApiError ? add.error.message : 'That listing could not be added.'}
        </p>
      )}

      <p className="field__hint small faint">
        A booking is taken against a listing. Its wording, rates and rules follow this
        property unless the listing overrides them. Removing one archives it — nothing
        about a listing is ever deleted, because reservations and statements point at it.
      </p>
    </div>
  )
}

/**
 * The inheritable half of a listing, and what each field is currently doing.
 *
 * `overridden_fields` names the fields the listing owns. For those, `resolved`
 * holds the listing's own value; for every other field it holds what the listing
 * is inheriting from the property. So an inherited field is shown **empty**, with
 * the inherited value as its hint, and an owned one is shown filled.
 *
 * Seeding an inherited field with the value it inherits would be the wrong
 * behaviour in a quiet way: the form would look identical either way, and the
 * first save would silently copy the property's wording and prices onto the
 * listing as overrides. From then on, correcting the property would stop
 * reaching it, and nobody would know why.
 */
const LISTING_FIELDS: { name: string; label: string; type: FieldSpec['type']; rows?: number }[] = [
  // Inherits the property's name without being one of the inherited fields,
  // which is why it is seeded from `own_title` rather than `title`.
  { name: 'title', label: 'Title guests see', type: 'text' },
  { name: 'summary', label: 'Summary', type: 'textarea', rows: 2 },
  { name: 'description', label: 'Description', type: 'textarea', rows: 5 },
  { name: 'space_description', label: 'The space', type: 'textarea' },
  { name: 'neighbourhood_description', label: 'The neighbourhood', type: 'textarea' },
  { name: 'transit_description', label: 'Getting around', type: 'textarea' },
  { name: 'house_rules', label: 'House rules', type: 'textarea' },
  { name: 'check_in_instructions', label: 'Arrival instructions', type: 'textarea' },
  { name: 'check_out_instructions', label: 'Departure instructions', type: 'textarea' },
  { name: 'max_occupancy', label: 'Sleeps', type: 'number' },
  { name: 'bedrooms', label: 'Bedrooms', type: 'number' },
  { name: 'bathrooms', label: 'Bathrooms', type: 'number' },
  { name: 'beds', label: 'Beds', type: 'number' },
  { name: 'base_rate', label: 'Base rate per night', type: 'money' },
  { name: 'cleaning_fee', label: 'Cleaning fee', type: 'money' },
  { name: 'extra_guest_fee', label: 'Extra guest fee', type: 'money' },
  { name: 'extra_guest_after', label: 'Charged after this many guests', type: 'number' },
  { name: 'minimum_nights', label: 'Minimum nights', type: 'number' },
  { name: 'maximum_nights', label: 'Maximum nights', type: 'number' },
  { name: 'check_in_time', label: 'Check-in from', type: 'time' },
  { name: 'check_out_time', label: 'Check-out by', type: 'time' },
]

/** Editing one listing. */
function ListingDialog({
  listing,
  property,
  onClose,
  onSaved,
}: {
  listing: Listing
  property: Property
  onClose: () => void
  onSaved: () => void
}) {
  const queryClient = useQueryClient()

  // The row carries no content at all, so the form is seeded from the detail.
  const detail = useQuery({
    queryKey: ['listing', listing.id],
    queryFn: () => api.get<{ data: Listing }>(`listings/${listing.id}`),
  })

  const save = useMutation({
    mutationFn: (values: RecordValues) => api.patch(`listings/${listing.id}`, values),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['listing', listing.id] })
      onSaved()
    },
  })

  const record = detail.data?.data ?? null

  const fields: FieldSpec[] = useMemo(() => {
    const owned = new Set(record?.overridden_fields ?? [])
    const resolved = record?.resolved ?? {}

    const inheritable = LISTING_FIELDS.map((field) => {
      const inherited =
        field.name === 'title'
          ? (record?.own_title ?? null) === null
            ? property.name
            : null
          : owned.has(field.name)
            ? null
            : resolved[field.name]

      return {
        ...field,
        hint:
          inherited === null || inherited === undefined || inherited === ''
            ? 'Empty follows the property.'
            : `Empty follows the property: ${
                field.type === 'money' && typeof inherited === 'number'
                  ? (inherited / 100).toFixed(2)
                  : String(inherited)
              }`,
      } as FieldSpec
    })

    return [
      {
        name: 'name',
        label: 'Internal name',
        type: 'text',
        required: true,
        hint: 'What your team calls this offer. Guests never see it.',
      },
      ...inheritable,
      {
        name: 'instant_book',
        label: 'Instant book',
        type: 'select',
        options: [
          { value: '1', label: 'Yes' },
          { value: '0', label: 'No' },
        ],
        // A checkbox cannot say "follow the property", and one seeded false
        // against a property that says yes would be stating the opposite of the
        // truth. The blank option is the third state.
        hint: 'Leave blank to follow the property.',
      },
      {
        name: 'change_reason',
        label: 'Why (optional)',
        type: 'text',
        hint: 'Kept with the version this save creates, so the history reads as a decision.',
      },
    ]
  }, [record, property.name])

  const initial: RecordValues | undefined = useMemo(() => {
    if (record === null) return undefined

    const owned = new Set(record.overridden_fields)
    // `own_title`, never `title`: the latter is the display title and falls back
    // to the property's name, so seeding from it would show a title this listing
    // does not have and turn it into one on the next save.
    const values: RecordValues = { name: record.name, title: record.own_title ?? '' }

    for (const field of LISTING_FIELDS) {
      if (field.name === 'title') continue

      const own = owned.has(field.name) ? record.resolved?.[field.name] : null

      values[field.name] = own === null || own === undefined ? '' : own
    }

    values.instant_book = owned.has('instant_book')
      ? (record.resolved?.instant_book === true ? '1' : '0')
      : ''
    values.change_reason = ''

    return values
  }, [record])

  if (record === null || initial === undefined) return null

  // Portalled out of the property dialog. This panel renders inside that
  // dialog's <form>, and a form inside a form is invalid markup the browser
  // silently unpicks — the inner submit button ends up driving the outer form.
  return createPortal(
    <RecordDialog
      // Named as a listing, because the property's own dialog is open behind it
      // and "Edit Alfama Terrace" over "Edit Alfama Terrace" says nothing.
      title={`Edit listing — ${record.title || record.name}`}
      description={`A listing of ${property.name}. Anything left empty follows the property, so the two stay in step when you correct it there.`}
      fields={fields}
      initial={initial}
      submitLabel="Save listing"
      pending={save.isPending}
      error={save.error}
      onSubmit={(values) => save.mutate(values)}
      onClose={onClose}
    />,
    document.body,
  )
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
            <PropertyImage key={`${photo.id}:${photo.url}`} url={photo.url} caption={photo.caption} />
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
