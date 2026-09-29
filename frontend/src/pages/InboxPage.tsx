import { useMemo, useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bot, Copy, MessageSquarePlus, Plus } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type { AgentAnswer, Conversation, Message, Paginated } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { RecordDialog } from '@/components/RecordDialog'
import type { FieldSpec, RecordValues } from '@/components/RecordDialog'
import { useAuth } from '@/lib/auth'
import { usePropertyOptions } from '@/lib/options'
import { useRecordDialog } from '@/lib/useRecordDialog'

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

  // Where this conversation is really happening. Until an OTA is connected the
  // guest is on Airbnb and we are not, so a reply leaves through a person's
  // hands and the thread has to record that rather than claim we sent it.
  const [elsewhere, setElsewhere] = useState(false)
  const [transport, setTransport] = useState('airbnb')
  const [logging, setLogging] = useState(false)
  const [received, setReceived] = useState('')
  const [agentAnswer, setAgentAnswer] = useState<AgentAnswer | null>(null)
  const [copied, setCopied] = useState(false)

  const { options: properties } = usePropertyOptions()
  const newThread = useRecordDialog<never>()

  const threadFields: FieldSpec[] = useMemo(
    () => [
      { name: 'subject', label: 'Subject', type: 'text', placeholder: 'Enquiry from Airbnb' },
      { name: 'property_id', label: 'Property', type: 'select', options: properties },
      {
        name: 'participant_type',
        label: 'Who it is with',
        type: 'select',
        options: [
          { value: 'guest', label: 'A guest' },
          { value: 'owner', label: 'An owner' },
          { value: 'vendor', label: 'A supplier' },
          { value: 'internal', label: 'Colleagues only' },
        ],
      },
    ],
    [properties],
  )

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

  function refreshThread() {
    void queryClient.invalidateQueries({ queryKey: ['conversation', activeId] })
    void queryClient.invalidateQueries({ queryKey: ['conversations'] })
  }

  const send = useMutation({
    mutationFn: (body: string) => {
      if (elsewhere && !isNote) {
        // Records history; sends nothing. The person carried it already.
        return api.post(`conversations/${activeId}/delivered`, {
          body,
          transport,
          // Kept with the message so a manager can still tell a model drafted
          // it after it left here by hand.
          is_ai_generated: agentAnswer !== null,
        })
      }

      return api.post(`conversations/${activeId}/${isNote ? 'notes' : 'messages'}`, { body })
    },
    onSuccess: () => {
      setDraft('')
      setAgentAnswer(null)
      setCopied(false)
      refreshThread()
    },
  })

  const openThread = useMutation({
    mutationFn: (values: RecordValues) => api.post<{ data: Conversation }>('conversations', values),
    onSuccess: (result) => {
      newThread.close()
      // Selected straight away: somebody opening a thread is about to paste a
      // message into it.
      setSelectedId(result.data.id)
      void queryClient.invalidateQueries({ queryKey: ['conversations'] })
    },
  })

  const logReceived = useMutation({
    mutationFn: (body: string) =>
      api.post(`conversations/${activeId}/received`, { body, transport }),
    onSuccess: () => {
      setReceived('')
      setLogging(false)
      refreshThread()
    },
  })

  const askAgent = useMutation({
    mutationFn: () =>
      api.post<{ data: { answer: AgentAnswer } | null; reason?: string }>(
        `conversations/${activeId}/agent-draft`,
      ),
    onSuccess: (result) => {
      if (result.data !== null) {
        setDraft(result.data.answer.reply)
        setAgentAnswer(result.data.answer)
        setCopied(false)
      }
    },
  })

  async function copyDraft() {
    try {
      await navigator.clipboard.writeText(draft)
      setCopied(true)
    } catch {
      // Clipboard access is refused outside a secure context; the text is on
      // screen and selectable either way, so this is not worth an error.
      setCopied(false)
    }
  }

  const conversation = thread.data?.data
  const messages = conversation?.messages ?? []

  return (
    <>
      {newThread.isOpen && (
        <RecordDialog
          title="New conversation"
          description="A thread for a conversation happening somewhere this platform is not connected to. Open it, then paste in what the guest wrote."
          fields={threadFields}
          submitLabel="Open thread"
          pending={openThread.isPending}
          error={openThread.error}
          onSubmit={(values) => openThread.mutate({ participant_type: 'guest', ...values })}
          onClose={newThread.close}
        />
      )}

      <div className="page-header">
        <div>
          <h1>Inbox</h1>
          <div className="page-header__subtitle">
            {list.data ? `${list.data.meta.total} conversation(s)` : 'Loading…'}
          </div>
        </div>

        <div className="row">
          {can('messages.send') && (
            <button type="button" className="btn btn--primary btn--sm" onClick={newThread.create}>
              <Plus size={15} aria-hidden /> New conversation
            </button>
          )}
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

              {/*
                Logging what a guest said somewhere else. Reading the inbox is
                enough for this: it is data entry about something that already
                happened, not an act of reaching a guest.
              */}
              <div className="inbox__log">
                {logging ? (
                  <form
                    onSubmit={(event) => {
                      event.preventDefault()
                      if (received.trim() !== '') logReceived.mutate(received)
                    }}
                  >
                    <label className="field__label" htmlFor="log-received">
                      Paste what the guest wrote
                    </label>
                    <textarea
                      id="log-received"
                      rows={2}
                      value={received}
                      placeholder="Hi! Is there parking near the flat?"
                      onChange={(event) => setReceived(event.target.value)}
                    />
                    <div className="row row--between mt-2">
                      <TransportPicker value={transport} onChange={setTransport} />
                      <div className="row">
                        <button type="button" className="btn btn--sm" onClick={() => setLogging(false)}>
                          Cancel
                        </button>
                        <button
                          type="submit"
                          className="btn btn--sm btn--primary"
                          disabled={logReceived.isPending || received.trim() === ''}
                        >
                          {logReceived.isPending ? 'Logging…' : 'Log it'}
                        </button>
                      </div>
                    </div>
                  </form>
                ) : (
                  <button type="button" className="btn btn--sm btn--ghost" onClick={() => setLogging(true)}>
                    <MessageSquarePlus size={14} aria-hidden /> Log a message the guest sent elsewhere
                  </button>
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

                  {!isNote && (
                    <div className="row row--between mb-2">
                      <button
                        type="button"
                        className="btn btn--sm"
                        onClick={() => askAgent.mutate()}
                        disabled={askAgent.isPending}
                      >
                        <Bot size={14} aria-hidden />
                        {askAgent.isPending ? 'Drafting…' : 'Ask the agent'}
                      </button>

                      {askAgent.data?.data === null && (
                        <span className="small faint">{askAgent.data.reason}</span>
                      )}
                    </div>
                  )}

                  <textarea
                    rows={3}
                    value={draft}
                    placeholder={isNote ? 'A note for colleagues…' : 'Reply to the guest…'}
                    onChange={(event) => {
                      setDraft(event.target.value)
                      // Edited by hand, so it is no longer the agent's words.
                      if (agentAnswer !== null) setAgentAnswer(null)
                      setCopied(false)
                    }}
                  />

                  {agentAnswer !== null && (
                    <div className="small muted mt-1">
                      <Chip label={agentAnswer.intent.replace(/_/g, ' ')} colour="sky" />{' '}
                      {Math.round(agentAnswer.confidence * 100)}% sure ·{' '}
                      {agentAnswer.would_auto_send
                        ? 'the agent judged this safe to send'
                        : `held: ${agentAnswer.held_because ?? '—'}`}
                      {agentAnswer.is_simulated && ' · simulated, not written by a model'}
                      {agentAnswer.withheld.length > 0 && (
                        <div className="small faint">
                          Arrival details were withheld: {agentAnswer.withheld.join(' ')}
                        </div>
                      )}
                    </div>
                  )}

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

                    <div className="row">
                      {elsewhere && !isNote && (
                        <button type="button" className="btn btn--sm" onClick={() => void copyDraft()}>
                          <Copy size={14} aria-hidden /> {copied ? 'Copied' : 'Copy'}
                        </button>
                      )}

                      <button type="submit" className="btn" disabled={send.isPending || draft.trim() === ''}>
                        {send.isPending
                          ? 'Saving…'
                          : isNote
                            ? 'Add note'
                            : elsewhere
                              ? 'Record as sent'
                              : 'Send'}
                      </button>
                    </div>
                  </div>

                  {!isNote && (
                    <div className="row row--between mt-2">
                      <label className="row small">
                        <input
                          type="checkbox"
                          checked={elsewhere}
                          onChange={(event) => setElsewhere(event.target.checked)}
                        />
                        {/* The distinction the whole workflow rests on: this
                            platform delivering a message, versus recording that
                            a person delivered one. Nothing here may imply the
                            first when only the second happened. */}
                        I will send this myself — just record it
                      </label>

                      {elsewhere && <TransportPicker value={transport} onChange={setTransport} />}
                    </div>
                  )}
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
 * Where a message really travelled.
 *
 * Offered as a choice rather than assumed, because the record of a conversation
 * is evidence in a dispute months later and "we think it was email" is not a
 * fact worth writing down. `manual` is the honest fallback for anything not
 * listed.
 */
function TransportPicker({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  return (
    <label className="row small faint">
      Where
      <select
        className="select--sm"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        aria-label="Where this message travelled"
      >
        <option value="airbnb">Airbnb</option>
        <option value="booking">Booking.com</option>
        <option value="whatsapp">WhatsApp</option>
        <option value="sms">SMS</option>
        <option value="phone">Phone call</option>
        <option value="email">Email</option>
        <option value="manual">Somewhere else</option>
      </select>
    </label>
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
