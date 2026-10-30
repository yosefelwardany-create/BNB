import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { Website } from '@/api/types'

const FIELDS: { key: keyof Website['settings']; label: string; type: string; hint?: string }[] = [
  { key: 'contact_email', label: 'Contact email', type: 'email', hint: 'Booking and request emails go here, and guests reply to it.' },
  { key: 'contact_phone', label: 'Contact phone', type: 'tel' },
  { key: 'address_line', label: 'Address shown in the footer', type: 'text' },
  { key: 'instagram_url', label: 'Instagram link', type: 'url' },
  { key: 'facebook_url', label: 'Facebook link', type: 'url' },
]

/**
 * The site's name, contact details, social links and logo. Used in emails and
 * on every page, so they are saved straight away rather than with the draft.
 */
export function SiteDetailsPanel({
  website,
  sitePath = `platform/websites/${website.id}`,
  who = 'platform',
  onSaved,
}: {
  website: Website
  /** The site on the API: the platform's, or the client portal's. */
  sitePath?: string
  /** A client's logo is a photo file; only the platform's may be an SVG. */
  who?: 'platform' | 'client'
  onSaved: (website: Website) => void
}) {
  const queryClient = useQueryClient()
  const [name, setName] = useState(website.name)
  const [settings, setSettings] = useState<Record<string, string>>(() =>
    Object.fromEntries(FIELDS.map((field) => [field.key, String(website.settings[field.key] ?? '')])),
  )

  const done = (result: { data: Website }) => {
    void queryClient.invalidateQueries({ queryKey: ['platform-websites'] })
    onSaved(result.data)
  }

  const save = useMutation({
    mutationFn: () =>
      api.patch<{ data: Website }>(sitePath, {
        name,
        settings: Object.fromEntries(Object.entries(settings).map(([key, value]) => [key, value.trim() === '' ? null : value.trim()])),
      }),
    onSuccess: done,
  })

  const upload = useMutation({
    mutationFn: (file: File) => {
      const form = new FormData()
      form.append('kind', 'logo')
      form.append('file', file)

      return api.post<{ data: Website }>(`${sitePath}/media`, form)
    },
    onSuccess: done,
  })

  const apiError = save.error instanceof ApiError ? save.error : null

  return (
    <section className="editor-panel" aria-label="Site details">
      <div className="editor-panel__head">
        <h3>Site details</h3>
      </div>
      <p className="small faint">Used in emails and on every page. Saved straight away, not with the draft.</p>

      <label className="field">
        <span className="field__label">Site name</span>
        <input className="input" value={name} maxLength={120} onChange={(event) => setName(event.target.value)} />
        {apiError?.fieldError('name') !== undefined && <span className="field__error">{apiError.fieldError('name')}</span>}
      </label>

      {FIELDS.map((field) => (
        <div className="field" key={field.key}>
          <label className="field__label" htmlFor={`site-${field.key}`}>{field.label}</label>
          <input
            id={`site-${field.key}`}
            className="input"
            type={field.type}
            value={settings[field.key] ?? ''}
            onChange={(event) => setSettings((previous) => ({ ...previous, [field.key]: event.target.value }))}
          />
          {field.hint !== undefined && <span className="field__hint">{field.hint}</span>}
          {apiError?.fieldError(`settings.${field.key}`) !== undefined && <span className="field__error">{apiError.fieldError(`settings.${field.key}`)}</span>}
        </div>
      ))}

      <div className="row">
        <button type="button" className="btn btn--sm btn--primary" disabled={save.isPending} onClick={() => save.mutate()}>
          {save.isPending ? 'Saving…' : 'Save details'}
        </button>
        {save.isSuccess && <span className="small faint" role="status">Saved.</span>}
      </div>

      <div className="field">
        <span className="field__label">Logo</span>
        {website.settings.logo_url && <img src={website.settings.logo_url} alt="" className="logo-preview" />}
        <input
          type="file"
          accept={who === 'client' ? 'image/png,image/jpeg,image/webp' : 'image/png,image/jpeg,image/webp,image/svg+xml'}
          aria-label="Upload a logo"
          onChange={(event) => {
            const file = event.target.files?.[0]
            if (file) upload.mutate(file)
          }}
        />
        <span className="field__hint">Shown at the top of every page, in place of the site's name.</span>
        {upload.error !== null && <span className="field__error" role="alert">{upload.error instanceof ApiError ? upload.error.message : 'The logo could not be uploaded.'}</span>}
      </div>
    </section>
  )
}
