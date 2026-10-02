import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BookOpen, RefreshCw, ShieldAlert } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type { PropertyDocument } from '@/api/types'
import { Chip } from '@/components/Chip'

/**
 * What the agent has actually read.
 *
 * The link used to be a link: a person could open it and the agent could not.
 * Now it is fetched and stored, which introduces two things somebody has to be
 * able to see — whether the read worked, and whether the document reaches
 * guests.
 *
 * The second is the one with consequences. A house manual routinely contains a
 * door code, and the platform's whole entitlement design is that arrival details
 * reach only a guest with a confirmed, paid booking inside its window. So
 * sharing is off by default, it is its own switch rather than a field in a form
 * somebody saves without reading, and when the document demonstrably contains
 * one of those secrets the screen says so before the switch is touched.
 */
const STATUS: Record<
  PropertyDocument['status'],
  { label: string; colour: 'emerald' | 'amber' | 'rose' | 'slate' }
> = {
  ok: { label: 'Read', colour: 'emerald' },
  pending: { label: 'Not read yet', colour: 'slate' },
  forbidden: { label: 'Not shared', colour: 'rose' },
  unreachable: { label: 'Could not reach it', colour: 'rose' },
  empty: { label: 'Nothing in it', colour: 'amber' },
  refused: { label: 'Refused', colour: 'rose' },
}

export function AgentKnowledge({
  propertyId,
  mayEdit,
}: {
  propertyId: string
  mayEdit: boolean
}) {
  const queryClient = useQueryClient()

  const documents = useQuery({
    queryKey: ['agent-documents', propertyId],
    queryFn: () => api.get<{ data: PropertyDocument[] }>(`properties/${propertyId}/agent/documents`),
  })

  const invalidate = () =>
    void queryClient.invalidateQueries({ queryKey: ['agent-documents', propertyId] })

  const refresh = useMutation({
    mutationFn: (id: string) =>
      api.post(`properties/${propertyId}/agent/documents/${id}/refresh`, {}),
    onSuccess: invalidate,
  })

  const share = useMutation({
    mutationFn: ({ id, guestSafe }: { id: string; guestSafe: boolean }) =>
      api.patch(`properties/${propertyId}/agent/documents/${id}/sharing`, {
        is_guest_safe: guestSafe,
      }),
    onSuccess: invalidate,
  })

  const rows = documents.data?.data ?? []

  if (rows.length === 0) {
    return null
  }

  const failed = refresh.error ?? share.error

  return (
    <section className="card mb-3">
      <header className="card__header">
        <h2>
          <BookOpen size={16} aria-hidden /> What the agent has read
        </h2>
      </header>

      <div className="card__body stack">
        {failed !== null && failed !== undefined && (
          <p className="field__error small" role="alert">
            {failed instanceof ApiError ? failed.message : 'That could not be done.'}
          </p>
        )}

        {rows.map((document) => {
          const status = STATUS[document.status] ?? STATUS.pending

          return (
            <div key={document.id} className="stack bordered p-2">
              <div className="row row--between">
                <span className="stack stack--tight">
                  <a href={document.url} target="_blank" rel="noopener noreferrer">
                    <strong>{document.label}</strong>
                  </a>
                  <span className="small faint">
                    {document.fetched_at !== null
                      ? `Read ${new Date(document.fetched_at).toLocaleString()}`
                      : 'Never read'}
                    {document.was_truncated && ' · only the first part'}
                  </span>
                </span>

                <span className="row">
                  <Chip label={status.label} colour={status.colour} />
                  {mayEdit && (
                    <button
                      type="button"
                      className="btn btn--sm btn--ghost"
                      disabled={refresh.isPending}
                      onClick={() => refresh.mutate(document.id)}
                    >
                      <RefreshCw size={14} aria-hidden /> Read it again
                    </button>
                  )}
                </span>
              </div>

              {document.failure !== null && (
                <p className="small muted" role="status">
                  {document.failure}
                </p>
              )}

              {document.is_usable && (
                <>
                  {document.contains_secrets.length > 0 && (
                    /*
                      Shown before the switch is touched, not after. This is the
                      one setting on the screen that can hand a door code to
                      somebody with no booking.
                    */
                    <p className="small" role="alert">
                      <ShieldAlert size={13} aria-hidden /> This document contains your{' '}
                      {document.contains_secrets.join(' and ')}. Shared with guests, anyone who
                      writes in can be told{' '}
                      {document.contains_secrets.length === 1 ? 'it' : 'them'} — including somebody
                      with no booking.
                    </p>
                  )}

                  <label>
                    <input
                      type="checkbox"
                      checked={document.is_guest_safe}
                      disabled={!mayEdit || share.isPending}
                      onChange={(event) =>
                        share.mutate({ id: document.id, guestSafe: event.target.checked })
                      }
                    />
                    <span>
                      Let guests be answered from this.{' '}
                      <span className="small faint">
                        Off, only you and your colleagues see it. The agent always reads it for
                        your own questions either way.
                      </span>
                    </span>
                  </label>
                </>
              )}
            </div>
          )
        })}
      </div>
    </section>
  )
}
