import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { Paginated, PlatformAnnouncement } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { formatDate } from '@/lib/format'

const LEVELS = ['info', 'warning', 'critical']
const AUDIENCES = ['all', 'trial', 'active', 'past_due']

/**
 * Notices shown inside affected tenants' interfaces.
 *
 * In the product rather than by email, because the people who need to know the
 * channel sync will be down on Sunday are the people looking at the channel
 * screen, not whoever reads the billing inbox.
 */
export function PlatformAnnouncementsPage() {
  const queryClient = useQueryClient()

  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [level, setLevel] = useState('info')
  const [audience, setAudience] = useState('all')
  const [publish, setPublish] = useState(true)

  const list = useQuery({
    queryKey: ['platform-announcements'],
    queryFn: () =>
      api.get<Paginated<PlatformAnnouncement>>('platform/announcements', { per_page: 50 }),
  })

  const create = useMutation({
    mutationFn: () =>
      api.post('platform/announcements', {
        title,
        body,
        level,
        audience,
        is_published: publish,
        // A critical notice about downtime should not be dismissable; anything
        // gentler should.
        is_dismissible: level !== 'critical',
      }),
    onSuccess: () => {
      setTitle('')
      setBody('')
      void queryClient.invalidateQueries({ queryKey: ['platform-announcements'] })
    },
  })

  const toggle = useMutation({
    mutationFn: (announcement: PlatformAnnouncement) =>
      api.patch(`platform/announcements/${announcement.id}`, {
        is_published: !announcement.is_published,
      }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['platform-announcements'] }),
  })

  const withdraw = useMutation({
    mutationFn: (announcement: PlatformAnnouncement) =>
      api.delete(`platform/announcements/${announcement.id}`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['platform-announcements'] }),
  })

  const failed = create.error ?? toggle.error ?? withdraw.error

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Announcements</h1>
          <div className="page-header__subtitle">
            {list.data ? `${list.data.meta.total} notice(s)` : 'Loading…'}
          </div>
        </div>
      </div>

      {failed !== null && failed !== undefined && (
        <div className="notice notice--error" role="alert">
          {failed instanceof ApiError ? failed.message : 'That action could not be completed.'}
        </div>
      )}

      <section className="card mb-3">
        <header className="card__header">
          <h2>New notice</h2>
        </header>

        <form
          className="card__body"
          onSubmit={(event) => {
            event.preventDefault()
            if (title.trim() !== '' && body.trim() !== '') create.mutate()
          }}
        >
          <div className="field">
            <label className="field__label" htmlFor="ann-title">
              Title
            </label>
            <input
              id="ann-title"
              type="text"
              value={title}
              onChange={(event) => setTitle(event.target.value)}
            />
          </div>

          <div className="field">
            <label className="field__label" htmlFor="ann-body">
              Body
            </label>
            <textarea
              id="ann-body"
              rows={3}
              value={body}
              onChange={(event) => setBody(event.target.value)}
            />
          </div>

          <div className="grid grid--two">
            <div className="field">
              <label className="field__label" htmlFor="ann-level">
                Level
              </label>
              <select
                id="ann-level"
                value={level}
                onChange={(event) => setLevel(event.target.value)}
              >
                {LEVELS.map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </select>
              <span className="field__hint">
                A critical notice cannot be dismissed by the reader.
              </span>
            </div>

            <div className="field">
              <label className="field__label" htmlFor="ann-audience">
                Audience
              </label>
              <select
                id="ann-audience"
                value={audience}
                onChange={(event) => setAudience(event.target.value)}
              >
                {AUDIENCES.map((option) => (
                  <option key={option} value={option}>
                    {option.replace('_', ' ')}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <label className="row small">
            <input
              type="checkbox"
              checked={publish}
              onChange={(event) => setPublish(event.target.checked)}
            />
            Publish immediately
          </label>

          <button
            type="submit"
            className="btn btn--primary mt-3"
            disabled={create.isPending || title.trim() === '' || body.trim() === ''}
          >
            {create.isPending ? 'Posting…' : 'Post notice'}
          </button>
        </form>
      </section>

      <QueryState
        isLoading={list.isLoading}
        error={list.error}
        isEmpty={(list.data?.data.length ?? 0) === 0}
        emptyTitle="No notices"
      >
        <section className="card">
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Notice</th>
                  <th>Audience</th>
                  <th>State</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {(list.data?.data ?? []).map((announcement) => (
                  <tr key={announcement.id}>
                    <td>
                      <div className="strong">{announcement.title}</div>
                      <div className="small faint wrap">{announcement.body}</div>
                    </td>

                    <td className="small">
                      {announcement.audience === 'specific'
                        ? `${announcement.organization_ids.length} named`
                        : announcement.audience.replace('_', ' ')}
                    </td>

                    <td>
                      <div className="row wrap">
                        <Chip
                          label={announcement.level}
                          colour={
                            announcement.level === 'critical'
                              ? 'rose'
                              : announcement.level === 'warning'
                                ? 'amber'
                                : 'sky'
                          }
                        />
                        {/* Published and live are different: a scheduled notice
                            is published and not yet showing. */}
                        {announcement.is_live ? (
                          <Chip label="Showing now" colour="emerald" />
                        ) : announcement.is_published ? (
                          <Chip label="Published, outside window" colour="slate" />
                        ) : (
                          <Chip label="Draft" colour="zinc" />
                        )}
                      </div>
                      {announcement.ends_at !== null && (
                        <div className="small faint mt-1">
                          Until {formatDate(announcement.ends_at)}
                        </div>
                      )}
                    </td>

                    <td>
                      <div className="row">
                        <button
                          type="button"
                          className="btn btn--ghost btn--sm"
                          onClick={() => toggle.mutate(announcement)}
                          disabled={toggle.isPending}
                        >
                          {announcement.is_published ? 'Unpublish' : 'Publish'}
                        </button>
                        <button
                          type="button"
                          className="btn btn--danger btn--sm"
                          onClick={() => withdraw.mutate(announcement)}
                          disabled={withdraw.isPending}
                        >
                          Withdraw
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </QueryState>
    </>
  )
}
