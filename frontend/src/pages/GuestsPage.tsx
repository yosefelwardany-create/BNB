import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus } from 'lucide-react'
import { api } from '@/api/client'
import type { Guest, Paginated } from '@/api/types'
import { QueryState } from '@/components/QueryState'
import { RecordDialog } from '@/components/RecordDialog'
import { useRecordDialog } from '@/lib/useRecordDialog'
import type { FieldSpec, RecordValues } from '@/components/RecordDialog'
import { formatDate, formatMoney } from '@/lib/format'
import { useAuth } from '@/lib/auth'

const GUEST_FIELDS: FieldSpec[] = [
  { name: 'first_name', label: 'First name', type: 'text', required: true },
  { name: 'last_name', label: 'Last name', type: 'text' },
  { name: 'email', label: 'Email', type: 'email' },
  { name: 'phone', label: 'Phone', type: 'tel' },
  { name: 'country_code', label: 'Country', type: 'text', placeholder: 'PT' },
  { name: 'language', label: 'Language', type: 'text', placeholder: 'en' },
  { name: 'notes', label: 'Notes', type: 'textarea' },
]

export function GuestsPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()

  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  const dialog = useRecordDialog<Guest>()

  const save = useMutation({
    mutationFn: (values: RecordValues) =>
      dialog.editing === null
        ? api.post('guests', values)
        : api.patch(`guests/${dialog.editing.id}`, values),
    onSuccess: () => {
      dialog.close()
      void queryClient.invalidateQueries({ queryKey: ['guests'] })
    },
  })

  const query = useQuery({
    queryKey: ['guests', { search, page }],
    queryFn: () =>
      api.get<Paginated<Guest>>('guests', {
        search: search || undefined,
        page,
        per_page: 25,
      }),
    placeholderData: keepPreviousData,
  })

  const guests = query.data?.data ?? []
  const meta = query.data?.meta

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Guests</h1>
          <div className="page-header__subtitle">
            {meta ? `${meta.total} profile(s)` : 'Loading…'}
          </div>
        </div>

        {can('guests.create') && (
          <button type="button" className="btn btn--primary" onClick={dialog.create}>
            <Plus size={16} aria-hidden /> New guest
          </button>
        )}
      </div>

      {dialog.isOpen && (
        <RecordDialog
          title={dialog.editing === null ? 'New guest' : `Edit ${dialog.editing.display_name}`}
          description={
            dialog.editing === null
              ? 'A guest who booked somewhere this platform is not connected to. Only a first name is required; an email or phone number lets the same person be recognised next time.'
              : undefined
          }
          fields={GUEST_FIELDS}
          initial={
            dialog.editing === null
              ? undefined
              : {
                  first_name: dialog.editing.first_name,
                  last_name: dialog.editing.last_name ?? '',
                  email: dialog.editing.email ?? '',
                  phone: dialog.editing.phone ?? '',
                  country_code: dialog.editing.country_code ?? '',
                  language: dialog.editing.language ?? '',
                }
          }
          submitLabel={dialog.editing === null ? 'Create guest' : 'Save changes'}
          pending={save.isPending}
          error={save.error}
          onSubmit={(values) => save.mutate(values)}
          onClose={dialog.close}
        />
      )}

      <div className="filters">
        <div className="field">
          <label className="field__label" htmlFor="guest-search">
            Search
          </label>
          <input
            id="guest-search"
            type="search"
            placeholder="Name, email or phone"
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
          isEmpty={guests.length === 0}
          emptyTitle="No guests yet"
          emptyBody="Guest profiles are created automatically with each booking."
        >
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Guest</th>
                  <th>Contact</th>
                  <th className="numeric">Stays</th>
                  <th className="numeric">Nights</th>
                  <th className="numeric">Lifetime value</th>
                  <th>Last stay</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {guests.map((guest) => (
                  <tr key={guest.id}>
                    <td>
                      <div className="strong">{guest.display_name}</div>
                      {guest.stats.is_returning && (
                        <span className="chip chip--indigo" style={{ marginTop: 3 }}>
                          Returning
                        </span>
                      )}
                    </td>
                    <td className="small">
                      <div className="truncate" style={{ maxWidth: 200 }}>
                        {guest.email ?? '—'}
                      </div>
                      <div className="faint">{guest.phone ?? ''}</div>
                    </td>
                    <td className="numeric">{guest.stats.reservations}</td>
                    <td className="numeric">{guest.stats.nights}</td>
                    <td className="numeric">{formatMoney(guest.stats.lifetime_value)}</td>
                    <td className="nowrap small">{formatDate(guest.stats.last_stay_date)}</td>
                    <td>
                      {can('guests.update') && (
                        <button
                          type="button"
                          className="btn btn--sm btn--ghost"
                          onClick={() => dialog.edit(guest)}
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
