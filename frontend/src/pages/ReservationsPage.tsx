import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { HostexReservationDetails } from '@/components/HostexDetails'
import { channelLabel } from '@/lib/channelLabel'
import { Plus } from 'lucide-react'
import { Link } from 'react-router-dom'
import { api } from '@/api/client'
import type { Paginated, Reservation } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { RecordDialog } from '@/components/RecordDialog'
import { useRecordDialog } from '@/lib/useRecordDialog'
import type { FieldSpec, RecordValues } from '@/components/RecordDialog'
import { Segmented } from '@/components/Segmented'
import { formatDateRange, formatMoney } from '@/lib/format'
import { useAuth } from '@/lib/auth'
import { useListingOptions } from '@/lib/options'

const STATUSES = [
  { value: '', label: 'All statuses' },
  { value: 'inquiry,quote', label: 'Enquiries' },
  { value: 'tentative', label: 'Held' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'checked_in', label: 'In house' },
  { value: 'checked_out', label: 'Departed' },
  { value: 'cancelled,no_show', label: 'Cancelled' },
]

export function ReservationsPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()

  const { options: listings } = useListingOptions()
  const dialog = useRecordDialog<never>()

  const fields: FieldSpec[] = useMemo(
    () => [
      {
        name: 'listing_id',
        label: 'Listing',
        type: 'select',
        options: listings,
        required: true,
        hint: 'A booking is against a listing rather than a property. Every property has one, named after it, and a property let more than one way has several.',
        emptyHint: (
          <>
            There is nothing here to book yet. Add a property on{' '}
            <Link to="/properties">Properties</Link> — it gets a listing of its own, which
            then appears in this picker.
          </>
        ),
      },
      { name: 'check_in', label: 'Check in', type: 'date', required: true },
      { name: 'check_out', label: 'Check out', type: 'date', required: true },
      { name: 'adults', label: 'Adults', type: 'number' },
      { name: 'children', label: 'Children', type: 'number' },
      { name: 'guest_first_name', label: 'Guest first name', type: 'text', required: true },
      { name: 'guest_last_name', label: 'Guest last name', type: 'text' },
      { name: 'guest_email', label: 'Guest email', type: 'email' },
      { name: 'guest_phone', label: 'Guest phone', type: 'tel' },
      {
        name: 'source',
        label: 'Booked through',
        type: 'select',
        options: [
          { value: 'direct', label: 'Direct' },
          { value: 'airbnb', label: 'Airbnb' },
          { value: 'booking_com', label: 'Booking.com' },
          { value: 'vrbo', label: 'Vrbo' },
          { value: 'other', label: 'Somewhere else' },
        ],
      },
      /*
       * Recording business that already exists, which is how anybody arriving
       * from another system starts: months of past stays, and guests in the
       * building today. The availability engine refuses an arrival in the past
       * because that rule protects a *sale* — and writing down what already
       * happened is not one. Ticking this waives the lead-time rules and nothing
       * else: a clash with another booking is still refused.
       */
      {
        name: 'records_existing_stay',
        label: 'This stay has already started',
        type: 'checkbox',
        hint: 'Tick to record a booking that is under way or finished. Dates already sold are still refused.',
      },
      /*
       * The status the booking is in, which was not askable at all.
       *
       * Two things it unlocks, both reported from a real migration. A stay that
       * finished is recorded as departed rather than as a confirmed booking that
       * will never arrive; and a booking the guest cancelled can be written down
       * *without* holding the nights, which is how a cancelled stay and the one
       * that replaced it both end up on the record instead of the second being
       * refused as a clash.
       */
      {
        name: 'status',
        label: 'Status',
        type: 'select',
        options: [
          { value: 'confirmed', label: 'Confirmed' },
          { value: 'checked_in', label: 'In house' },
          { value: 'checked_out', label: 'Departed' },
          { value: 'tentative', label: 'Held' },
          { value: 'inquiry', label: 'Enquiry' },
          { value: 'quote', label: 'Quote' },
          { value: 'cancelled', label: 'Cancelled' },
          { value: 'no_show', label: 'No show' },
        ],
        hint: 'Confirmed unless you say otherwise. Cancelled, no-show, enquiry and quote hold no nights, so they never clash with another booking.',
      },
      {
        name: 'external_confirmation_code',
        label: 'Channel’s confirmation code',
        type: 'text',
        placeholder: 'HMX5F8DWAE',
        hint: 'What Airbnb or Booking.com calls this reservation. Searchable, and what a payout query is settled with.',
      },
      {
        name: 'booked_at',
        label: 'Booked on',
        type: 'date',
        hint: 'When the guest actually booked. Left empty this is today, which makes lead-time figures wrong for anything entered after the fact.',
      },
      {
        name: 'guest_notes',
        label: 'What the guest said',
        type: 'textarea',
        hint: 'Their own words — requests, arrival time. Kept apart from your notes because a guest may be shown this and never the internal ones.',
      },
      {
        name: 'internal_notes',
        label: 'Internal notes',
        type: 'textarea',
        hint: 'Yours. Payout amounts, what the channel collected, anything the guest must not read.',
      },
    ],
    [listings],
  )

  const create = useMutation({
    mutationFn: (values: RecordValues) =>
      api.post('reservations', {
        listing_id: values.listing_id,
        check_in: values.check_in,
        check_out: values.check_out,
        adults: values.adults ?? 1,
        children: values.children,
        source: values.source,
        status: values.status,
        external_confirmation_code: values.external_confirmation_code,
        guest_notes: values.guest_notes,
        internal_notes: values.internal_notes,
        records_existing_stay: values.records_existing_stay === true,
        booked_at: values.booked_at,
        // The API takes the guest nested, so the flat form is folded back here
        // rather than asking a person to think about the shape of a payload.
        guest: {
          first_name: values.guest_first_name,
          last_name: values.guest_last_name,
          email: values.guest_email,
          phone: values.guest_phone,
        },
      }),
    onSuccess: () => {
      dialog.close()
      void queryClient.invalidateQueries({ queryKey: ['reservations'] })
    },
  })

  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)

  const query = useQuery({
    queryKey: ['reservations', { search, status, page }],
    queryFn: () =>
      api.get<Paginated<Reservation>>('reservations', {
        search: search || undefined,
        status: status || undefined,
        page,
        per_page: 25,
        sort: 'check_in_date',
      }),
    // Keeping the previous page visible while the next loads avoids the table
    // collapsing to a spinner on every keystroke.
    placeholderData: keepPreviousData,
  })

  const reservations = query.data?.data ?? []
  const meta = query.data?.meta

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Reservations</h1>
          <div className="page-header__subtitle">
            {meta ? `${meta.total} booking(s)` : 'Loading…'}
          </div>
        </div>

        {can('reservations.create') && (
          <button type="button" className="btn btn--primary" onClick={dialog.create}>
            <Plus size={16} aria-hidden /> New booking
          </button>
        )}
      </div>

      {dialog.isOpen && (
        <RecordDialog
          title="New booking"
          description="A booking taken somewhere this platform is not connected to. The dates are checked against the calendar, so a clash is refused rather than double-sold."
          fields={fields}
          submitLabel="Create booking"
          pending={create.isPending}
          error={create.error}
          onSubmit={(values) => create.mutate(values)}
          onClose={dialog.close}
        />
      )}

      <div className="filters">
        <div className="field">
          <label className="field__label" htmlFor="search">
            Search
          </label>
          <input
            id="search"
            type="search"
            placeholder="Code, guest name or email"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
          />
        </div>

        <div className="field">
          <span className="field__label">Status</span>
          <Segmented
            label="Status"
            value={status}
            onChange={(next) => {
              setStatus(next)
              setPage(1)
            }}
            options={STATUSES}
          />
        </div>
      </div>

      <div className="card">
        <QueryState
          isLoading={query.isLoading}
          error={query.error}
          isEmpty={reservations.length === 0}
          emptyTitle="No reservations match"
          emptyBody="Try widening the filters."
        >
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Guest</th>
                  <th>Stay</th>
                  <th className="numeric">Nights</th>
                  <th className="numeric">Guests</th>
                  <th>Source</th>
                  <th>Status</th>
                  <th className="numeric">Total</th>
                  <th className="numeric">Owed</th>
                </tr>
              </thead>
              <tbody>
                {reservations.map((reservation) => (
                  <tr key={reservation.id}>
                    <td><div className="mono">{reservation.display_reference ?? reservation.external_confirmation_code ?? reservation.confirmation_code}</div><div className="small faint">{reservation.reference_label ?? 'Internal reference'}</div>{reservation.hostex && <HostexReservationDetails source={reservation.hostex} />}</td>
                    <td className="truncate" style={{ maxWidth: 180 }}>
                      {reservation.guest?.display_name ?? '—'}
                    </td>
                    <td className="nowrap">
                      {formatDateRange(reservation.stay.check_in_date, reservation.stay.check_out_date)}
                    </td>
                    <td className="numeric">{reservation.stay.nights}</td>
                    <td className="numeric">{reservation.guests.total ?? 'Unavailable'}</td>
                    <td className="small muted">{channelLabel(reservation.hostex?.channel_type ?? reservation.source)}</td>
                    <td>
                      <Chip label={reservation.status_label} colour={reservation.status_colour} />
                    </td>
                    <td className="numeric">{formatMoney(reservation.financials.grand_total)}{reservation.hostex && <div className="small faint">Stay rate · includes commission</div>}</td>
                    <td className="numeric">
                      {reservation.financials.balance_due == null ? <span className="faint">Unavailable</span> : reservation.financials.balance_due.amount > 0 ? (
                        <span className="strong">{formatMoney(reservation.financials.balance_due)}</span>
                      ) : (
                        <span className="faint">Paid</span>
                      )}
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
