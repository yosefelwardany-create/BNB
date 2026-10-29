import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowUp, ExternalLink, Globe, Plus } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type { Paginated, Website, WebsiteListingOption } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { RecordDialog } from '@/components/RecordDialog'
import type { FieldSpec, RecordValues } from '@/components/RecordDialog'
import { useRecordDialog } from '@/lib/useRecordDialog'

/**
 * Booking websites.
 *
 * The platform owner's brand site lists properties from every client account;
 * a client site only that client's. Both are created and run from here, by the
 * owner, in the same way every other operational screen works in the managed
 * service.
 */
export function WebsitesPage() {
  const queryClient = useQueryClient()
  const createDialog = useRecordDialog<never>()
  const [selectedId, setSelectedId] = useState<string | null>(null)

  const sites = useQuery({
    queryKey: ['platform-websites'],
    queryFn: () => api.get<{ data: Website[] }>('platform/websites'),
  })

  const organizations = useQuery({
    queryKey: ['platform-organizations', 'website-picker'],
    queryFn: () => api.get<Paginated<{ id: string; name: string }>>('platform/organizations', { per_page: 100 }),
    enabled: createDialog.isOpen,
  })

  const selected = sites.data?.data.find((candidate) => candidate.id === selectedId) ?? null

  const createFields: FieldSpec[] = useMemo(
    () => [
      {
        name: 'kind',
        label: 'Whose site',
        type: 'select',
        required: true,
        options: [
          { value: 'brand', label: 'Your brand site, across every client' },
          { value: 'client', label: 'One client’s own site' },
        ],
      },
      {
        name: 'organization_id',
        label: 'Client',
        type: 'select',
        hint: 'Only for a client’s own site.',
        options: (organizations.data?.data ?? []).map((organization) => ({ value: organization.id, label: organization.name })),
      },
      { name: 'name', label: 'Name', type: 'text', required: true },
      {
        name: 'slug',
        label: 'Address',
        type: 'text',
        required: true,
        hint: 'Lowercase letters, numbers and dashes. The site lives at …/sites/<address>.',
      },
    ],
    [organizations.data],
  )

  const create = useMutation({
    mutationFn: (values: RecordValues) =>
      api.post<{ data: Website }>('platform/websites', {
        kind: values.kind,
        name: values.name,
        slug: values.slug,
        ...(values.kind === 'client' ? { organization_id: values.organization_id } : {}),
      }),
    onSuccess: (result) => {
      createDialog.close()
      setSelectedId(result.data.id)
      void queryClient.invalidateQueries({ queryKey: ['platform-websites'] })
    },
  })

  return (
    <div className="page">
      <section className="card mb-3">
        <header className="card__header row row--between">
          <div>
            <h2>Websites</h2>
            <span className="small faint">Booking sites guests find and book on. Each shows only the published properties you choose.</span>
          </div>
          <button type="button" className="btn btn--sm btn--primary" onClick={createDialog.create}>
            <Plus size={15} aria-hidden /> New website
          </button>
        </header>

        <QueryState
          isLoading={sites.isLoading}
          error={sites.error}
          isEmpty={(sites.data?.data.length ?? 0) === 0}
          emptyTitle="No websites yet"
          emptyBody="Create your brand site, or a site for one client."
        >
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Whose</th>
                  <th>Status</th>
                  <th>Address</th>
                  <th className="numeric">Properties</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {(sites.data?.data ?? []).map((website) => (
                  <tr key={website.id}>
                    <td className="strong">{website.name}</td>
                    <td>{website.kind === 'brand' ? 'Brand site' : website.organization_name}</td>
                    <td>
                      <Chip label={website.status === 'live' ? 'Live' : 'Draft'} colour={website.status === 'live' ? 'emerald' : 'zinc'} />
                    </td>
                    <td>
                      <a href={website.public_url} target="_blank" rel="noreferrer" className="small">
                        {website.public_url.replace(/^https?:\/\//, '')}
                      </a>
                    </td>
                    <td className="numeric">{website.listings.length}</td>
                    <td>
                      <button type="button" className="btn btn--ghost btn--sm" aria-label={`Edit ${website.name}`} onClick={() => setSelectedId(website.id)}>
                        Edit
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </QueryState>
      </section>

      {selected !== null && <WebsiteEditor key={selected.id} website={selected} />}

      {createDialog.isOpen && (
        <RecordDialog
          title="New website"
          fields={createFields}
          initial={{ kind: 'brand' }}
          submitLabel="Create"
          pending={create.isPending}
          error={create.error}
          onSubmit={(values) => create.mutate({ kind: 'brand', ...values })}
          onClose={createDialog.close}
        />
      )}
    </div>
  )
}

const DETAIL_FIELDS: { key: keyof Website['settings']; label: string; type?: string; hint?: string }[] = [
  { key: 'heading', label: 'Heading', hint: 'The large line on the home page.' },
  { key: 'tagline', label: 'Tagline' },
  { key: 'primary_colour', label: 'Main colour', type: 'color' },
  { key: 'accent_colour', label: 'Button colour', type: 'color' },
  { key: 'contact_email', label: 'Contact email', type: 'email' },
  { key: 'contact_phone', label: 'Contact phone', type: 'tel' },
  { key: 'address_line', label: 'Address shown in the footer' },
  { key: 'instagram_url', label: 'Instagram link', type: 'url' },
  { key: 'facebook_url', label: 'Facebook link', type: 'url' },
]

/**
 * One site: its look and contact details, its images, which properties it
 * shows and in what order, and whether it is live.
 */
function WebsiteEditor({ website }: { website: Website }) {
  const queryClient = useQueryClient()
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['platform-websites'] })

  const [name, setName] = useState(website.name)
  const [slug, setSlug] = useState(website.slug)
  const [settings, setSettings] = useState<Record<string, string>>(() =>
    Object.fromEntries(DETAIL_FIELDS.map((field) => [field.key, website.settings[field.key] ?? ''])),
  )
  const [chosen, setChosen] = useState(() =>
    website.listings.map((row) => ({ listing_id: row.listing_id, is_featured: row.is_featured })),
  )

  const options = useQuery({
    queryKey: ['platform-website-listing-options', website.kind === 'client' ? website.organization_id : 'all'],
    queryFn: () =>
      api.get<{ data: WebsiteListingOption[] }>(
        'platform/website-listing-options',
        website.kind === 'client' && website.organization_id !== null ? { organization_id: website.organization_id } : undefined,
      ),
  })

  const saveDetails = useMutation({
    mutationFn: () =>
      api.patch<{ data: Website }>(`platform/websites/${website.id}`, {
        name,
        slug,
        settings: Object.fromEntries(Object.entries(settings).map(([key, value]) => [key, value.trim() === '' ? null : value.trim()])),
      }),
    onSuccess: refresh,
  })

  /*
   * A property that stopped being published (or whose client was suspended)
   * is no longer offered, and the server refuses it. Sent back on every save,
   * it would make the list unsaveable with no box left to untick, so it is
   * named here and left out of the next save.
   */
  const offered = (id: string) => options.data === undefined || options.data.data.some((option) => option.listing_id === id && option.ready)
  const gone = chosen.filter((row) => !offered(row.listing_id))

  const saveListings = useMutation({
    mutationFn: () =>
      api.put<{ data: Website }>(`platform/websites/${website.id}/listings`, {
        listings: chosen.filter((row) => offered(row.listing_id)),
      }),
    onSuccess: refresh,
  })

  // Put a home on sale from here, when that is all it lacks.
  const publishListing = useMutation({
    mutationFn: (listingId: string) => api.post<{ data: WebsiteListingOption }>(`platform/website-listing-options/${listingId}/publish`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['platform-website-listing-options'] }),
  })

  const publication = useMutation({
    mutationFn: (live: boolean) => api.post<{ data: Website }>(`platform/websites/${website.id}/${live ? 'publish' : 'unpublish'}`),
    onSuccess: refresh,
  })

  const upload = useMutation({
    mutationFn: ({ kind, file }: { kind: 'logo' | 'hero'; file: File }) => {
      const form = new FormData()
      form.append('kind', kind)
      form.append('file', file)

      return api.post<{ data: Website }>(`platform/websites/${website.id}/media`, form)
    },
    onSuccess: refresh,
  })

  const byId = new Map((options.data?.data ?? []).map((option) => [option.listing_id, option]))
  const chosenIds = chosen.map((row) => row.listing_id)
  const ordered = [
    ...chosenIds.map((id) => byId.get(id)).filter((option): option is WebsiteListingOption => option !== undefined && option.ready),
    ...(options.data?.data ?? []).filter((option) => option.ready && !chosenIds.includes(option.listing_id)),
    // Not on sale yet, so not choosable: shown with what they still need.
    ...(options.data?.data ?? []).filter((option) => !option.ready),
  ]

  const toggle = (id: string) =>
    setChosen((rows) => (rows.some((row) => row.listing_id === id)
      ? rows.filter((row) => row.listing_id !== id)
      : [...rows, { listing_id: id, is_featured: false }]))

  const moveUp = (id: string) =>
    setChosen((rows) => {
      const index = rows.findIndex((row) => row.listing_id === id)
      if (index <= 0) return rows
      const next = [...rows]
      const [row] = next.splice(index, 1)
      if (row !== undefined) next.splice(index - 1, 0, row)

      return next
    })

  const feature = (id: string) =>
    setChosen((rows) => rows.map((row) => (row.listing_id === id ? { ...row, is_featured: !row.is_featured } : row)))

  const errorText = (error: unknown, fallback: string) => (error instanceof ApiError ? error.message : fallback)

  return (
    <section className="card mb-3" aria-label={`Edit ${website.name}`}>
      <header className="card__header row row--between">
        <div className="row">
          <Globe size={18} aria-hidden />
          <h2>{website.name}</h2>
          <Chip label={website.status === 'live' ? 'Live' : 'Draft'} colour={website.status === 'live' ? 'emerald' : 'zinc'} />
        </div>
        <div className="row">
          <a className="btn btn--ghost btn--sm" href={website.public_url} target="_blank" rel="noreferrer">
            <ExternalLink size={14} aria-hidden /> View site
          </a>
          <button
            type="button"
            className={website.status === 'live' ? 'btn btn--ghost btn--sm' : 'btn btn--primary btn--sm'}
            disabled={publication.isPending}
            onClick={() => publication.mutate(website.status !== 'live')}
          >
            {website.status === 'live' ? 'Unpublish' : 'Publish'}
          </button>
        </div>
      </header>

      <div className="card__body stack">
        {publication.error !== null && (
          <p className="notice notice--error" role="alert">{errorText(publication.error, 'The site could not be published.')}</p>
        )}

        {website.readiness !== undefined && website.readiness.length > 0 && (
          <div className="stack">
            <h3>Ready to take bookings</h3>
            <ul className="stack" aria-label="Ready to take bookings" style={{ listStyle: 'none', padding: 0, margin: 0 }}>
              {website.readiness.map((line) => (
                <li key={line.key} className="row" style={{ alignItems: 'flex-start' }}>
                  <Chip label={line.ok ? 'Ready' : 'Needs attention'} colour={line.ok ? 'emerald' : line.blocks_publishing ? 'rose' : 'amber'} />
                  <span className="small">{line.message}</span>
                </li>
              ))}
            </ul>
          </div>
        )}

        <div className="stack">
          <h3>Details</h3>
          <div className="grid grid--two">
            <label className="field">
              <span className="field__label">Name</span>
              <input className="input" value={name} onChange={(event) => setName(event.target.value)} />
            </label>
            <label className="field">
              <span className="field__label">Address</span>
              <input className="input" value={slug} onChange={(event) => setSlug(event.target.value)} />
            </label>
            {DETAIL_FIELDS.map((field) => (
              <label className="field" key={field.key}>
                <span className="field__label">{field.label}</span>
                <input
                  className="input"
                  type={field.type === 'color' ? 'text' : field.type ?? 'text'}
                  placeholder={field.type === 'color' ? '#1F4E5F' : undefined}
                  value={settings[field.key] ?? ''}
                  onChange={(event) => setSettings((previous) => ({ ...previous, [field.key]: event.target.value }))}
                />
                {field.hint && <span className="field__hint small faint">{field.hint}</span>}
              </label>
            ))}
          </div>
          <div className="row">
            <button type="button" className="btn btn--sm" disabled={saveDetails.isPending} onClick={() => saveDetails.mutate()}>
              {saveDetails.isPending ? 'Saving…' : 'Save details'}
            </button>
            {saveDetails.error !== null && (
              <span className="field__error small" role="alert">{errorText(saveDetails.error, 'The details could not be saved.')}</span>
            )}
          </div>
        </div>

        <div className="stack">
          <h3>Images</h3>
          <div className="grid grid--two">
            {(['logo', 'hero'] as const).map((kind) => (
              <label className="field" key={kind}>
                <span className="field__label">{kind === 'logo' ? 'Logo' : 'Home page photo'}</span>
                {website.settings[`${kind}_url`] && (
                  <img src={website.settings[`${kind}_url`] ?? ''} alt="" style={{ maxHeight: 80, maxWidth: 240, objectFit: 'contain' }} />
                )}
                <input
                  type="file"
                  accept="image/png,image/jpeg,image/webp,image/svg+xml"
                  onChange={(event) => {
                    const file = event.target.files?.[0]
                    if (file) upload.mutate({ kind, file })
                  }}
                />
              </label>
            ))}
          </div>
          {upload.error !== null && <p className="field__error small" role="alert">{errorText(upload.error, 'The image could not be uploaded.')}</p>}
        </div>

        <section className="stack" aria-label="Properties on this site">
          <h3>Properties</h3>
          <p className="small faint">
            {website.kind === 'brand'
              ? 'Properties from every client account. Ticked ones appear on the site in this order; featured ones lead the home page.'
              : `Properties of ${website.organization_name ?? 'this client'}. Ticked ones appear on the site in this order; featured ones lead the home page.`}
            {' '}A property has to be published before the site can show it.
          </p>
          {gone.length > 0 && (
            <p className="notice small" role="status">
              {gone.length === 1 ? '1 property is' : `${gone.length} properties are`} no longer published and will be removed from
              the site when you save.
            </p>
          )}
          <QueryState
            isLoading={options.isLoading}
            error={options.error}
            isEmpty={ordered.length === 0}
            emptyTitle="No properties yet"
            emptyBody="Add a property on the Properties screen, then come back to put it on the site."
          >
            <ul className="stack stack--tight" style={{ listStyle: 'none', padding: 0, margin: 0 }}>
              {ordered.map((option) => {
                const row = chosen.find((candidate) => candidate.listing_id === option.listing_id)

                if (!option.ready) {
                  return (
                    <li key={option.listing_id} className="row row--between">
                      <span>
                        <span className="strong">{option.title}</span>
                        <span className="small faint"> · {[option.organization_name, option.city].filter(Boolean).join(' · ')}</span>
                        <span className="small muted" style={{ display: 'block' }}>
                          {option.needs.join(' ')}
                          {!option.can_publish && ' Finish it on the Properties screen, then publish it here.'}
                        </span>
                      </span>
                      {option.can_publish && (
                        <button
                          type="button"
                          className="btn btn--sm"
                          disabled={publishListing.isPending}
                          onClick={() => publishListing.mutate(option.listing_id)}
                          aria-label={`Publish ${option.title}`}
                        >
                          {option.property_active ? 'Publish' : 'Activate and publish'}
                        </button>
                      )}
                    </li>
                  )
                }

                return (
                  <li key={option.listing_id} className="row row--between">
                    <label className="row">
                      <input type="checkbox" checked={row !== undefined} onChange={() => toggle(option.listing_id)} aria-label={`Show ${option.title}`} />
                      <span>
                        <span className="strong">{option.title}</span>
                        <span className="small faint"> · {[option.organization_name, option.city].filter(Boolean).join(' · ')}</span>
                      </span>
                    </label>
                    {row !== undefined && (
                      <span className="row">
                        <label className="row small">
                          <input type="checkbox" checked={row.is_featured} onChange={() => feature(option.listing_id)} aria-label={`Feature ${option.title}`} />
                          Featured
                        </label>
                        <button type="button" className="btn btn--ghost btn--sm" onClick={() => moveUp(option.listing_id)} aria-label={`Move ${option.title} up`}>
                          <ArrowUp size={14} aria-hidden />
                        </button>
                      </span>
                    )}
                  </li>
                )
              })}
            </ul>
          </QueryState>
          {publishListing.error !== null && (
            <p className="field__error small" role="alert">{errorText(publishListing.error, 'The property could not be published.')}</p>
          )}
          <div className="row">
            <button type="button" className="btn btn--sm btn--primary" disabled={saveListings.isPending} onClick={() => saveListings.mutate()}>
              {saveListings.isPending ? 'Saving…' : 'Save properties'}
            </button>
            {saveListings.error !== null && (
              <span className="field__error small" role="alert">{errorText(saveListings.error, 'The properties could not be saved.')}</span>
            )}
          </div>
        </section>
      </div>
    </section>
  )
}
