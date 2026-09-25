import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bot, FlaskConical, Send, ShieldAlert } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type {
  AgentAnswer,
  AgentConfiguration,
  AgentEvalRun,
  Paginated,
  Property,
  Reservation,
} from '@/api/types'
import { Chip } from '@/components/Chip'
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

  const [propertyId, setPropertyId] = useState<string | null>(null)

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
          <h1>Guest agent</h1>
          <div className="page-header__subtitle">
            One agent per property, configured from that property&rsquo;s own facts. Drafts only —
            nothing on this screen reaches a guest.
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
  const [floor, setFloor] = useState(brief.confidence_floor)

  const save = useMutation({
    mutationFn: () =>
      api.patch<{ data: AgentConfiguration }>(`properties/${property.id}/agent`, {
        enabled,
        persona,
        extra_knowledge: extra.trim() === '' ? null : extra.trim(),
        never: lines(never),
        escalate: lines(escalate),
        auto_send: autoSend,
        confidence_floor: floor,
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
 * The bench: one question at a time, and the whole scenario set.
 *
 * The single question is what an operator reaches for ("what does it say if
 * somebody who has not paid asks for the door code?"); the suite is what makes a
 * change to the brief measurable rather than a matter of impression.
 */
function Bench({ property, configuration }: { property: Property; configuration: AgentConfiguration }) {
  const [question, setQuestion] = useState('')
  const [reservationId, setReservationId] = useState('')
  const [answer, setAnswer] = useState<AskResult['data'] | null>(null)
  const [run, setRun] = useState<AgentEvalRun | null>(null)

  const bookings = useQuery({
    queryKey: ['agent-bookings', property.id],
    queryFn: () =>
      api.get<Paginated<Reservation>>('reservations', {
        property_id: property.id,
        per_page: 25,
      }),
  })

  const ask = useMutation({
    mutationFn: () =>
      api.post<AskResult>(`properties/${property.id}/agent/ask`, {
        question,
        reservation_id: reservationId === '' ? null : reservationId,
      }),
    onSuccess: (result) => setAnswer(result.data),
  })

  const evaluate = useMutation({
    mutationFn: () => api.post<{ data: AgentEvalRun }>(`properties/${property.id}/agent/evaluate`),
    onSuccess: (result) => setRun(result.data),
  })

  const failed = ask.error ?? evaluate.error

  return (
    <>
      <section className="card mb-3">
        <header className="card__header">
          <h2>
            <Send size={16} aria-hidden /> Ask it something
          </h2>
        </header>

        <form
          className="card__body stack"
          onSubmit={(event) => {
            event.preventDefault()
            ask.mutate()
          }}
        >
          {failed !== null && failed !== undefined && (
            <div className="notice notice--error" role="alert">
              {failed instanceof ApiError ? failed.message : 'That could not be completed.'}
            </div>
          )}

          <div className="field">
            <label className="field__label" htmlFor="agent-question">
              As the guest
            </label>
            <textarea
              id="agent-question"
              rows={2}
              placeholder="Can you send me the door code please?"
              value={question}
              onChange={(event) => setQuestion(event.target.value)}
            />
          </div>

          <div className="field">
            <label className="field__label" htmlFor="agent-booking">
              Asking about
            </label>
            <select
              id="agent-booking"
              value={reservationId}
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
            <button
              type="submit"
              className="btn btn--primary"
              disabled={ask.isPending || question.trim().length < 2}
            >
              {ask.isPending ? 'Asking…' : 'Draft a reply'}
            </button>
          </div>
        </form>

        {answer !== null && <AnswerCard answer={answer.answer} />}
      </section>

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
