import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { Conversation, Message, Paginated } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { useAuth } from '@/lib/auth'

// The server's own filter names, so the interface cannot ask for a view the
// API does not have and quietly fall back to the default.
const FILTERS = [
  { value: 'awaiting_reply', label: 'Awaiting reply' },
  { value: 'inbox', label: 'Open' },
  { value: 'mine', label: 'Assigned to me' },
  { value: 'unassigned', label: 'Unassigned' },
  { value: 'all', label: 'All' },
]

/**
 * The inbox.
 *
 * Sorted by who has been waiting longest rather than by what arrived last,
 * because the guest who messaged four hours ago and has heard nothing is the
 * one who matters — and a newest-first list buries them under every automated
 * confirmation that has gone out since.
 */
export function InboxPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()

  const [filter, setFilter] = useState('awaiting_reply')
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [draft, setDraft] = useState('')
  const [isNote, setIsNote] = useState(false)

  const list = useQuery({
    queryKey: ['conversations', { filter }],
    queryFn: () =>
      api.get<Paginated<Conversation>>('conversations', { filter, per_page: 50 }),
    placeholderData: keepPreviousData,
  })

  const conversations = list.data?.data ?? []
  const activeId = selectedId ?? conversations[0]?.id ?? null

  const thread = useQuery({
    queryKey: ['conversation', activeId],
    queryFn: () => api.get<{ data: Conversation }>(`conversations/${activeId}`),
    enabled: activeId !== null,
  })

  const send = useMutation({
    mutationFn: (body: string) =>
      api.post(`conversations/${activeId}/${isNote ? 'notes' : 'messages'}`, { body }),
    onSuccess: () => {
      setDraft('')
      void queryClient.invalidateQueries({ queryKey: ['conversation', activeId] })
      void queryClient.invalidateQueries({ queryKey: ['conversations'] })
    },
  })

  const conversation = thread.data?.data
  const messages = conversation?.messages ?? []

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Inbox</h1>
          <div className="page-header__subtitle">
            {list.data ? `${list.data.meta.total} conversation(s)` : 'Loading…'}
          </div>
        </div>

        <div className="row">
          {FILTERS.map((option) => (
            <button
              key={option.value}
              type="button"
              className={filter === option.value ? 'btn btn--sm' : 'btn btn--ghost btn--sm'}
              onClick={() => {
                setFilter(option.value)
                setSelectedId(null)
              }}
            >
              {option.label}
            </button>
          ))}
        </div>
      </div>

      <div className="inbox">
        <aside className="inbox__list card">
          <QueryState
            isLoading={list.isLoading}
            error={list.error}
            isEmpty={conversations.length === 0}
            emptyTitle="Nothing waiting"
            emptyBody="No conversations match this filter."
          >
            {conversations.map((item) => (
              <button
                key={item.id}
                type="button"
                className={
                  item.id === activeId
                    ? 'inbox__item inbox__item--active'
                    : 'inbox__item'
                }
                onClick={() => setSelectedId(item.id)}
              >
                <div className="row row--between">
                  <span className="strong truncate">{item.title}</span>
                  {item.unread_count > 0 && (
                    <span className="chip chip--indigo">{item.unread_count}</span>
                  )}
                </div>

                <div className="small faint truncate">{item.last_message_preview ?? '—'}</div>

                <div className="row mt-1">
                  {item.channel !== null && <Chip label={item.channel} colour="slate" />}

                  {/* How long a guest has been waiting, stated rather than
                      left to be worked out from a timestamp. */}
                  {item.is_awaiting_reply && item.minutes_waiting !== null && (
                    <Chip
                      label={`Waiting ${formatWait(item.minutes_waiting)}`}
                      colour={item.minutes_waiting > 240 ? 'rose' : 'amber'}
                    />
                  )}
                </div>
              </button>
            ))}
          </QueryState>
        </aside>

        <section className="inbox__thread card">
          {activeId === null ? (
            <div className="empty">
              <div className="empty__title">No conversation selected</div>
            </div>
          ) : (
            <QueryState isLoading={thread.isLoading} error={thread.error}>
              <header className="inbox__thread-header">
                <div>
                  <div className="strong">{conversation?.title}</div>
                  <div className="small faint">
                    {conversation?.reservation?.confirmation_code ?? 'No booking'}
                    {conversation?.property?.name !== undefined &&
                      ` · ${conversation.property.name}`}
                  </div>
                </div>
                {conversation?.status !== undefined && (
                  <Chip label={conversation.status} colour="slate" />
                )}
              </header>

              <div className="inbox__messages">
                {messages.map((message) => (
                  <MessageBubble key={message.id} message={message} />
                ))}

                {messages.length === 0 && (
                  <div className="small faint">No messages yet.</div>
                )}
              </div>

              {can('messages.send') && (
                <form
                  className="inbox__composer"
                  onSubmit={(event) => {
                    event.preventDefault()
                    if (draft.trim() !== '') send.mutate(draft)
                  }}
                >
                  {send.error !== null && (
                    <div className="notice notice--error" role="alert">
                      {send.error instanceof ApiError
                        ? send.error.message
                        : 'That message could not be sent.'}
                    </div>
                  )}

                  <textarea
                    rows={3}
                    value={draft}
                    placeholder={isNote ? 'A note for colleagues…' : 'Reply to the guest…'}
                    onChange={(event) => setDraft(event.target.value)}
                  />

                  <div className="row row--between mt-2">
                    <label className="row small">
                      <input
                        type="checkbox"
                        checked={isNote}
                        onChange={(event) => setIsNote(event.target.checked)}
                      />
                      {/* Said plainly on the control itself, because the
                          consequence of getting it wrong is a private remark
                          reaching a guest. */}
                      Internal note — the guest never sees this
                    </label>

                    <button type="submit" className="btn" disabled={send.isPending || draft.trim() === ''}>
                      {send.isPending ? 'Sending…' : isNote ? 'Add note' : 'Send'}
                    </button>
                  </div>
                </form>
              )}
            </QueryState>
          )}
        </section>
      </div>
    </>
  )
}

/**
 * One message.
 *
 * Provenance is shown on the bubble: what a person wrote, what an automation
 * sent and what only reached a local transport are three different things, and
 * an operator reading a thread has to be able to tell them apart.
 */
function MessageBubble({ message }: { message: Message }) {
  const inbound = message.direction === 'inbound'

  const className = message.is_internal_note
    ? 'bubble bubble--note'
    : inbound
      ? 'bubble bubble--in'
      : 'bubble bubble--out'

  return (
    <div className={className}>
      <div className="row row--between small faint">
        <span>{message.author_name ?? (inbound ? 'Guest' : 'Staff')}</span>
        <span>
          {message.sent_at !== null
            ? new Date(message.sent_at).toLocaleString()
            : new Date(message.created_at).toLocaleString()}
        </span>
      </div>

      <div className="bubble__body">{message.body}</div>

      <div className="row mt-1">
        {message.is_internal_note && <Chip label="Internal" colour="zinc" />}
        {message.automation_rule_id !== null && <Chip label="Automated" colour="indigo" />}
        {message.is_ai_generated && <Chip label="AI drafted" colour="sky" />}

        {/* Never presented as delivered when it was recorded locally. */}
        {message.delivery?.simulated === true && (
          <Chip label="Simulated delivery" colour="amber" />
        )}

        {message.failed_at !== null && (
          <Chip label={message.failure_reason ?? 'Failed'} colour="rose" />
        )}
      </div>
    </div>
  )
}

function formatWait(minutes: number): string {
  if (minutes < 60) return `${Math.round(minutes)}m`
  if (minutes < 60 * 24) return `${Math.round(minutes / 60)}h`

  return `${Math.round(minutes / (60 * 24))}d`
}
