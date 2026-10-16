import { useMemo, useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bot, Copy, GraduationCap, MessageSquarePlus, Plus } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type { AgentAnswer, Conversation, Message, Paginated } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { RecordDialog } from '@/components/RecordDialog'
import type { FieldSpec, RecordValues } from '@/components/RecordDialog'
import { useAuth } from '@/lib/auth'
import { usePropertyOptions } from '@/lib/options'
import { toast } from '@/lib/toast'
import { useRecordDialog } from '@/lib/useRecordDialog'
import { channelLabel } from '@/lib/channelLabel'

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

  /*
   * The question the agent could not answer, held while the knowledge editor is
   * open. Null when it is closed — the question is the dialog's whole reason to
   * exist, so there is nothing to keep once it closes.
   */
  const [teaching, setTeaching] = useState<string | null>(null)

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

  /*
   * Declared after the thread it needs, because it needs the property the
   * thread belongs to — a knowledge entry is per property, and the guest's
   * question only tells us which one by way of the conversation it arrived in.
   */
  const teach = useMutation({
    mutationFn: (values: RecordValues) =>
      api.post(`properties/${conversation?.property_id ?? ''}/agent/knowledge`, values),
    onSuccess: () => {
      setTeaching(null)
      // One entry per topic, so saving under an existing one corrects it. The
      // thread itself does not change; the next question is what reads it.
      void queryClient.invalidateQueries({ queryKey: ['property-knowledge'] })
      toast('Saved. The agent will use this from its next answer.')
    },
  })
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

      {/*
        Teaching the agent, from the message that showed it needed teaching.
        
        A held reply is a gap in what the property has written down, and the
        guest's question is that gap in their own words. Carrying it into the
        editor means the repair starts from the thing that exposed it rather
        than from a blank form on another screen — which is the difference
        between a fix somebody makes now and one they mean to make later.
      */}
      {teaching !== null && conversation?.property_id != null && (
        <RecordDialog
          title="Teach the agent"
          description="Saved to this property's knowledge base. The agent reads it on the next question, and answers on its own when nothing is left to guess at."
          fields={KNOWLEDGE_FIELDS}
          initial={{ guest_safe: true }}
          submitLabel="Save"
          pending={teach.isPending}
          error={teach.error}
          /*
           * The default is merged here as well as seeded above, because a
           * dialog given an `initial` submits only what changed — so a tick
           * left alone would send nothing and the server would store the fact
           * as private. That is precisely the failure this form exists to fix:
           * somebody writes the pool hours down and the next guest goes
           * unanswered too. Unticking it still sends `false`, which wins.
           */
          onSubmit={(values) => teach.mutate({ guest_safe: true, ...values })}
          onClose={() => setTeaching(null)}
        >
          <div className="panel small">
            <div className="faint">What the guest asked</div>
            <div className="mt-1">{teaching}</div>
          </div>
        </RecordDialog>
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
                  {item.channel !== null && <Chip label={channelLabel(item.source_channel ?? item.channel)} colour="slate" />}

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
                  <MessageBubble
                    key={message.id}
                    message={message}
                    propertyId={conversation?.property_id ?? null}
                    onTeach={setTeaching}
                  />
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
/**
 * One fact the property keeps, as the agent will read it back.
 *
 * Topic rather than title, and one entry per topic, so saving under an
 * existing one corrects it instead of leaving two facts that disagree — which
 * is the failure mode of a knowledge base nobody prunes.
 */
const KNOWLEDGE_FIELDS: FieldSpec[] = [
  {
    name: 'topic',
    label: 'Topic',
    type: 'text',
    required: true,
    placeholder: 'Pool hours',
    hint: 'A short name for the thing. Saving under a topic that already exists corrects it.',
  },
  {
    name: 'content',
    label: 'What the agent should say',
    type: 'textarea',
    required: true,
    rows: 5,
    hint: 'Write it as fact, not as a reply. The agent words the answer itself.',
  },
  /*
   * Ticked by default here, and nowhere else.
   *
   * Everywhere else a fact starts private, because the same list holds who to
   * ring about the boiler. This form is opened from a guest's unanswered
   * question — the person filling it in is answering that guest — so starting
   * it off would mean writing the pool hours down and watching the next guest
   * go unanswered too, which is the bug this whole flow exists to fix.
   */
  {
    name: 'guest_safe',
    label: 'The agent may tell guests this',
    type: 'checkbox',
    hint: 'Leave this on unless the note is for your team rather than the guest.',
  },
]

function MessageBubble({
  message,
  propertyId,
  onTeach,
}: {
  message: Message
  propertyId: string | null
  /** Opens the knowledge editor with this question already in it. */
  onTeach: (question: string) => void
}) {
  const inbound = message.direction === 'inbound'
  const attempt = message.automatic_reply

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

      {/*
        What the agent decided about this message, under the message.
        
        Both of these end with the guest holding nothing, and the thread used to
        show that as an unanswered question and no more — so an agent that drafted
        carefully and declined to send looked exactly like an agent that was never
        switched on. The reason is the part that tells somebody what to do next.
      */}
      {attempt !== undefined && ['held', 'failed'].includes(attempt.status ?? '') && (
        <div className={`attempt attempt--${attempt.status}`}>
          <div className="small">
            <strong>
              {attempt.status === 'held'
                ? 'Not sent — the agent held this for you'
                : 'Not sent — the agent could not answer'}
            </strong>
            {attempt.detail !== null && <div className="faint mt-1">{attempt.detail}</div>}
          </div>

          {/*
            Offered only where it would help. A held reply is a gap in what the
            property has written down, and the guest's own question is the gap
            stated plainly — which makes it the topic somebody should fill in.
          */}
          {attempt.status === 'held' && propertyId !== null && (
            <button
              type="button"
              className="btn btn--sm mt-2"
              onClick={() => onTeach(message.body)}
            >
              <GraduationCap size={14} aria-hidden /> Teach the agent
            </button>
          )}
        </div>
      )}
    </div>
  )
}

function formatWait(minutes: number): string {
  if (minutes < 60) return `${Math.round(minutes)}m`
  if (minutes < 60 * 24) return `${Math.round(minutes / 60)}h`

  return `${Math.round(minutes / (60 * 24))}d`
}
