import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { BookOpen, Send, ShieldAlert, X } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type { AgentAnswer, AgentAsk, Property } from '@/api/types'
import { AgentAvatar } from '@/components/AgentAvatar'
import { AgentReply } from '@/components/AgentReply'
import { AgentMemoryPanel } from '@/components/AgentMemoryPanel'
import { AgentActionQueue } from '@/components/AgentActionQueue'

/**
 * A chat with one property's agent, opened from its card.
 *
 * Deliberately not the Agents screen. That screen is where an agent is
 * configured and tested — provider, gates, scenario runs — and sending somebody
 * there to ask a question is like opening the settings app to send a text. This
 * is the chat: a name at the top, a thread, a box.
 *
 * It talks to whichever road the property actually has. A bot that answers in
 * the moment gets the synchronous endpoint; one that answers by callback gets
 * the webhook and the answer appears in the thread when it lands. The person
 * typing should not have to know which, so the only visible difference is how
 * long it takes and a line saying the question is still out.
 */
export function AgentChatDrawer({
  property,
  onClose,
}: {
  property: Property
  onClose: () => void
}) {
  const agent = property.agent
  const queryClient = useQueryClient()

  const [question, setQuestion] = useState('')
  const [thread, setThread] = useState<Turn[]>([])

  const inputRef = useRef<HTMLTextAreaElement>(null)
  const endRef = useRef<HTMLDivElement>(null)

  // Focus the box, because the only reason to open this is to type in it.
  useEffect(() => inputRef.current?.focus(), [])

  // Escape closes, like every other overlay in the product.
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose()
    }

    document.addEventListener('keydown', onKey)

    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  const answersNow = agent?.can_answer === true

  const ask = useMutation({
    mutationFn: (asked: string) =>
      api.post<{ data: { answer: AgentAnswer } }>(`properties/${property.id}/agent/ask`, {
        question: asked,
        audience: 'operator',
        history: historyOf(thread),
      }),
    onSuccess: (result, asked) => {
      void queryClient.invalidateQueries({ queryKey: ['agent-memories', property.id] })
      void queryClient.invalidateQueries({ queryKey: ['agent-actions', property.id] })
      setThread((current) => [...current, { you: asked, answer: result.data.answer }])
      setQuestion('')
    },
  })

  const askLater = useMutation({
    mutationFn: (asked: string) =>
      api.post<{ data: AgentAsk }>(`properties/${property.id}/agent/ask-later`, {
        question: asked,
        audience: 'operator',
        history: historyOf(thread),
      }),
    onSuccess: (result, asked) => {
      void queryClient.invalidateQueries({ queryKey: ['agent-memories', property.id] })
      setThread((current) => [...current, { you: asked, answer: null, askId: result.data.id }])
      setQuestion('')
    },
  })

  /*
   * The question still out with the bot, if there is one.
   *
   * Derived from the thread rather than tracked in its own state: a second
   * source of truth for "are we waiting" is a second thing to get out of step,
   * and the answer is already sitting in the last turn.
   */
  const outstanding = thread[thread.length - 1]?.answer === null
    ? (thread[thread.length - 1]?.askId ?? null)
    : null

  /*
   * Watching for the answer to a question that went out by webhook.
   *
   * Polled only while something is actually out, and stopped the moment it
   * lands. A chat that kept asking the server about a settled question would
   * spend somebody's quota on nothing.
   */
  const pending = useQuery({
    queryKey: ['agent-asks', property.id],
    queryFn: () => api.get<{ data: AgentAsk[] }>(`properties/${property.id}/agent/asks`),
    enabled: outstanding !== null,
    // Polled only while something is actually out, and stopped by the same
    // condition the moment it lands.
    refetchInterval: outstanding === null ? false : 4_000,
  })

  const asks = pending.data?.data ?? []

  /** The settled ask for a turn, or undefined while it is still out. */
  function landed(askId: string | undefined): AgentAsk | undefined {
    if (askId === undefined) return undefined

    const row = asks.find((ask) => ask.id === askId)

    return row === undefined || row.status === 'pending' ? undefined : row
  }

  const waiting = outstanding !== null && landed(outstanding) === undefined

  // Declared after the poll because it watches it: a late answer arriving
  // should bring the bottom of the thread into view the same way sending does.
  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' })
  }, [thread.length, pending.dataUpdatedAt])

  const sending = ask.isPending || askLater.isPending
  const failed = ask.error ?? askLater.error
  const reachable = agent?.can_answer === true || agent?.can_be_asked_later === true

  function send() {
    const asked = question.trim()

    if (asked.length < 2 || sending || waiting) return

    if (answersNow) {
      ask.mutate(asked)
    } else {
      askLater.mutate(asked)
    }
  }

  return createPortal(
    <div className="drawer-backdrop" role="presentation" onClick={onClose}>
      <aside
        className="drawer drawer--chat"
        role="dialog"
        aria-modal="true"
        aria-label={`Chat with ${agent?.name ?? 'this property’s agent'}`}
        onClick={(event) => event.stopPropagation()}
      >
        <header className="drawer__header row row--between">
          <span className="row">
            <AgentAvatar url={agent?.avatar_url} initial={agent?.initial} />
            <span className="stack stack--tight">
              <strong>{agent?.name ?? 'This property’s agent'}</strong>
              <span className="small faint">{property.name}</span>
            </span>
          </span>

          <button type="button" className="btn btn--sm btn--ghost" onClick={onClose} aria-label="Close">
            <X size={16} aria-hidden />
          </button>
        </header>

        <div className="drawer__body chat">
          <AgentMemoryPanel propertyId={property.id} onForget={() => setThread([])} />
          {agent?.is_simulated && <p className="notice notice--warning" role="status">{agent.connection_message ?? 'Demo mode: replies are simulated.'}</p>}
          {thread.length === 0 && (
            <p className="small muted">
              Ask about bookings and the inbox, or request local calendar, rate, property-information and task changes.
              Guest replies wait for your approval before sending. Calendar, rate and property changes stay local.
              What you tell the agent is saved for your future chats about this property.
            </p>
          )}

          {thread.map((turn, index) => {
            const settled = landed(turn.askId)

            return (
              <div key={index} className="stack">
                <p className="chat__you">{turn.you}</p>

                {turn.answer !== null && (
                  <ChatReply text={turn.answer.reply} held={turn.answer.held_because} simulated={turn.answer.is_simulated} />
                )}

                {turn.answer === null && settled !== undefined && (
                  <ChatReply
                    text={settled.reply ?? settled.failure ?? 'No answer came back.'}
                    held={settled.held_because}
                  />
                )}

                {turn.answer === null && settled === undefined && (
                  <p className="chat__them chat__them--waiting small muted">
                    {agent?.name ?? 'The agent'} is working on it. This can take a couple of minutes
                    — the answer appears here when it lands.
                  </p>
                )}
              </div>
            )
          })}

          {failed !== null && failed !== undefined && (
            <p className="field__error small" role="alert">
              {failed instanceof ApiError ? failed.message : 'That could not be asked.'}
            </p>
          )}

          {thread.some(turn => /^(Awaiting approval|Completed):/.test(turn.answer?.reply ?? landed(turn.askId)?.reply ?? '')) && (
            <AgentActionQueue propertyId={property.id} />
          )}
          <div ref={endRef} />
        </div>

        <footer className="drawer__footer stack">
          {!reachable ? (
            <p className="small muted">
              {agent?.connection_message ?? 'No bot is connected to this property yet.'}{' '}
              <Link to={`/agent?property=${property.id}`}>Set one up</Link>.
            </p>
          ) : (
            <form
              className="chat__composer row"
              onSubmit={(event) => {
                event.preventDefault()
                send()
              }}
            >
              <textarea
                ref={inputRef}
                rows={2}
                value={question}
                placeholder="How did this flat do last month?"
                disabled={waiting}
                onChange={(event) => setQuestion(event.target.value)}
                // Enter sends, Shift+Enter makes a new line. The expected
                // behaviour of every chat box anybody has used.
                onKeyDown={(event) => {
                  if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault()
                    send()
                  }
                }}
                aria-label="Message"
              />
              <button
                type="submit"
                className="btn btn--primary"
                disabled={sending || waiting || question.trim().length < 2}
                aria-label="Send"
              >
                <Send size={16} aria-hidden />
              </button>
            </form>
          )}

          <div className="row row--between small faint">
            <Link to={`/agent?property=${property.id}`}>Edit this agent</Link>
            {agent?.knowledge_base_url != null && (
              <a href={agent.knowledge_base_url} target="_blank" rel="noopener noreferrer">
                <BookOpen size={13} aria-hidden /> Knowledge base
              </a>
            )}
          </div>
        </footer>
      </aside>
    </div>,
    document.body,
  )
}

interface Turn {
  you: string
  /** Set when the agent answered in the moment. */
  answer: AgentAnswer | null
  /** Set when the answer is coming back later: the ask to watch for. */
  askId?: string
}

function ChatReply({ text, held, simulated }: { text: string; held: string | null; simulated?: boolean }) {
  return (
    <div className="chat__them">
      <AgentReply text={text} />
      {simulated && <p className="small muted">Simulated reply</p>}
      {held !== null && (
        // Shown rather than hidden: a reply the gates stopped is still worth
        // reading, and why it was stopped is the useful half.
        <p className="small muted">
          <ShieldAlert size={13} aria-hidden /> {held}
        </p>
      )}
    </div>
  )
}

/**
 * Both halves of every previous turn, which is what makes a follow-up mean what
 * it says. A question like "and the one downstairs?" is unintelligible without
 * it.
 */
function historyOf(thread: Turn[]): { role: string; body: string }[] {
  return thread.slice(-10).flatMap((turn) => {
    const reply = turn.answer?.reply

    return reply === undefined || reply === null
      ? [{ role: 'guest', body: turn.you.slice(0, 2000) }]
      : [
          { role: 'guest', body: turn.you.slice(0, 2000) },
          { role: 'host', body: reply.slice(0, 2000) },
        ]
  })
}
