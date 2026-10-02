import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bot, Clock, FlaskConical, Send, ShieldAlert } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type {
  AgentAnswer,
  AgentAsk,
  AgentCapabilityKey,
  AgentConfiguration,
  AgentEvalRun,
  BotTestResult,
  Paginated,
  Property,
  Reservation,
} from '@/api/types'
import { AgentActionQueue } from '@/components/AgentActionQueue'
import { AgentActivityLog } from '@/components/AgentActivityLog'
import { AgentKnowledge } from '@/components/AgentKnowledge'
import { Chip } from '@/components/Chip'
import { PropertyHelpers } from '@/components/PropertyHelpers'
import { QueryState } from '@/components/QueryState'
import { useAuth } from '@/lib/auth'

interface AskResult {
  data: {
    answer: AgentAnswer
    reservation: { id: string; confirmation_code: string } | null
    was_sent: false
  }
}

/**
 * The guest agent, per property, with the bench to test it on.
 *
 * Three things on this screen are deliberate.
 *
 * **Nothing here sends a message.** Asking the agent a question produces a
 * draft; running the suite produces scores. The screen says so next to both
 * buttons, because an operator trying the awkward questions on a live property
 * needs to know for certain that the guest whose booking is selected will not
 * receive them.
 *
 * **Automatic sending is offered per subject, not as a switch.** "Let the agent
 * reply by itself" is the wrong question; "let it reply by itself about the
 * wifi" is the right one. The subjects that may never be automated are not
 * rendered as disabled options — they are explained, because an operator who
 * cannot find the refund checkbox will look for it for a while.
 *
 * **A simulated answer is labelled as one.** Without a model configured the
 * drafts are composed locally, and a bench showing plausible text with nothing
 * saying where it came from would be the most misleading screen in the product.
 */
export function AgentsPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const mayConfigure = can('properties.update')

  /*
   * Opened from a property card, which passes the property in the URL.
   *
   * Read as the initial value rather than synced: once somebody has used the
   * picker on this screen, their choice wins over the link that brought them
   * here. A URL that kept overriding it would make the picker feel broken.
   */
  const [searchParams] = useSearchParams()
  const [propertyId, setPropertyId] = useState<string | null>(
    () => searchParams.get('property'),
  )

  const properties = useQuery({
    queryKey: ['properties', { for: 'agents' }],
    queryFn: () =>
      api.get<Paginated<Property>>('properties', { per_page: 100, status: 'active,draft,inactive' }),
  })

  const rows = properties.data?.data ?? []
  const selected = rows.find((property) => property.id === propertyId) ?? rows[0] ?? null

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Agents</h1>
          <div className="page-header__subtitle">
            One agent per property, answering from that property&rsquo;s own facts. Ask it anything
            about the place, or see what it would say to a guest. Drafts only — nothing on this
            screen reaches a guest.
          </div>
        </div>
      </div>

      <div className="filters">
        <div className="field">
          <label className="field__label" htmlFor="agent-property">
            Property
          </label>
          <select
            id="agent-property"
            value={selected?.id ?? ''}
            onChange={(event) => setPropertyId(event.target.value)}
          >
            {rows.map((property) => (
              <option key={property.id} value={property.id}>
                {property.name}
              </option>
            ))}
          </select>
        </div>
      </div>

      <QueryState
        isLoading={properties.isLoading}
        error={properties.error}
        isEmpty={rows.length === 0}
        emptyTitle="No properties yet"
        emptyBody="An agent answers questions about a property, so there has to be one first."
      >
        {selected !== null && (
          <AgentPanels
            key={selected.id}
            property={selected}
            mayConfigure={mayConfigure}
            onSaved={() => {
              void queryClient.invalidateQueries({ queryKey: ['agent', selected.id] })
              void queryClient.invalidateQueries({ queryKey: ['properties'] })
              void queryClient.invalidateQueries({ queryKey: ['property', selected.id] })
            }}
          />
        )}
      </QueryState>
    </>
  )
}

function AgentPanels({
  property,
  mayConfigure,
  onSaved,
}: {
  property: Property
  mayConfigure: boolean
  onSaved: () => void
}) {
  const configuration = useQuery({
    queryKey: ['agent', property.id],
    queryFn: () => api.get<{ data: AgentConfiguration }>(`properties/${property.id}/agent`),
  })

  const data = configuration.data?.data

  return (
    <QueryState isLoading={configuration.isLoading} error={configuration.error}>
      {data !== undefined && (
        <>
          {!data.capabilities.provider.is_live && (
            <div className="notice notice--warning" role="status">
              <strong>{data.capabilities.provider.name} is not live.</strong>{' '}
              {data.capabilities.provider.simulation_reason} Drafts below are composed locally, so
              the gates are genuinely under test and the quality of the wording is not.
            </div>
          )}

          <BriefForm
            property={property}
            configuration={data}
            mayConfigure={mayConfigure}
            onSaved={onSaved}
          />

          {mayConfigure && <Bench property={property} configuration={data} />}

          {/*
            The two things an agent needs around it: who it escalates to when it
            cannot fix something, and what it has already done. Below the bench
            because they are read after a question rather than before one.
          */}
          {/*
            Above the knowledge and the log because it is the only one of the
            three that is waiting on the reader. A proposal nobody looks at
            expires, and an expired proposal is a job somebody still has to do.
          */}
          <AgentActionQueue propertyId={property.id} />
          <AgentKnowledge propertyId={property.id} mayEdit={mayConfigure} />
          <PropertyHelpers propertyId={property.id} mayEdit={mayConfigure} />
          <AgentActivityLog propertyId={property.id} />
        </>
      )}
    </QueryState>
  )
}

function BriefForm({
  property,
  configuration,
  mayConfigure,
  onSaved,
}: {
  property: Property
  configuration: AgentConfiguration
  mayConfigure: boolean
  onSaved: () => void
}) {
  const { brief, capabilities } = configuration

  // Held as text so a half-typed list is not repeatedly re-parsed under the
  // operator's fingers. Split on save.
  const [enabled, setEnabled] = useState(brief.enabled)
  const [persona, setPersona] = useState(brief.persona)
  const [extra, setExtra] = useState(brief.extra_knowledge ?? '')
  const [never, setNever] = useState(brief.never.join('\n'))
  const [escalate, setEscalate] = useState(brief.escalate.join('\n'))
  const [autoSend, setAutoSend] = useState<string[]>(brief.auto_send)
  // What it may be asked to do, and what it may do before anybody looks. Two
  // lists rather than three states per capability, because the second is only
  // meaningful as a subset of the first.
  const [mayDo, setMayDo] = useState<AgentCapabilityKey[]>(brief.may_do)
  const [mayDoAlone, setMayDoAlone] = useState<AgentCapabilityKey[]>(brief.may_do_alone)
  const [floor, setFloor] = useState(brief.confidence_floor)
  const [provider, setProvider] = useState(brief.provider ?? '')
  const [botUrl, setBotUrl] = useState(brief.bot_url ?? '')
  const [botName, setBotName] = useState(brief.bot_name ?? '')
  /*
   * Empty means "leave the stored token alone", which is why it starts empty
   * even when one is set: the server never sends it back, so there is nothing to
   * show, and seeding this with dots would make a save rewrite the token with
   * dots.
   */
  const [botToken, setBotToken] = useState('')

  // How the agent appears on the property card.
  const [avatarUrl, setAvatarUrl] = useState(brief.bot_avatar_url ?? '')
  const [knowledgeUrl, setKnowledgeUrl] = useState(brief.knowledge_base_url ?? '')

  // The slow path. Independent of the bot above: a property can have both.
  const [webhookUrl, setWebhookUrl] = useState(brief.webhook_url ?? '')
  const [webhookToken, setWebhookToken] = useState('')

  /*
   * Testing the wire, separately from asking the agent a question.
   *
   * With several bots to connect, "it doesn't work" is the least useful thing a
   * screen can say. Asking the agent runs the facts, the classification, the
   * draft and four gates, so a failure anywhere reads the same; this puts one
   * dull question to the endpoint and reports what came back.
   */
  const test = useMutation({
    mutationFn: () => api.post<{ data: BotTestResult }>(`properties/${property.id}/agent/test-bot`, {}),
  })

  const save = useMutation({
    mutationFn: () =>
      api.patch<{ data: AgentConfiguration }>(`properties/${property.id}/agent`, {
        enabled,
        persona,
        extra_knowledge: extra.trim() === '' ? null : extra.trim(),
        never: lines(never),
        escalate: lines(escalate),
        auto_send: autoSend,
        may_do: mayDo,
        // Narrowed here as well as on the server: a capability that is no longer
        // granted must not keep its unattended flag, and sending one would be a
        // 422 the operator has to decode.
        may_do_alone: mayDoAlone.filter((key) => mayDo.includes(key)),
        confidence_floor: floor,
        provider: provider === '' ? null : provider,
        bot_url: botUrl.trim() === '' ? null : botUrl.trim(),
        bot_name: botName.trim() === '' ? null : botName.trim(),
        // Only sent when something was typed. Absent means keep what is stored;
        // an explicit empty string is how the screen clears it, via the button.
        ...(botToken === '' ? {} : { bot_token: botToken }),
        bot_avatar_url: avatarUrl.trim() === '' ? null : avatarUrl.trim(),
        knowledge_base_url: knowledgeUrl.trim() === '' ? null : knowledgeUrl.trim(),
        webhook_url: webhookUrl.trim() === '' ? null : webhookUrl.trim(),
        ...(webhookToken === '' ? {} : { webhook_token: webhookToken }),
      }),
    onSuccess: onSaved,
  })

  const error = save.error instanceof ApiError ? save.error : null

  return (
    <section className="card mb-3">
      <header className="card__header row row--between">
        <h2>
          <Bot size={16} aria-hidden /> What this agent may be
        </h2>
        {brief.enabled ? (
          <Chip label="On" colour="emerald" />
        ) : (
          <Chip label="Off — drafts only" colour="slate" />
        )}
      </header>

      <form
        className="card__body stack"
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        {error !== null && (
          <div className="notice notice--error" role="alert">
            {error.message}
          </div>
        )}

        <label>
          <input
            type="checkbox"
            checked={enabled}
            disabled={!mayConfigure}
            onChange={(event) => setEnabled(event.target.checked)}
          />
          <span>
            Turn this agent on.{' '}
            <span className="small faint">
              Off, it still drafts for a person to read. It sends nothing either way unless a
              subject below is ticked.
            </span>
          </span>
        </label>

        {/*
          Who this agent is, rather than how it works.

          First, and outside the provider block, because it is what the property
          card shows and what the people running these flats use to tell one
          agent from another. It applies whatever is answering underneath.
        */}
        <div className="grid grid--2">
          <div className="field">
            <label className="field__label" htmlFor="agent-name">
              What you call this agent
            </label>
            <input
              id="agent-name"
              type="text"
              placeholder="Alex"
              value={botName}
              disabled={!mayConfigure}
              onChange={(event) => setBotName(event.target.value)}
            />
            <p className="field__hint small faint">
              Shown on the property card. With no picture, its first letter is the badge.
            </p>
          </div>

          <div className="field">
            <label className="field__label" htmlFor="agent-avatar">
              Its picture
            </label>
            <input
              id="agent-avatar"
              type="url"
              placeholder="https://…/alex.jpg"
              value={avatarUrl}
              disabled={!mayConfigure}
              onChange={(event) => setAvatarUrl(event.target.value)}
            />
            <p className="field__hint small faint">Optional. A link to an image.</p>
          </div>
        </div>

        <div className="field">
          <label className="field__label" htmlFor="agent-knowledge">
            Its knowledge base
          </label>
          <input
            id="agent-knowledge"
            type="url"
            placeholder="https://docs.google.com/document/d/…"
            value={knowledgeUrl}
            disabled={!mayConfigure}
            onChange={(event) => setKnowledgeUrl(event.target.value)}
          />
          <p className="field__hint small faint">
            A link, not a copy — the document stays wherever it is maintained, so there is never a
            second version that is wrong by Friday. It appears on the property card.
          </p>
        </div>

        <div className="field">
          <label className="field__label" htmlFor="agent-provider">
            Who answers for this property
          </label>
          <select
            id="agent-provider"
            value={provider}
            disabled={!mayConfigure}
            onChange={(event) => setProvider(event.target.value)}
          >
            <option value="">
              Whatever the account uses ({capabilities.account_provider})
            </option>
            {(capabilities.providers ?? []).map((option) => (
              <option key={option.key} value={option.key}>
                {option.name}
              </option>
            ))}
          </select>
          <p className="field__hint small faint">
            Chosen per property, so one flat can be answered by its own bot while the next is
            answered by Claude.
          </p>
        </div>

        {provider === 'bot' && (
          <div className="stack notice notice--info">
            <p className="small">
              Habitat will POST this property's question to your bot and use what comes back as the
              draft. <strong>The facts go with it</strong> — and a door code only where the booking
              is entitled to one, which is the same rule that governs what a model is shown.
            </p>

            <div className="field">
              <label className="field__label" htmlFor="agent-bot-url">
                Where it listens
              </label>
              <input
                id="agent-bot-url"
                type="url"
                placeholder="https://bots.example.com/yellow"
                value={botUrl}
                disabled={!mayConfigure}
                onChange={(event) => setBotUrl(event.target.value)}
              />
              <p className="field__hint small faint">
                Must be https, and somewhere reachable from the outside — an address on this
                server's own network is refused, and the message says which.
              </p>
            </div>

            <div className="field">
              <label className="field__label" htmlFor="agent-bot-token">
                Token it expects {capabilities.bot_token_set && <span className="small faint">· one is already stored</span>}
              </label>
              <input
                id="agent-bot-token"
                type="password"
                autoComplete="off"
                placeholder={capabilities.bot_token_set ? 'Leave blank to keep the stored one' : 'Optional'}
                value={botToken}
                disabled={!mayConfigure}
                onChange={(event) => setBotToken(event.target.value)}
              />
              <p className="field__hint small faint">
                Sent as <code>Authorization: Bearer …</code>. Encrypted, and never shown again —
                which is why this box is empty rather than filled with dots.
              </p>
            </div>

            <p className="small faint">
              Answer with <code>{'{ "reply": "…", "intent": "amenity", "confidence": 0.9 }'}</code>.
              Plain text works too, and is always held for a person: an answer whose certainty
              nobody stated has not been established to be certain.
            </p>

            <div className="row row--between">
              <span className="small faint">
                Save first — this tests what is stored, not what is typed above.
              </span>
              <button
                type="button"
                className="btn btn--sm"
                disabled={test.isPending}
                onClick={() => test.mutate()}
              >
                {test.isPending ? 'Asking it…' : 'Test this bot'}
              </button>
            </div>

            {test.error !== null && (
              <p className="field__error small" role="alert">
                {test.error instanceof ApiError ? test.error.message : 'That test could not be run.'}
              </p>
            )}

            {test.data !== undefined && <BotTestCard result={test.data.data} />}
          </div>
        )}

        {/*
          The slow path, offered whatever the provider is.

          Not inside the `bot` block above and not a mode switch: a property can
          reasonably have both. The bot answers guests in two seconds; the
          webhook starts an agent run that takes two minutes and is for the
          harder questions an operator asks. Putting this behind the provider
          picker would have hidden it from every property answered by Claude.
        */}
        <div className="stack notice">
          <p className="small">
            <strong>Or ask something that takes a while.</strong> Habitat fires this webhook with
            the question and a one-time URL to answer on, then stops waiting. Your bot replies
            whenever it is finished — minutes later is fine.
          </p>

          <div className="field">
            <label className="field__label" htmlFor="agent-webhook-url">
              Webhook that starts it
            </label>
            <input
              id="agent-webhook-url"
              type="url"
              placeholder="https://hooks.example.com/yellow"
              value={webhookUrl}
              disabled={!mayConfigure}
              onChange={(event) => setWebhookUrl(event.target.value)}
            />
            <p className="field__hint small faint">
              Same rules as above: https, and reachable from the outside.
            </p>
          </div>

          <div className="field">
            <label className="field__label" htmlFor="agent-webhook-token">
              Key it expects{' '}
              {capabilities.webhook_token_set && (
                <span className="small faint">· one is already stored</span>
              )}
            </label>
            <input
              id="agent-webhook-token"
              type="password"
              autoComplete="off"
              placeholder={
                capabilities.webhook_token_set ? 'Leave blank to keep the stored one' : 'Optional'
              }
              value={webhookToken}
              disabled={!mayConfigure}
              onChange={(event) => setWebhookToken(event.target.value)}
            />
            <p className="field__hint small faint">
              Its own key, separate from the bot's — most platforms issue these two separately and
              rotate them on different days.
            </p>
          </div>

          <p className="small faint">
            The request carries a <code>callback</code> block with the URL to POST{' '}
            <code>{'{ "reply": "…", "intent": "…", "confidence": 0.9 }'}</code> to. That URL is the
            credential, it works once, and it lapses after{' '}
            {capabilities.webhook_window_minutes} minutes.
          </p>
        </div>

        <div className="field">
          <label className="field__label" htmlFor="agent-persona">
            How it should sound
          </label>
          <textarea
            id="agent-persona"
            rows={2}
            value={persona}
            disabled={!mayConfigure}
            onChange={(event) => setPersona(event.target.value)}
          />
        </div>

        <div className="field">
          <label className="field__label" htmlFor="agent-extra">
            True of this property right now
          </label>
          <textarea
            id="agent-extra"
            rows={2}
            placeholder="The lift is out until the end of March; it is three flights up."
            value={extra}
            disabled={!mayConfigure}
            onChange={(event) => setExtra(event.target.value)}
          />
          <p className="field__hint small faint">
            Facts the property record does not carry. The agent answers only from what it is given,
            so anything missing here it will offer to check rather than guess.
          </p>
        </div>

        <fieldset className="field">
          <legend className="field__label">Answer these on its own</legend>
          <div className="checks">
            {capabilities.auto_sendable.map((intent) => (
              <label key={intent}>
                <input
                  type="checkbox"
                  checked={autoSend.includes(intent)}
                  disabled={!mayConfigure || !enabled}
                  onChange={(event) =>
                    setAutoSend((current) =>
                      event.target.checked
                        ? [...current, intent]
                        : current.filter((value) => value !== intent),
                    )
                  }
                />
                <span>{humanise(intent)}</span>
              </label>
            ))}
          </div>
          <p className="field__hint small faint">
            <ShieldAlert size={13} aria-hidden /> Everything else — how to get in, money, dates,
            complaints — is always read by a person first, whatever the agent&rsquo;s confidence.
            That list is fixed in the platform and cannot be widened from here.
          </p>
        </fieldset>

        <fieldset className="field">
          <legend className="field__label">What it may be asked to do</legend>
          <div className="stack stack--tight">
            {(capabilities.actions ?? []).map((action) => {
              const granted = mayDo.includes(action.key)

              return (
                <div key={action.key} className="stack stack--tight bordered p-2">
                  <label>
                    <input
                      type="checkbox"
                      checked={granted}
                      disabled={!mayConfigure || !enabled}
                      onChange={(event) => {
                        setMayDo((current) =>
                          event.target.checked
                            ? [...current, action.key]
                            : current.filter((value) => value !== action.key),
                        )

                        // Ungranting takes the unattended flag with it. Leaving
                        // it set would mean re-granting the capability silently
                        // restored permission to do it unread.
                        if (!event.target.checked) {
                          setMayDoAlone((current) => current.filter((value) => value !== action.key))
                        }
                      }}
                    />
                    <span>{action.label}</span>
                  </label>

                  {/* What it costs when it is wrong, in the platform's words. */}
                  <p className="small faint">{action.consequence}</p>

                  {granted && (
                    <label className="small">
                      <input
                        type="checkbox"
                        checked={mayDoAlone.includes(action.key)}
                        disabled={!mayConfigure || !enabled || !action.may_ever_be_autonomous}
                        onChange={(event) =>
                          setMayDoAlone((current) =>
                            event.target.checked
                              ? [...current, action.key]
                              : current.filter((value) => value !== action.key),
                          )
                        }
                      />
                      <span>
                        {action.may_ever_be_autonomous
                          ? 'and may do it without waiting for a person'
                          : 'always confirmed by a person — this cannot be changed'}
                      </span>
                    </label>
                  )}
                </div>
              )
            })}
          </div>
          <p className="field__hint small faint">
            <ShieldAlert size={13} aria-hidden /> Anything not ticked here is refused, however the
            agent is asked for it. Anything ticked without the second box becomes a proposal you
            approve above. Approving one needs the same permission as doing it by hand, so granting
            a capability does not widen what your colleagues can do.
          </p>
        </fieldset>

        <div className="field">
          <label className="field__label" htmlFor="agent-escalate">
            Always send to a person if the guest mentions
          </label>
          <textarea
            id="agent-escalate"
            rows={3}
            placeholder={'neighbour\npolice\nlawyer'}
            value={escalate}
            disabled={!mayConfigure}
            onChange={(event) => setEscalate(event.target.value)}
          />
          <p className="field__hint small faint">
            One per line, matched against the guest&rsquo;s own words before the agent&rsquo;s
            judgement is consulted.
          </p>
        </div>

        <div className="field">
          <label className="field__label" htmlFor="agent-never">
            Never do
          </label>
          <textarea
            id="agent-never"
            rows={3}
            placeholder={'offer a discount\npromise a late check-out'}
            value={never}
            disabled={!mayConfigure}
            onChange={(event) => setNever(event.target.value)}
          />
        </div>

        <div className="field">
          <label className="field__label" htmlFor="agent-floor">
            Only answer on its own when at least {Math.round(floor * 100)}% sure
          </label>
          <input
            id="agent-floor"
            type="range"
            min={0.5}
            max={1}
            step={0.05}
            value={floor}
            disabled={!mayConfigure}
            onChange={(event) => setFloor(Number(event.target.value))}
          />
        </div>

        {mayConfigure && (
          <div className="row row--between">
            <button type="submit" className="btn btn--primary" disabled={save.isPending}>
              {save.isPending ? 'Saving…' : 'Save brief'}
            </button>
          </div>
        )}
      </form>
    </section>
  )
}

/**
 * What came back when the bot was tested.
 *
 * Three states, kept apart because they need different things done about them: it
 * could not be reached, it answered, or it answered and stated no confidence —
 * which is working, and means its drafts will always wait for a person. Reporting
 * the third as success without saying so would leave somebody wondering for a
 * week why nothing auto-sends.
 */
function BotTestCard({ result }: { result: BotTestResult }) {
  if (!result.reached) {
    return (
      <div className="notice notice--error stack" role="alert">
        <strong>{result.bot ?? 'The bot'} could not be reached.</strong>
        <p className="small">{result.problem}</p>
        <p className="small faint">
          {result.endpoint ?? 'No endpoint is set.'} ·{' '}
          {result.token_sent ? 'a token was sent' : 'no token was sent'}
        </p>
      </div>
    )
  }

  return (
    <div className="notice notice--info stack" role="status">
      <strong>{result.bot} answered.</strong>
      <p className="small">“{result.reply}”</p>
      <p className="small faint">
        Habitat read that as <strong>{result.read_as?.intent}</strong>
        {result.read_as?.stated_confidence === true ? (
          <> at {Math.round((result.read_as.confidence ?? 0) * 100)}% confidence.</>
        ) : (
          <>
            {' '}
            with <strong>no confidence stated</strong>, so its drafts will always wait for a
            person. That is working, not broken — add a <code>confidence</code> field to let it
            answer on its own.
          </>
        )}
      </p>
    </div>
  )
}

/**
 * The bench: one question at a time, and the whole scenario set.
 *
 * The single question is what an operator reaches for ("what does it say if
 * somebody who has not paid asks for the door code?"); the suite is what makes a
 * change to the brief measurable rather than a matter of impression.
 */
function Bench({ property, configuration }: { property: Property; configuration: AgentConfiguration }) {
  const queryClient = useQueryClient()
  const [question, setQuestion] = useState('')
  const [reservationId, setReservationId] = useState('')
  /*
   * Who the question is on behalf of.
   *
   * Not a tone switch. A guest question is answered from guest-safe facts
   * gated on their booking; an operator question is answered from the
   * property's performance figures, gated on what this account may already
   * read elsewhere. Picking the wrong one does not give a worse answer — it
   * gives an answer from the wrong facts entirely.
   */
  const [audience, setAudience] = useState<'guest' | 'operator'>('guest')
  const [run, setRun] = useState<AgentEvalRun | null>(null)

  /*
   * The exchange so far, so this is a conversation rather than a series of
   * unrelated questions.
   *
   * It matters more than it looks. A guest's second message is usually only
   * intelligible after the first — "and what about the one downstairs?" — and an
   * agent asked that cold answers something else entirely. The API has always
   * taken a history; nothing was keeping one.
   */
  const [thread, setThread] = useState<{ guest: string; answer: AgentAnswer }[]>([])

  const bookings = useQuery({
    queryKey: ['agent-bookings', property.id],
    queryFn: () =>
      api.get<Paginated<Reservation>>('reservations', {
        property_id: property.id,
        per_page: 25,
      }),
  })

  const ask = useMutation({
    mutationFn: (asked: string) =>
      api.post<AskResult>(`properties/${property.id}/agent/ask`, {
        question: asked,
        audience,
        reservation_id: reservationId === '' ? null : reservationId,
        // Both halves of every previous turn, which is what makes a follow-up
        // mean what it says.
        history: thread.flatMap((turn) => [
          { role: 'guest', body: turn.guest },
          { role: 'host', body: turn.answer.reply },
        ]),
      }),
    onSuccess: (result, asked) => {
      setThread((current) => [...current, { guest: asked, answer: result.data.answer }])
      setQuestion('')
    },
  })

  /*
   * The same question, down the slow road.
   *
   * Nothing comes back but a row saying it went out. The answer arrives on the
   * callback whenever the bot is finished, and the log below is what notices.
   */
  const askLater = useMutation({
    mutationFn: (asked: string) =>
      api.post<{ data: AgentAsk }>(`properties/${property.id}/agent/ask-later`, {
        question: asked,
        audience,
        reservation_id: reservationId === '' ? null : reservationId,
        history: thread.flatMap((turn) => [
          { role: 'guest', body: turn.guest },
          { role: 'host', body: turn.answer.reply },
        ]),
      }),
    onSuccess: () => {
      setQuestion('')
      void queryClient.invalidateQueries({ queryKey: ['agent-asks', property.id] })
    },
  })

  const evaluate = useMutation({
    mutationFn: () => api.post<{ data: AgentEvalRun }>(`properties/${property.id}/agent/evaluate`),
    onSuccess: (result) => setRun(result.data),
  })

  const failed = ask.error ?? askLater.error ?? evaluate.error

  return (
    <>
      <section className="card mb-3">
        <header className="card__header">
          <h2>
            <Send size={16} aria-hidden /> Talk to{' '}
            {configuration.brief.bot_name ?? configuration.capabilities.provider.name}
          </h2>
          {thread.length > 0 && (
            <button type="button" className="btn btn--sm btn--ghost" onClick={() => setThread([])}>
              Start again
            </button>
          )}
        </header>

        {thread.length > 0 && (
          <div className="card__body stack">
            {thread.map((turn, index) => (
              <div key={index} className="stack">
                <p className="small">
                  <strong>Guest:</strong> {turn.guest}
                </p>
                <AnswerCard answer={turn.answer} />
              </div>
            ))}
          </div>
        )}

        <form
          className="card__body stack"
          onSubmit={(event) => {
            event.preventDefault()
            ask.mutate(question)
          }}
        >
          {failed !== null && failed !== undefined && (
            <div className="notice notice--error" role="alert">
              {failed instanceof ApiError ? failed.message : 'That could not be completed.'}
            </div>
          )}

          <div className="field">
            <label className="field__label" htmlFor="agent-audience">
              Asking as
            </label>
            <select
              id="agent-audience"
              value={audience}
              // Locked once a thread is going, like the booking below: the two
              // audiences are answered from different facts, and switching
              // halfway would make the answers above and below mean different
              // things.
              disabled={thread.length > 0}
              onChange={(event) => setAudience(event.target.value as 'guest' | 'operator')}
            >
              <option value="guest">A guest — what the agent would reply</option>
              <option value="operator">Me — how this property is doing</option>
            </select>
            <p className="field__hint small faint">
              {audience === 'operator'
                ? 'The agent is given this property’s occupancy, rate, revenue and bookings — as far as your own permissions let you see them — and nothing a guest would be told. Nothing here is ever sent to anyone.'
                : 'The agent is given only what a guest may be told. It holds no revenue and no booking list, so it cannot answer a question about performance.'}
            </p>
          </div>

          <div className="field">
            <label className="field__label" htmlFor="agent-question">
              {audience === 'operator'
                ? thread.length === 0
                  ? 'What do you want to know?'
                  : 'And then'
                : thread.length === 0
                  ? 'As the guest'
                  : 'And then the guest says'}
            </label>
            <textarea
              id="agent-question"
              rows={2}
              placeholder={
                audience === 'operator'
                  ? 'How did this flat do last month, and what is on the books?'
                  : 'Can you send me the door code please?'
              }
              value={question}
              onChange={(event) => setQuestion(event.target.value)}
            />
          </div>

          {/*
            Only for a guest question. Which booking a question arrives on is
            what decides a guest's entitlement; an operator's question is about
            the property, and offering a booking picker would suggest the
            figures were scoped to one stay.
          */}
          <div className="field" hidden={audience === 'operator'}>
            <label className="field__label" htmlFor="agent-booking">
              Asking about
            </label>
            <select
              id="agent-booking"
              value={reservationId}
              // Locked once a thread is going: entitlement is decided by which
              // booking the question arrives on, and changing it halfway would
              // make the answers above and below mean different things.
              disabled={thread.length > 0}
              onChange={(event) => setReservationId(event.target.value)}
            >
              <option value="">Somebody with no booking</option>
              {(bookings.data?.data ?? []).map((reservation) => (
                <option key={reservation.id} value={reservation.id}>
                  {reservation.confirmation_code} · {reservation.stay.check_in_date} ·{' '}
                  {reservation.status_label}
                </option>
              ))}
            </select>
            <p className="field__hint small faint">
              Which booking the question arrives on decides what the agent is allowed to know. The
              same question against an unpaid booking and a paid one gets different answers, and
              this is where to see that.
            </p>
          </div>

          <div className="row row--between">
            <span className="small faint">The draft is not sent. Nothing is written to a thread.</span>

            <div className="row">
              {/*
                Offered only where a webhook is configured, and never as the
                default. Waiting is the better experience when waiting works;
                this is for the bot that takes two minutes, where the
                alternative is a request that times out and throws the answer
                away.
              */}
              {configuration.capabilities.webhook_set && (
                <button
                  type="button"
                  className="btn"
                  disabled={askLater.isPending || question.trim().length < 2}
                  onClick={() => askLater.mutate(question)}
                >
                  {askLater.isPending ? 'Sending…' : 'Ask and come back'}
                </button>
              )}

              <button
                type="submit"
                className="btn btn--primary"
                disabled={ask.isPending || question.trim().length < 2}
              >
                {ask.isPending ? 'Asking…' : thread.length === 0 ? 'Draft a reply' : 'Send'}
              </button>
            </div>
          </div>
        </form>
      </section>

      {configuration.capabilities.webhook_set && (
        <AskLog property={property} windowMinutes={configuration.capabilities.webhook_window_minutes} />
      )}

      <section className="card">
        <header className="card__header row row--between">
          <h2>
            <FlaskConical size={16} aria-hidden /> Score it against known answers
          </h2>
          <button
            type="button"
            className="btn"
            onClick={() => evaluate.mutate()}
            disabled={evaluate.isPending}
          >
            {evaluate.isPending ? 'Running…' : 'Run the suite'}
          </button>
        </header>

        <div className="card__body">
          <p className="small muted">
            The same scenarios and the same scoring as <code>php artisan agent:evaluate</code>, so
            the number here is the number in the pipeline. Safety failures — a leaked door code, a
            reply sent that needed a person — must be zero on any provider. Quality failures are
            what a better model and a better brief improve.
          </p>

          {run !== null && <EvalSummary run={run} />}

          {run === null && !configuration.capabilities.provider.is_live && (
            <p className="small faint">
              Running this without a model configured still tests every gate, because the gates are
              in the platform rather than in the prompt.
            </p>
          )}
        </div>
      </section>
    </>
  )
}

/**
 * Questions fired at a webhook, and what came back.
 *
 * The whole reason this panel exists is the pending row. An asynchronous feature
 * whose screen shows nothing between asking and answering reads as a button that
 * did not work, and the operator asks again — which starts a second agent run
 * they pay for. So an ask that is still out says so, with when it went and when
 * it gives up.
 *
 * It polls only while something is actually waiting. A page left open on a
 * property whose asks have all settled stops asking the server about them.
 */
function AskLog({ property, windowMinutes }: { property: Property; windowMinutes: number }) {
  const asks = useQuery({
    queryKey: ['agent-asks', property.id],
    queryFn: () => api.get<{ data: AgentAsk[] }>(`properties/${property.id}/agent/asks`),
    refetchInterval: (query) =>
      (query.state.data?.data ?? []).some((ask) => ask.is_waiting) ? 5_000 : false,
  })

  const rows = asks.data?.data ?? []

  return (
    <section className="card mb-3">
      <header className="card__header row row--between">
        <h2>
          <Clock size={16} aria-hidden /> Asked and waiting
        </h2>
        {rows.some((ask) => ask.is_waiting) && (
          <span className="small faint">Checking every few seconds…</span>
        )}
      </header>

      <div className="card__body stack">
        {rows.length === 0 ? (
          <p className="small muted">
            Nothing asked this way yet. Questions fired at the webhook appear here, answered or
            still out — they lapse after {windowMinutes} minutes if nothing replies.
          </p>
        ) : (
          rows.map((ask) => <AskRow key={ask.id} ask={ask} />)
        )}
      </div>
    </section>
  )
}

function AskRow({ ask }: { ask: AgentAsk }) {
  const status = {
    pending: { label: ask.is_waiting ? 'Waiting' : 'Lapsing', colour: 'amber' as const },
    answered: { label: 'Answered', colour: 'emerald' as const },
    failed: { label: 'No answer', colour: 'rose' as const },
    expired: { label: 'Timed out', colour: 'slate' as const },
  }[ask.status]

  return (
    <div className="stack bordered p-2">
      <div className="row row--between">
        <p className="small">
          <strong>Asked:</strong> {ask.question}
        </p>
        <Chip label={status.label} colour={status.colour} />
      </div>

      <p className="small faint">
        {ask.bot_name ?? ask.endpoint_host ?? 'the webhook'}
        {ask.asked_at !== null && <> · sent {new Date(ask.asked_at).toLocaleTimeString()}</>}
        {ask.is_waiting && ask.expires_at !== null && (
          <> · gives up at {new Date(ask.expires_at).toLocaleTimeString()}</>
        )}
        {ask.answered_at !== null && (
          <> · back at {new Date(ask.answered_at).toLocaleTimeString()}</>
        )}
      </p>

      {ask.status === 'pending' && (
        <p className="small muted">
          Out with the bot. This updates on its own when the answer lands — there is no need to ask
          again, and asking again starts a second run.
        </p>
      )}

      {ask.reply !== null && (
        <>
          <p>{ask.reply}</p>

          <p className="small faint">
            {ask.intent !== null && <>Read as {humanise(ask.intent)}</>}
            {ask.confidence !== null && <> · {Math.round(ask.confidence * 100)}% sure</>}
          </p>

          {ask.would_auto_send ? (
            <p className="small">
              <Chip label="Would send on its own" colour="amber" />
            </p>
          ) : (
            ask.held_because !== null && (
              <p className="small muted">
                <ShieldAlert size={13} aria-hidden /> Held: {ask.held_because}
              </p>
            )
          )}

          {ask.withheld.length > 0 && (
            <p className="small muted">
              The bot was not given the arrival details: {ask.withheld.join(' ')}
            </p>
          )}
        </>
      )}

      {ask.failure !== null && (
        <p className="small" role="alert">
          {ask.failure}
        </p>
      )}
    </div>
  )
}

function AnswerCard({ answer }: { answer: AgentAnswer }) {
  return (
    <div className="card__body agent-answer">
      <div className="row row--between mb-2">
        <div className="row">
          <Chip label={humanise(answer.intent)} colour="sky" />
          <Chip label={`${Math.round(answer.confidence * 100)}% sure`} colour="slate" />
          {answer.would_auto_send ? (
            <Chip label="Would send on its own" colour="emerald" />
          ) : (
            <Chip label="Held for a person" colour="amber" />
          )}
          {answer.is_simulated && <Chip label="Simulated" colour="zinc" />}
        </div>
        <span className="small faint">
          {answer.provider}
          {answer.model === null ? '' : ` · ${answer.model}`} · {answer.tokens} tokens
        </span>
      </div>

      <blockquote className="agent-draft">{answer.reply}</blockquote>

      {answer.held_because !== null && (
        <p className="small muted">
          <strong>Held because:</strong> {answer.held_because}
        </p>
      )}

      {answer.withheld.length > 0 && (
        <div className="notice notice--info">
          <strong>The agent was not given the arrival details for this guest.</strong>
          <ul className="small">
            {answer.withheld.map((reason) => (
              <li key={reason}>{reason}</li>
            ))}
          </ul>
          The door code and wifi password are left out of the prompt entirely in this case, rather
          than the agent being asked to keep them to itself.
        </div>
      )}

      <p className="small faint">
        Facts it was given: {answer.used_facts.join(', ') || 'none'}.
      </p>
    </div>
  )
}

function EvalSummary({ run }: { run: AgentEvalRun }) {
  return (
    <>
      <div className={run.unsafe > 0 ? 'notice notice--error' : 'notice notice--info'} role="status">
        {run.unsafe > 0 ? (
          <strong>
            {run.unsafe} of {run.total} scenarios were unsafe. Nothing else matters until that is
            zero.
          </strong>
        ) : (
          <strong>
            Safety: {run.total}/{run.total} clean. Quality: {run.passed}/{run.total} fully correct.
          </strong>
        )}
      </div>

      <div className="table-wrap">
        <table className="data">
          <thead>
            <tr>
              <th>Scenario</th>
              <th>Read as</th>
              <th>Outcome</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
            {run.results.map((result) => (
              <tr key={result.name}>
                <td>
                  <div className="strong">{result.name}</div>
                  <div className="small faint">{result.question}</div>
                </td>
                <td className="small">
                  {humanise(result.answer.intent)}
                  <div className="small faint">
                    {Math.round(result.answer.confidence * 100)}% sure
                  </div>
                </td>
                <td>
                  {!result.safe ? (
                    <Chip label="Unsafe" colour="rose" />
                  ) : result.passed ? (
                    <Chip label="Pass" colour="emerald" />
                  ) : (
                    <Chip label="Weak" colour="amber" />
                  )}
                </td>
                <td className="small">
                  {result.safety_failures.map((failure) => (
                    <div key={failure} className="danger">
                      {failure}
                    </div>
                  ))}
                  {result.quality_failures.map((failure) => (
                    <div key={failure} className="muted">
                      {failure}
                    </div>
                  ))}
                  {result.safety_failures.length === 0 &&
                    result.quality_failures.length === 0 &&
                    '—'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  )
}

function humanise(intent: string): string {
  const words = intent.replace(/_/g, ' ')

  return words.charAt(0).toUpperCase() + words.slice(1)
}

function lines(value: string): string[] {
  return value
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '')
}
