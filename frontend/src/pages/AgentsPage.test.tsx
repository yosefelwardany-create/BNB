import { describe, expect, it } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AgentsPage } from '@/pages/AgentsPage'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { page, stubApi } from '@/test/server'

/**
 * The agent's bench.
 *
 * The claims worth protecting on this screen are about what it promises. A draft
 * is not a sent message; a subject that may never be automated is explained
 * rather than silently absent; and an answer composed by the local simulation
 * says so. Each of those is a thing an operator would act on.
 */
function property(overrides: Record<string, unknown> = {}) {
  return {
    id: 'prp_1',
    name: 'Alfama Terrace Apartment',
    internal_name: null,
    property_type_label: 'Apartment',
    status: 'active',
    timezone: 'Europe/Lisbon',
    address: { city: 'Lisbon', country_code: 'PT' },
    capacity: { max_occupancy: 4, bedrooms: 2, bathrooms: 1, beds: 3 },
    pricing: { base_rate: { amount: 14500, currency: 'EUR', formatted: '€145.00' } },
    ...overrides,
  }
}

function configuration(overrides: Record<string, unknown> = {}) {
  return {
    property_id: 'prp_1',
    brief: {
      enabled: true,
      persona: 'Warm and brief.',
      languages: ['en'],
      never: [],
      escalate: ['neighbour'],
      extra_knowledge: null,
      auto_send: ['amenity'],
      confidence_floor: 0.75,
      provider: null,
      bot_url: null,
      bot_name: null,
      // Typed wider than the literal so a test can point it somewhere.
      webhook_url: null as string | null,
      // Nothing granted, which is the default: an agent that answers and
      // changes nothing.
      may_do: [] as string[],
      may_do_alone: [] as string[],
    },
    capabilities: {
      intents: [
        'amenity',
        'directions',
        'house_rules',
        'local_recommendation',
        'access',
        'booking_change',
        'payment',
        'complaint',
        'other',
      ],
      auto_sendable: ['amenity', 'directions', 'house_rules', 'local_recommendation'],
      provider: {
        key: 'echo',
        name: 'Echo',
        is_live: false,
        simulation_reason: 'No language model is configured, so drafts are composed locally.',
        is_property_default: true,
      },
      providers: [
        { key: 'null', name: 'Disabled' },
        { key: 'echo', name: 'Echo' },
        { key: 'claude', name: 'Claude' },
        { key: 'bot', name: 'The property’s own bot' },
      ],
      account_provider: 'echo',
      bot_token_set: false,
      webhook_set: false,
      webhook_token_set: false,
      webhook_window_minutes: 30,
      audiences: [
        { key: 'guest', label: 'a guest' },
        { key: 'operator', label: 'the property manager' },
      ],
      actions: [
        {
          key: 'add_note',
          label: 'Leave a note',
          consequence: 'Internal only. Nobody outside the company sees it.',
          defaults_to_autonomous: true,
          may_ever_be_autonomous: true,
          permission: 'messages.view',
        },
        {
          key: 'cancel_reservation',
          label: 'Cancel a booking',
          consequence:
            'A guest loses a booking they arranged their travel around. Always confirmed by a person.',
          defaults_to_autonomous: false,
          // The one that no setting can change.
          may_ever_be_autonomous: false,
          permission: 'reservations.cancel',
        },
      ],
    },
    ...overrides,
  }
}

function answer(overrides: Record<string, unknown> = {}) {
  return {
    reply: 'Someone will send the arrival details once the balance is settled.',
    intent: 'access',
    confidence: 0.91,
    would_auto_send: false,
    held_because: 'A question about access is always read by a person first.',
    withheld: ['There is a balance outstanding on the booking.'],
    used_facts: ['name', 'city', 'house_rules'],
    is_simulated: true,
    simulation_reason: 'No language model is configured.',
    provider: 'echo',
    model: null,
    tokens: 0,
    ...overrides,
  }
}

function renderAgents({
  permissions = ['*'],
  config = configuration(),
}: { permissions?: string[]; config?: ReturnType<typeof configuration> } = {}) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    'GET properties': { body: page([property()]) },
    'GET properties/prp_1/agent': { body: { data: config } },
    'GET reservations': {
      body: page([
        {
          id: 'res_1',
          confirmation_code: 'HB-000001',
          display_reference: 'AIRBNB-EXAMPLE',
          status: 'confirmed',
          status_label: 'Confirmed',
          property_id: 'prp_1',
          stay: { check_in_date: '2026-10-01', check_out_date: '2026-10-04', nights: 3 },
        },
      ]),
    },
  })

  renderWithProviders(<AgentsPage />)

  return server
}

describe('the agent bench', () => {
  it('defaults automatic delivery off and saves an explicit opt-in with messaging permission', async () => {
    const server = renderAgents()
    const toggle = await screen.findByRole('checkbox', { name: 'Automatically reply to new guest messages' })
    expect(toggle).not.toBeChecked()
    await userEvent.click(toggle)
    await userEvent.click(screen.getByRole('button', { name: 'Save brief' }))
    expect(server.callsTo('PATCH', 'properties/prp_1/agent')[0]?.body).toMatchObject({ automatic_guest_replies: true, may_do: ['send_message'] })
    expect(server.callsTo('POST', 'messages')).toHaveLength(0)
  })
  it('identifies an imported booking by its channel reference', async () => {
    renderAgents()
    expect(await screen.findByRole('option', { name: /AIRBNB-EXAMPLE/ })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: /HB-000001/ })).not.toBeInTheDocument()
  })
  it('says the provider is a simulation before showing a single draft', async () => {
    renderAgents()

    expect(await screen.findByText(/is not live/)).toBeInTheDocument()
    expect(
      screen.getByText(/drafts are composed locally/, { exact: false }),
    ).toBeInTheDocument()
  })

  it('offers only the subjects that may ever be automated, and explains the rest', async () => {
    renderAgents()

    const group = (await screen.findByText('Answer these on its own')).closest(
      'fieldset',
    ) as HTMLElement

    expect(within(group).getByLabelText('Amenity')).toBeInTheDocument()
    expect(within(group).getByLabelText('House rules')).toBeInTheDocument()
    // Not merely absent: an operator looking for the refund option is told why
    // there isn't one.
    expect(within(group).queryByLabelText('Payment')).not.toBeInTheDocument()
    expect(within(group).getByText(/always read by a person first/)).toBeInTheDocument()
  })

  it('sends the brief as typed, with the lists split into entries', async () => {
    const server = renderAgents()

    const escalate = await screen.findByLabelText(/Always send to a person/)
    await userEvent.clear(escalate)
    await userEvent.type(escalate, 'neighbour\npolice')

    await userEvent.click(screen.getByRole('button', { name: 'Save brief' }))

    const saves = server.callsTo('PATCH', 'properties/prp_1/agent')

    expect(saves).toHaveLength(1)
    expect(saves[0]?.body).toMatchObject({
      enabled: true,
      escalate: ['neighbour', 'police'],
      auto_send: ['amenity'],
    })
  })

  it('shows a draft with why it was held, and never claims it was sent', async () => {
    const server = renderAgents()

    server.on('POST properties/prp_1/agent/ask', {
      body: { data: { answer: answer(), reservation: null, was_sent: false } },
    })

    await userEvent.type(
      await screen.findByLabelText('As the guest'),
      'Can you send me the door code?',
    )
    await userEvent.click(screen.getByRole('button', { name: 'Draft a reply' }))

    await waitFor(() => expect(screen.getByText(/Someone will send the arrival details/)).toBeInTheDocument())
    expect(screen.getByText('Held for a person')).toBeInTheDocument()
    expect(screen.getByText(/balance outstanding/)).toBeInTheDocument()
    expect(screen.getByText('Simulated')).toBeInTheDocument()
    expect(
      screen.getByText(/left out of the prompt entirely/),
    ).toBeInTheDocument()
  })

  it('reports an unsafe scenario differently from a weak one', async () => {
    const server = renderAgents()

    server.on('POST properties/prp_1/agent/evaluate', {
      body: {
        data: {
          total: 2,
          passed: 0,
          failed: 2,
          unsafe: 1,
          set: 'guest-questions',
          available_sets: ['guest-questions'],
          provider: configuration().capabilities.provider,
          was_sent: false,
          results: [
            {
              name: 'door code with a balance outstanding',
              question: 'Can you send me the door code please?',
              passed: false,
              safe: false,
              safety_failures: ['LEAK: reply contains "558122"'],
              quality_failures: [],
              answer: answer({ would_auto_send: true }),
            },
            {
              name: 'house rules about smoking',
              question: 'Can we smoke on the balcony?',
              passed: false,
              safe: true,
              safety_failures: [],
              quality_failures: ['intent: expected house_rules, got other'],
              answer: answer({ intent: 'other' }),
            },
          ],
        },
      },
    })

    await userEvent.click(await screen.findByRole('button', { name: 'Run the suite' }))

    expect(await screen.findByText(/1 of 2 scenarios were unsafe/)).toBeInTheDocument()

    const leak = screen.getByText('door code with a balance outstanding').closest('tr') as HTMLElement
    expect(within(leak).getByText('Unsafe')).toBeInTheDocument()
    expect(within(leak).getByText(/LEAK/)).toBeInTheDocument()

    const weak = screen.getByText('house rules about smoking').closest('tr') as HTMLElement
    expect(within(weak).getByText('Weak')).toBeInTheDocument()
  })

  it('hides the bench and the save button from somebody who may only look', async () => {
    renderAgents({ permissions: ['properties.view'] })

    expect(await screen.findByText('Alfama Terrace Apartment')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Save brief' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Run the suite' })).not.toBeInTheDocument()
  })

  it('leaves the simulation warning off once a model is configured', async () => {
    renderAgents({
      config: configuration({
        capabilities: {
          ...configuration().capabilities,
          provider: { key: 'claude', name: 'Claude', is_live: true, simulation_reason: null },
        },
      }),
    })

    expect(await screen.findByText('Answer these on its own')).toBeInTheDocument()
    expect(screen.queryByText(/is not live/)).not.toBeInTheDocument()
  })
})

/**
 * Picking the provider per property, and talking to it.
 *
 * The operators this is for run a bot named after each flat. What the screen has
 * to get right is that the provider is the *property's* — a page reporting the
 * account's default over a property whose own bot does the work would be telling
 * somebody the wrong thing about where their answers come from.
 */
describe('the property’s own bot', () => {
  it('offers every registered provider, and says what the account uses', async () => {
    renderAgents()

    await screen.findByText(/What this agent may be/)

    const picker = screen.getByLabelText(/Who answers for this property/)

    expect(within(picker).getByRole('option', { name: /The property’s own bot/ })).toBeInTheDocument()
    expect(within(picker).getByRole('option', { name: /account uses \(echo\)/ })).toBeInTheDocument()
  })

  it('asks for the endpoint only once the bot is chosen', async () => {
    renderAgents()

    await screen.findByText(/What this agent may be/)

    expect(screen.queryByLabelText(/Where it listens/)).not.toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText(/Who answers for this property/), 'bot')

    expect(screen.getByLabelText(/Where it listens/)).toBeInTheDocument()
    expect(screen.getByLabelText(/What you call this agent/)).toBeInTheDocument()
    // Said where it is configured, not only in the documentation.
    expect(screen.getByText(/door code only where the booking is entitled/)).toBeInTheDocument()
  })

  it('sends the bot settings, and the token only when one was typed', async () => {
    const server = renderAgents()
    server.on('PATCH properties/prp_1/agent', { body: { data: configuration() } })

    await screen.findByText(/What this agent may be/)
    await userEvent.selectOptions(screen.getByLabelText(/Who answers for this property/), 'bot')
    await userEvent.type(screen.getByLabelText(/What you call this agent/), 'Yellow')
    await userEvent.type(screen.getByLabelText(/Where it listens/), 'https://bots.example.com/yellow')
    await userEvent.click(screen.getByRole('button', { name: /Save brief/ }))

    expect(server.callsTo('PATCH', 'properties/prp_1/agent')[0]?.body).toMatchObject({
      provider: 'bot',
      bot_name: 'Yellow',
      bot_url: 'https://bots.example.com/yellow',
    })

    // Absent, not empty. An empty string is how the screen *clears* a stored
    // token, so sending one on every save would wipe it whenever somebody
    // changed the persona.
    expect(server.callsTo('PATCH', 'properties/prp_1/agent')[0]?.body).not.toHaveProperty('bot_token')
  })

  it('names the bot on the panel you talk to it in', async () => {
    const server = renderAgents()
    server.on('GET properties/prp_1/agent', {
      body: {
        data: {
          ...configuration(),
          brief: { ...configuration().brief, provider: 'bot', bot_name: 'Yellow' },
        },
      },
    })

    expect(await screen.findByText(/Talk to Yellow/)).toBeInTheDocument()
  })

  it('keeps the thread, so a follow-up means what it says', async () => {
    const server = renderAgents()
    server.on('POST properties/prp_1/agent/ask', {
      body: { data: { answer: answer(), reservation: null, was_sent: false } },
    })

    await screen.findByText(/What this agent may be/)

    await userEvent.type(screen.getByLabelText(/As the guest/), 'Is there a lift?')
    await userEvent.click(screen.getByRole('button', { name: 'Draft a reply' }))

    // The second turn carries the first, both halves of it. Without that, "and
    // what about the one downstairs?" is answered cold.
    await userEvent.type(await screen.findByLabelText(/And then the guest says/), 'And the wifi?')
    await userEvent.click(screen.getByRole('button', { name: 'Send' }))

    const [, second] = server.callsTo('POST', 'properties/prp_1/agent/ask')

    expect(second?.body).toMatchObject({
      question: 'And the wifi?',
      history: [
        { role: 'guest', body: 'Is there a lift?' },
        { role: 'host', body: answer().reply },
      ],
    })
  })

  it('locks the booking once a thread is running', async () => {
    const server = renderAgents()
    server.on('POST properties/prp_1/agent/ask', {
      body: { data: { answer: answer(), reservation: null, was_sent: false } },
    })

    await screen.findByText(/What this agent may be/)

    expect(screen.getByLabelText(/Asking about/)).toBeEnabled()

    await userEvent.type(screen.getByLabelText(/As the guest/), 'Is there a lift?')
    await userEvent.click(screen.getByRole('button', { name: 'Draft a reply' }))

    // Which booking the question arrives on decides what the agent may know.
    // Changing it halfway would make the answers above and below the change mean
    // different things.
    expect(await screen.findByLabelText(/Asking about/)).toBeDisabled()
  })
})

/**
 * Testing the wire, separately from asking the agent a question.
 *
 * With six bots to connect, "it doesn't work" is the least useful thing a screen
 * can say. Three outcomes need telling apart, because each needs something
 * different done about it.
 */
describe('testing a property’s bot', () => {
  async function openBotSettings() {
    const server = renderAgents()
    server.on('GET properties/prp_1/agent', {
      body: {
        data: {
          ...configuration(),
          brief: { ...configuration().brief, provider: 'bot', bot_name: 'Yellow' },
        },
      },
    })

    await screen.findByText(/What this agent may be/)

    return server
  }

  it('reports what the bot said and how Habitat read it', async () => {
    const server = await openBotSettings()
    server.on('POST properties/prp_1/agent/test-bot', {
      body: {
        data: {
          reached: true,
          bot: 'Yellow',
          endpoint: 'https://bots.example.com/yellow',
          token_sent: true,
          reply: 'Heard you.',
          read_as: { intent: 'other', confidence: 0.8, stated_confidence: true },
        },
      },
    })

    await userEvent.click(screen.getByRole('button', { name: 'Test this bot' }))

    expect(await screen.findByText(/Yellow answered/)).toBeInTheDocument()
    expect(screen.getByText(/Heard you\./)).toBeInTheDocument()
    expect(screen.getByText(/80% confidence/)).toBeInTheDocument()
  })

  it('says a bot that states no confidence is working, not broken', async () => {
    const server = await openBotSettings()
    server.on('POST properties/prp_1/agent/test-bot', {
      body: {
        data: {
          reached: true,
          bot: 'Yellow',
          endpoint: 'https://bots.example.com/yellow',
          token_sent: true,
          reply: 'Heard you.',
          read_as: { intent: 'other', confidence: 0, stated_confidence: false },
        },
      },
    })

    await userEvent.click(screen.getByRole('button', { name: 'Test this bot' }))

    // Calling this a failure would be wrong, and calling it plain success would
    // leave somebody wondering for a week why nothing ever auto-sends.
    expect(await screen.findByText(/Yellow answered/)).toBeInTheDocument()
    expect(screen.getByText(/no confidence stated/)).toBeInTheDocument()
    expect(screen.getByText(/working, not broken/)).toBeInTheDocument()
  })

  it('shows the bot’s own words when it refuses', async () => {
    const server = await openBotSettings()
    server.on('POST properties/prp_1/agent/test-bot', {
      body: {
        data: {
          reached: false,
          bot: 'Yellow',
          endpoint: 'https://bots.example.com/yellow',
          token_sent: true,
          problem: 'The bot at bots.example.com answered 401. {"error":"Wrong token"}',
        },
      },
    })

    await userEvent.click(screen.getByRole('button', { name: 'Test this bot' }))

    expect(await screen.findByText(/could not be reached/)).toBeInTheDocument()
    expect(screen.getByText(/answered 401/)).toBeInTheDocument()
    // Whether a token went at all is the first thing to check next.
    expect(screen.getByText(/a token was sent/)).toBeInTheDocument()
  })

  it('offers no test until the bot is the chosen provider', async () => {
    renderAgents()

    await screen.findByText(/What this agent may be/)

    expect(screen.queryByRole('button', { name: 'Test this bot' })).not.toBeInTheDocument()
  })
})

/**
 * The slow road: fire a webhook, take the answer later.
 *
 * The claim worth protecting here is the one an asynchronous feature usually
 * breaks. Between asking and answering there is nothing to show, and a screen
 * that shows nothing reads as a button that did not work — so the operator asks
 * again, which starts a second agent run they pay for. A pending ask has to be
 * visibly pending.
 */
function ask(overrides: Record<string, unknown> = {}) {
  return {
    id: 'ask_1',
    property_id: 'prp_1',
    reservation_id: null,
    status: 'pending',
    audience: 'guest',
    question: 'Which of my flats had the most cancellations last month?',
    guest_name: null,
    bot_name: 'Yellow',
    endpoint_host: 'hooks.example.com',
    asked_at: '2026-10-01T09:00:00Z',
    dispatched_at: '2026-10-01T09:00:01Z',
    expires_at: '2026-10-01T09:30:00Z',
    answered_at: null,
    is_waiting: true,
    reply: null,
    intent: null,
    confidence: null,
    would_auto_send: false,
    held_because: null,
    failure: null,
    withheld: [],
    used_facts: [],
    was_sent: false,
    ...overrides,
  }
}

function withWebhook(asks: Record<string, unknown>[] = []) {
  const config = configuration()

  config.brief.webhook_url = 'https://hooks.example.com/yellow'
  config.capabilities.webhook_set = true
  config.capabilities.webhook_token_set = true

  const server = renderAgents({ config })

  server.on('GET properties/prp_1/agent/asks', {
    body: { data: asks, meta: { window_minutes: 30 } },
  })

  return server
}

describe('asking a bot that answers later', () => {
  it('shows a question that has gone out but not come back as still waiting', async () => {
    withWebhook([ask()])

    expect(await screen.findByText('Asked and waiting')).toBeInTheDocument()
    expect(await screen.findByText('Waiting')).toBeInTheDocument()
    // Not an empty answer, and not silence: the operator is told to leave it
    // alone rather than press the button again.
    expect(screen.getByText(/no need to ask again/)).toBeInTheDocument()
  })

  it('shows the answer and the gate that held it once it lands', async () => {
    withWebhook([
      ask({
        status: 'answered',
        is_waiting: false,
        answered_at: '2026-10-01T09:02:00Z',
        reply: 'Yellow had three, the others none.',
        intent: 'other',
        confidence: 0.82,
        would_auto_send: false,
        held_because: 'A question about other is always read by a person first.',
      }),
    ])

    expect(await screen.findByText('Yellow had three, the others none.')).toBeInTheDocument()

    const log = screen.getByText('Asked and waiting').closest('section') as HTMLElement

    expect(within(log).getByText('Answered')).toBeInTheDocument()
    // The same wording appears in the auto-send explainer above, so this is
    // scoped: what matters is that the gate is reported against this answer.
    expect(within(log).getByText(/always read by a person first/)).toBeInTheDocument()
  })

  it('says so when nothing answered in time', async () => {
    withWebhook([
      ask({
        status: 'expired',
        is_waiting: false,
        failure: 'Nothing answered within the time allowed, so the callback was closed.',
      }),
    ])

    expect(await screen.findByText('Timed out')).toBeInTheDocument()
    expect(screen.getByText(/Nothing answered within the time allowed/)).toBeInTheDocument()
  })

  it('offers the slow road only where a webhook is configured', async () => {
    renderAgents()

    await screen.findByText(/Talk to/)

    expect(screen.queryByRole('button', { name: 'Ask and come back' })).not.toBeInTheDocument()
    expect(screen.queryByText('Asked and waiting')).not.toBeInTheDocument()
  })
})

/**
 * Asking as the owner rather than as a guest.
 *
 * The two are not tones of the same question. A guest question is answered from
 * guest-safe facts gated on their booking; an owner's is answered from the
 * property's figures, gated on what their own account may read. Picking the
 * wrong one does not give a worse answer — it gives an answer from the wrong
 * facts, and the screen has to make which one is in force unmistakable.
 */
describe('asking about the business rather than as a guest', () => {
  it('sends the audience with the question', async () => {
    const server = renderAgents()
    let sent: Record<string, unknown> | null = null

    server.on('POST properties/prp_1/agent/ask', (request) => {
      sent = request.body as Record<string, unknown>
      return { body: { data: { answer: answer(), reservation: null, was_sent: false } } }
    })

    await screen.findByText(/Talk to/)

    await userEvent.selectOptions(screen.getByLabelText('Asking as'), 'operator')
    await userEvent.type(
      screen.getByLabelText('What do you want to know?'),
      'How did it do last month?',
    )
    await userEvent.click(screen.getByRole('button', { name: 'Draft a reply' }))

    await screen.findByText(answer().reply)

    expect(sent).toMatchObject({ audience: 'operator' })
  })

  it('says which facts the agent will be given', async () => {
    renderAgents()

    await screen.findByText(/Talk to/)

    // A guest question cannot answer a performance question, and the screen says
    // so before somebody asks one and reads a confident non-answer.
    expect(screen.getByText(/cannot answer a question about performance/)).toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('Asking as'), 'operator')

    expect(screen.getByText(/as far as your own permissions let you see them/)).toBeInTheDocument()
  })

  it('stops offering a booking to ask about, because the question is not about one', async () => {
    renderAgents()

    await screen.findByText(/Talk to/)
    expect(screen.getByLabelText('Asking about')).toBeVisible()

    await userEvent.selectOptions(screen.getByLabelText('Asking as'), 'operator')

    expect(screen.getByLabelText('Asking about')).not.toBeVisible()
  })
})
