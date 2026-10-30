import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ExternalLink, PencilLine } from 'lucide-react'
import { api } from '@/api/client'
import type { Website } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { SiteEditor } from '@/pages/site-editor/SiteEditor'

/**
 * The client's own website: what each of their sites is, whether it is
 * online, and the way into its editor.
 *
 * The one place in the portal where a client changes something. The site's
 * address, its booking rules and whether it is online stay with the
 * management company, so none of them is offered here.
 *
 * With `preview`, the platform owner is looking through the client's eyes;
 * the page says where the site is edited instead of opening it.
 */
export function OwnerWebsitePage({ preview = false }: { preview?: boolean }) {
  const sites = useQuery({
    queryKey: ['owner-websites'],
    queryFn: () => api.get<{ data: Website[] }>('portal/owner/websites'),
  })

  const rows = sites.data?.data ?? []

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Website</h1>
          <div className="page-header__subtitle">Your booking website: change its pages, its look and its homes, and publish when it is ready</div>
        </div>
      </div>

      <QueryState
        isLoading={sites.isLoading}
        error={sites.error}
        isEmpty={rows.length === 0}
        emptyTitle="No website yet"
        emptyBody="The management company sets up your booking website. Once it has, it is here to edit."
      >
        <div className="stack">
          {rows.map((site) => (
            <article key={site.id} className="card" aria-label={site.name}>
              <header className="card__header">
                <h2>{site.name}</h2>
                <span className="row">
                  {site.status === 'live' ? <Chip label="Online" colour="emerald" /> : <Chip label="Not online yet" colour="slate" />}
                  {site.unpublished_changes === true && <Chip label="Changes not published yet" colour="amber" />}
                </span>
              </header>
              <div className="card__body stack stack--tight">
                <a href={site.public_url} target="_blank" rel="noreferrer" className="row small">
                  <ExternalLink size={14} aria-hidden /> {site.public_url}
                </a>
                {preview ? (
                  <p className="small muted">This is the client’s page. Edit it from the Websites screen.</p>
                ) : (
                  <div>
                    <Link to={`/website/${site.id}/edit`} className="btn btn--primary btn--sm" aria-label={`Edit ${site.name}`}>
                      <PencilLine size={14} aria-hidden /> Edit site
                    </Link>
                  </div>
                )}
              </div>
            </article>
          ))}
        </div>
      </QueryState>
    </>
  )
}

/** A client's own site in the page builder, at /website/:websiteId/edit. */
export function OwnerSiteEditorPage() {
  const { websiteId = '' } = useParams()

  return (
    <SiteEditor
      apiBase={`portal/owner/websites/${websiteId}/editor`}
      sitePath={`portal/owner/websites/${websiteId}`}
      backTo="/website"
      who="client"
    />
  )
}
