import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { Website } from '@/api/types'

/**
 * The site's pieces on another website, such as a client's Shopify store:
 * the store's addresses, which are the only ones allowed to show them, and
 * the tags to paste into the store. The platform's to set; a client never
 * sees it.
 */
export function StoreSection({ website, sitePath, onSaved }: { website: Website; sitePath: string; onSaved: (website: Website) => void }) {
  const queryClient = useQueryClient()
  const [addresses, setAddresses] = useState(() => (website.embed_origins ?? []).join('\n'))
  const [copied, setCopied] = useState<string | null>(null)

  const lines = addresses.split('\n').map((line) => line.trim()).filter((line) => line !== '')

  const save = useMutation({
    mutationFn: () => api.patch<{ data: Website }>(sitePath, { embed_origins: lines }),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['platform-websites'] })
      onSaved(result.data)
    },
  })

  const apiError = save.error instanceof ApiError ? save.error : null
  const problems = lines
    .map((line, index) => ({ line, message: apiError?.fieldError(`embed_origins.${index}`) }))
    .filter((problem): problem is { line: string; message: string } => problem.message !== undefined)

  const copy = (key: string, text: string) => {
    void navigator.clipboard.writeText(text).then(() => setCopied(key))
  }

  const snippets = website.embed
    ? [
        { key: 'script', name: 'the script', label: 'The script: add it once to the store’s theme, before </body>', text: website.embed.script },
        { key: 'search', name: 'the search bar', label: 'Search bar', text: website.embed.search },
        { key: 'homes', name: 'the homes grid', label: 'Homes grid', text: website.embed.homes },
        ...website.embed.book.map((home) => ({ key: `book-${home.listing_id}`, name: `the booking box for ${home.title}`, label: `Booking box: ${home.title}`, text: home.tag })),
      ]
    : []

  return (
    <section className="stack" aria-label="On other websites">
      <h4>On other websites</h4>
      <p className="small faint">
        Show this site’s search, homes and booking boxes on the client’s own store, such as a Shopify store. Only the addresses
        listed here can show them. Guests book and pay here as on the site.
      </p>

      <div className="field">
        <label className="field__label" htmlFor="site-embed-origins">Store addresses (one per line)</label>
        <textarea
          id="site-embed-origins"
          className="input"
          rows={3}
          placeholder={'https://harbour-homes.myshopify.com\nhttps://www.harbourhomes.com'}
          value={addresses}
          onChange={(event) => setAddresses(event.target.value)}
        />
        <span className="field__hint">Just the address, starting with https://, with nothing after it.</span>
        {problems.map((problem) => (
          <span key={problem.line} className="field__error">{problem.line}: {problem.message}</span>
        ))}
        {apiError?.fieldError('embed_origins') !== undefined && <span className="field__error">{apiError.fieldError('embed_origins')}</span>}
      </div>

      <div className="row">
        <button type="button" className="btn btn--sm btn--primary" disabled={save.isPending} onClick={() => save.mutate()}>
          {save.isPending ? 'Saving…' : 'Save addresses'}
        </button>
        {save.isSuccess && <span className="small faint" role="status">Saved.</span>}
        {save.error !== null && apiError?.status !== 422 && (
          <span className="field__error small" role="alert">{apiError?.message ?? 'The addresses could not be saved.'}</span>
        )}
      </div>

      {snippets.map((snippet) => (
        <div className="field" key={snippet.key}>
          <span className="field__label">{snippet.label}</span>
          <code className="snippet">{snippet.text}</code>
          <div className="row">
            <button
              type="button"
              className="btn btn--ghost btn--sm"
              aria-label={copied === snippet.key ? `Copied ${snippet.name}` : `Copy ${snippet.name}`}
              onClick={() => copy(snippet.key, snippet.text)}
            >
              {copied === snippet.key ? 'Copied' : 'Copy'}
            </button>
          </div>
        </div>
      ))}
    </section>
  )
}
