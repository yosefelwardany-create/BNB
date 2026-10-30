import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ExternalLink, Globe, PencilRuler, Plus } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type { Paginated, Website } from '@/api/types'
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
            <span className="small faint">Booking sites guests find and book on. Build their pages in the page builder; each shows only the homes you choose.</span>
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
                      <span className="row">
                        <Link className="btn btn--sm" to={`/websites/${website.id}/edit`} aria-label={`Edit ${website.name} in the page builder`}>
                          <PencilRuler size={14} aria-hidden /> Edit site
                        </Link>
                        <button type="button" className="btn btn--ghost btn--sm" aria-label={`Settings for ${website.name}`} onClick={() => setSelectedId(website.id)}>
                          Settings
                        </button>
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </QueryState>
      </section>

      {selected !== null && <WebsiteSettings key={selected.id} website={selected} />}

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

/**
 * One site's address and whether it is online. Everything guests see —
 * pages, look, photos, homes and contact details — is in the page builder.
 */
function WebsiteSettings({ website }: { website: Website }) {
  const queryClient = useQueryClient()
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['platform-websites'] })

  const [name, setName] = useState(website.name)
  const [slug, setSlug] = useState(website.slug)

  const save = useMutation({
    mutationFn: () => api.patch<{ data: Website }>(`platform/websites/${website.id}`, { name, slug }),
    onSuccess: refresh,
  })

  const publication = useMutation({
    mutationFn: (live: boolean) => api.post<{ data: Website }>(`platform/websites/${website.id}/${live ? 'publish' : 'unpublish'}`),
    onSuccess: refresh,
  })

  const errorText = (error: unknown, fallback: string) => (error instanceof ApiError ? error.message : fallback)
  const live = website.status === 'live'

  return (
    <section className="card mb-3" aria-label={`Settings for ${website.name}`}>
      <header className="card__header row row--between">
        <div className="row">
          <Globe size={18} aria-hidden />
          <h2>{website.name}</h2>
          <Chip label={live ? 'Online' : 'Offline'} colour={live ? 'emerald' : 'zinc'} />
        </div>
        <div className="row">
          <a className="btn btn--ghost btn--sm" href={website.public_url} target="_blank" rel="noreferrer">
            <ExternalLink size={14} aria-hidden /> View site
          </a>
          <Link className="btn btn--sm" to={`/websites/${website.id}/edit`}>
            <PencilRuler size={14} aria-hidden /> Edit site
          </Link>
          <button
            type="button"
            className={live ? 'btn btn--ghost btn--sm' : 'btn btn--primary btn--sm'}
            disabled={publication.isPending}
            onClick={() => publication.mutate(!live)}
          >
            {live ? 'Take offline' : 'Go live'}
          </button>
        </div>
      </header>

      <div className="card__body stack">
        {publication.error !== null && (
          <p className="notice notice--error" role="alert">{errorText(publication.error, 'The site could not be put online.')}</p>
        )}

        {website.unpublished_changes === true && (
          <p className="notice notice--info small" role="status">
            The page builder has changes guests do not see yet. Publish them from the page builder.
          </p>
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
          <h3>Name and address</h3>
          <div className="grid grid--two">
            <label className="field">
              <span className="field__label">Name</span>
              <input className="input" value={name} onChange={(event) => setName(event.target.value)} />
            </label>
            <label className="field">
              <span className="field__label">Address</span>
              <input className="input" value={slug} onChange={(event) => setSlug(event.target.value)} />
            </label>
          </div>
          <div className="row">
            <button type="button" className="btn btn--sm" disabled={save.isPending} onClick={() => save.mutate()}>
              {save.isPending ? 'Saving…' : 'Save'}
            </button>
            {save.error !== null && <span className="field__error small" role="alert">{errorText(save.error, 'The site could not be saved.')}</span>}
          </div>
        </div>
      </div>
    </section>
  )
}
