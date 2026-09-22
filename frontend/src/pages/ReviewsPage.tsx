import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { Paginated, Review, ReviewSummary } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { formatDate, formatNumber, formatPercent } from '@/lib/format'
import { useAuth } from '@/lib/auth'

const VIEWS = [
  { value: 'awaiting', label: 'Awaiting a reply' },
  { value: 'negative', label: 'Negative' },
  { value: 'all', label: 'All' },
  { value: 'hidden', label: 'Including hidden' },
]

/**
 * Reviews.
 *
 * Ratings arrive on different scales — one channel rates out of five, another
 * out of ten — so the raw score is shown with its own scale beside the
 * normalised percentage rather than being silently rescaled into a single
 * number nobody can check.
 */
export function ReviewsPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()

  const [view, setView] = useState('awaiting')
  const [page, setPage] = useState(1)
  const [replyingTo, setReplyingTo] = useState<string | null>(null)
  const [draft, setDraft] = useState('')

  const filters = {
    awaiting_response: view === 'awaiting' ? true : undefined,
    negative_only: view === 'negative' ? true : undefined,
    include_hidden: view === 'hidden' ? true : undefined,
  }

  const list = useQuery({
    queryKey: ['reviews', { view, page }],
    queryFn: () =>
      api.get<Paginated<Review>>('reviews', { ...filters, page, per_page: 25 }),
    placeholderData: keepPreviousData,
  })

  const summary = useQuery({
    queryKey: ['reviews-summary'],
    queryFn: () =>
      api.get<{ data: ReviewSummary; meta: { note: string } }>('reviews/summary'),
  })

  const respond = useMutation({
    mutationFn: ({ review, response }: { review: Review; response: string }) =>
      api.post(`reviews/${review.id}/response`, { response }),
    onSuccess: () => {
      setReplyingTo(null)
      setDraft('')
      void queryClient.invalidateQueries({ queryKey: ['reviews'] })
      void queryClient.invalidateQueries({ queryKey: ['reviews-summary'] })
    },
  })

  const visibility = useMutation({
    mutationFn: ({ review, hide }: { review: Review; hide: boolean }) =>
      api.post(`reviews/${review.id}/${hide ? 'hide' : 'unhide'}`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['reviews'] }),
  })

  const reviews = list.data?.data ?? []
  const meta = list.data?.meta
  const figures = summary.data?.data
  const failed = respond.error ?? visibility.error

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Reviews</h1>
          <div className="page-header__subtitle">
            {figures
              ? `${formatNumber(figures.reviews)} review(s) · ${formatNumber(
                  figures.awaiting_response,
                )} awaiting a reply`
              : 'Loading…'}
          </div>
        </div>

        <div className="row">
          {VIEWS.map((option) => (
            <button
              key={option.value}
              type="button"
              className={view === option.value ? 'btn btn--sm' : 'btn btn--ghost btn--sm'}
              onClick={() => {
                setView(option.value)
                setPage(1)
              }}
            >
              {option.label}
            </button>
          ))}
        </div>
      </div>

      <QueryState isLoading={summary.isLoading} error={summary.error}>
        <div className="grid grid--stats mb-3">
          <div className="card card__body">
            <div className="stat__label">Average</div>
            <div className="stat__value">
              {figures?.average_out_of_five === null || figures === undefined
                ? '—'
                : `${figures.average_out_of_five.toFixed(2)} / 5`}
            </div>
            {/* The caveat the server sends, shown rather than dropped: an
                average across channels that rate out of 5 and out of 10 means
                nothing unless the reader knows it was normalised first. */}
            <div className="stat__meta">{summary.data?.meta.note}</div>
          </div>

          <div className="card card__body">
            <div className="stat__label">Response rate</div>
            <div className="stat__value">
              {figures ? formatPercent(figures.response_rate) : '—'}
            </div>
            <div className="stat__meta">
              {figures ? `${formatNumber(figures.responded)} answered` : ''}
            </div>
          </div>

          <div className="card card__body">
            <div className="stat__label">Negative</div>
            <div className="stat__value">
              {figures ? formatNumber(figures.negative) : '—'}
            </div>
            <div className="stat__meta">Scored below the acceptable threshold</div>
          </div>
        </div>
      </QueryState>

      {failed !== null && failed !== undefined && (
        <div className="notice notice--error" role="alert">
          {failed instanceof ApiError ? failed.message : 'That action could not be completed.'}
        </div>
      )}

      <QueryState
        isLoading={list.isLoading}
        error={list.error}
        isEmpty={reviews.length === 0}
        emptyTitle="Nothing here"
        emptyBody="No reviews match this view."
      >
        <div className="stack">
          {reviews.map((review) => (
            <article key={review.id} className="card card__body">
              <div className="row row--between">
                <div className="row">
                  <span className="strong">{review.guest?.display_name ?? 'Guest'}</span>
                  <Chip label={review.source} colour="slate" />
                  {review.is_negative && <Chip label="Negative" colour="rose" />}
                  {review.is_hidden_internally && (
                    // Said exactly: hiding it here does nothing to the copy
                    // the public can still read on the channel.
                    <Chip label="Hidden here, still public there" colour="amber" />
                  )}
                </div>

                <div className="row small faint">
                  {review.rating !== null && (
                    <span className="strong">
                      {review.rating} / {review.rating_scale}
                    </span>
                  )}
                  {review.rating_percent !== null && (
                    <span>({formatPercent(review.rating_percent)})</span>
                  )}
                  <span>{formatDate(review.submitted_at)}</span>
                </div>
              </div>

              <div className="small faint">{review.property?.name ?? '—'}</div>

              {review.title !== null && <div className="strong mt-2">{review.title}</div>}

              {review.public_comment !== null && (
                <p className="wrap">{review.public_comment}</p>
              )}

              {review.private_comment !== null && (
                <div className="panel">
                  <div className="small strong">Private to us</div>
                  <p className="wrap small">{review.private_comment}</p>
                </div>
              )}

              {review.has_response ? (
                <div className="panel">
                  <div className="row row--between small">
                    <span className="strong">Our reply</span>
                    <span className="faint">
                      {review.response_hours !== null &&
                        `after ${Math.round(review.response_hours)}h`}
                    </span>
                  </div>
                  <p className="wrap small">{review.response}</p>
                </div>
              ) : (
                can('reviews.respond') &&
                (replyingTo === review.id ? (
                  <form
                    className="mt-2"
                    onSubmit={(event) => {
                      event.preventDefault()
                      if (draft.trim() !== '') {
                        respond.mutate({ review, response: draft })
                      }
                    }}
                  >
                    <textarea
                      rows={3}
                      value={draft}
                      placeholder="Your public reply…"
                      onChange={(event) => setDraft(event.target.value)}
                    />

                    <div className="row row--between mt-2">
                      <span className="small faint">
                        {/* A reply cannot be taken back once it is on the
                            channel, so the consequence is stated before the
                            button rather than discovered after it. */}
                        This is published under your property&rsquo;s name and cannot be
                        edited afterwards.
                      </span>

                      <div className="row">
                        <button
                          type="button"
                          className="btn btn--ghost btn--sm"
                          onClick={() => {
                            setReplyingTo(null)
                            setDraft('')
                          }}
                        >
                          Cancel
                        </button>
                        <button
                          type="submit"
                          className="btn btn--sm"
                          disabled={respond.isPending || draft.trim() === ''}
                        >
                          {respond.isPending ? 'Publishing…' : 'Publish reply'}
                        </button>
                      </div>
                    </div>
                  </form>
                ) : (
                  <div className="row mt-2">
                    <button
                      type="button"
                      className="btn btn--sm"
                      onClick={() => {
                        setReplyingTo(review.id)
                        setDraft('')
                      }}
                    >
                      Reply
                    </button>

                    <button
                      type="button"
                      className="btn btn--ghost btn--sm"
                      onClick={() =>
                        visibility.mutate({
                          review,
                          hide: !review.is_hidden_internally,
                        })
                      }
                      disabled={visibility.isPending}
                    >
                      {review.is_hidden_internally ? 'Unhide' : 'Hide from our lists'}
                    </button>
                  </div>
                ))
              )}
            </article>
          ))}
        </div>

        {meta !== undefined && meta.last_page > 1 && (
          <div className="row row--between mt-3">
            <span className="small faint">
              Page {meta.current_page} of {meta.last_page}
            </span>
            <div className="row">
              <button
                type="button"
                className="btn btn--ghost btn--sm"
                disabled={meta.current_page <= 1}
                onClick={() => setPage((current) => current - 1)}
              >
                Previous
              </button>
              <button
                type="button"
                className="btn btn--ghost btn--sm"
                disabled={meta.current_page >= meta.last_page}
                onClick={() => setPage((current) => current + 1)}
              >
                Next
              </button>
            </div>
          </div>
        )}
      </QueryState>
    </>
  )
}
