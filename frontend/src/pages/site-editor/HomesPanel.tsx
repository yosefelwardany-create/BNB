import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowUp } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type { Website, WebsiteListingOption } from '@/api/types'
import { QueryState } from '@/components/QueryState'

/**
 * Which homes the site shows, in what order, and which lead its home page.
 *
 * Every home of the account is listed, not only those already on sale: one
 * still to be published says what it needs, and the platform can publish it
 * from here when that is all it lacks.
 */
export function HomesPanel({
  website,
  sitePath = `platform/websites/${website.id}`,
  who = 'platform',
  onSaved,
}: {
  website: Website
  /** The site on the API: the platform's, or the client portal's. */
  sitePath?: string
  /** A client sees what a home still needs; only the platform puts one on sale. */
  who?: 'platform' | 'client'
  onSaved: (website: Website) => void
}) {
  const queryClient = useQueryClient()
  const [chosen, setChosen] = useState(() =>
    website.listings.map((row) => ({ listing_id: row.listing_id, is_featured: row.is_featured })),
  )

  const options = useQuery({
    queryKey: who === 'client'
      ? ['owner-website-listing-options', sitePath]
      : ['platform-website-listing-options', website.kind === 'client' ? website.organization_id : 'all'],
    queryFn: () =>
      who === 'client'
        ? api.get<{ data: WebsiteListingOption[] }>(`${sitePath}/listing-options`)
        : api.get<{ data: WebsiteListingOption[] }>(
            'platform/website-listing-options',
            website.kind === 'client' && website.organization_id !== null ? { organization_id: website.organization_id } : undefined,
          ),
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
      api.put<{ data: Website }>(`${sitePath}/listings`, {
        listings: chosen.filter((row) => offered(row.listing_id)),
      }),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['platform-websites'] })
      onSaved(result.data)
    },
  })

  // Put a home on sale from here, when that is all it lacks.
  const publishListing = useMutation({
    mutationFn: (listingId: string) => api.post<{ data: WebsiteListingOption }>(`platform/website-listing-options/${listingId}/publish`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['platform-website-listing-options'] }),
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
    <section className="editor-panel stack" aria-label="Properties on this site">
      <div className="editor-panel__head">
        <h3>Homes on this site</h3>
      </div>
      <p className="small faint">
        {website.kind === 'brand'
          ? 'Properties from every client account. Ticked ones appear on the site in this order; featured ones lead the home page.'
          : `Properties of ${website.organization_name ?? 'this client'}. Ticked ones appear on the site in this order; featured ones lead the home page.`}
        {' '}A property has to be published before the site can show it. Saved straight away.
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
        emptyBody={who === 'client'
          ? 'Your homes appear here once the management company adds them.'
          : 'Add a property on the Properties screen, then come back to put it on the site.'}
      >
        <ul className="stack stack--tight" style={{ listStyle: 'none', padding: 0, margin: 0 }}>
          {ordered.map((option) => {
            const row = chosen.find((candidate) => candidate.listing_id === option.listing_id)

            if (!option.ready) {
              return (
                <li key={option.listing_id} className="home-option">
                  <span>
                    <span className="strong">{option.title}</span>
                    <span className="small faint"> · {[option.organization_name, option.city].filter(Boolean).join(' · ')}</span>
                    <span className="small muted" style={{ display: 'block' }}>
                      {option.needs.join(' ')}
                      {who === 'client'
                        ? ' The management company puts it on sale once it is ready.'
                        : !option.can_publish && ' Finish it on the Properties screen, then publish it here.'}
                    </span>
                  </span>
                  {who === 'platform' && option.can_publish && (
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
              <li key={option.listing_id} className="home-option">
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
          {saveListings.isPending ? 'Saving…' : 'Save homes'}
        </button>
        {saveListings.isSuccess && <span className="small faint" role="status">Saved.</span>}
        {saveListings.error !== null && (
          <span className="field__error small" role="alert">{errorText(saveListings.error, 'The properties could not be saved.')}</span>
        )}
      </div>
    </section>
  )
}
