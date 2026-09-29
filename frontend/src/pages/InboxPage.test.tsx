import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { InboxPage } from '@/pages/InboxPage'
import { conversation, message, session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { page, stubApi } from '@/test/server'

/**
 * The inbox.
 *
 * Provenance is the point. What a person wrote, what an automation sent, what a
 * model drafted and what never left the building are four different things, and
 * a manager reading a thread has to be able to tell them apart at a glance.
 *
 * The one that is not cosmetic is delivery. This installation has no mail
 * transport configured, so messages are written to a log; showing them as sent
 * would have an operator believe a guest was told something they were never
 * told.
 */
function renderInbox(
  messages: ReturnType<typeof message>[],
  permissions: string[] = ['messages.view', 'messages.send'],
) {
  return stubApi({
    'GET auth/me': { body: session({ permissions }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    'GET conversations': { body: page([conversation()]) },
    'GET conversations/con_1': { body: { data: conversation({ messages }) } },
  })
}

async function bubbleFor(body: string) {
  const text = await screen.findByText(body)

  return text.closest('.bubble') as HTMLElement
}

describe('message provenance', () => {
  it('never shows a locally recorded message as delivered', async () => {
    renderInbox([message()])
    renderWithProviders(<InboxPage />)

    const bubble = await bubbleFor('Your keys are in the lockbox by the door.')

    expect(within(bubble).getByText('Simulated delivery')).toBeInTheDocument()
  })

  it('leaves the chip off when something really was sent', async () => {
    renderInbox([
      message({
        body: 'Checked in.',
        delivery: { transport: 'smtp', simulated: false, reason: null, fallback_from: null },
      }),
    ])
    renderWithProviders(<InboxPage />)

    const bubble = await bubbleFor('Checked in.')

    expect(within(bubble).queryByText('Simulated delivery')).not.toBeInTheDocument()
  })

  it('distinguishes an automation and a model draft from a person', async () => {
    renderInbox([
      message({
        id: 'msg_2',
        body: 'Welcome! Check-in is from 15:00.',
        automation_rule_id: 'rul_1',
        is_ai_generated: true,
      }),
    ])
    renderWithProviders(<InboxPage />)

    const bubble = await bubbleFor('Welcome! Check-in is from 15:00.')

    expect(within(bubble).getByText('Automated')).toBeInTheDocument()
    expect(within(bubble).getByText('AI drafted')).toBeInTheDocument()
  })

  it('marks an internal note, because the cost of confusing one is a guest reading it', async () => {
    renderInbox([
      message({ id: 'msg_3', body: 'Guest has complained twice before.', is_internal_note: true }),
    ])
    renderWithProviders(<InboxPage />)

    const bubble = await bubbleFor('Guest has complained twice before.')

    expect(within(bubble).getByText('Internal')).toBeInTheDocument()
    expect(bubble).toHaveClass('bubble--note')
  })

  it('shows why a message failed rather than only that it did', async () => {
    renderInbox([
      message({
        id: 'msg_4',
        body: 'Your invoice is attached.',
        failed_at: '2025-06-01T11:00:00+00:00',
        failure_reason: 'Mailbox does not exist',
      }),
    ])
    renderWithProviders(<InboxPage />)

    const bubble = await bubbleFor('Your invoice is attached.')

    expect(within(bubble).getByText('Mailbox does not exist')).toBeInTheDocument()
  })
})

describe('the composer', () => {
  it('is there for somebody who may send', async () => {
    renderInbox([message()])
    renderWithProviders(<InboxPage />)

    expect(await screen.findByRole('button', { name: 'Send' })).toBeInTheDocument()
  })

  it('is not offered to somebody who may only read', async () => {
    renderInbox([message()], ['messages.view'])
    renderWithProviders(<InboxPage />)

    await screen.findByText('Your keys are in the lockbox by the door.')

    expect(screen.queryByRole('button', { name: 'Send' })).not.toBeInTheDocument()
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
  })

  it('says on the control itself what an internal note means', async () => {
    renderInbox([message()])
    renderWithProviders(<InboxPage />)

    expect(
      await screen.findByText('Internal note — the guest never sees this'),
    ).toBeInTheDocument()
  })

  it('sends a note to the notes endpoint, not to the guest', async () => {
    const server = renderInbox([message()])
    server.on('POST conversations/con_1/notes', { body: { data: message() } })

    renderWithProviders(<InboxPage />)

    await screen.findByRole('button', { name: 'Send' })

    await userEvent.click(screen.getByLabelText(/Internal note/))
    await userEvent.type(screen.getByRole('textbox'), 'Called the cleaner.')
    await userEvent.click(screen.getByRole('button', { name: 'Add note' }))

    // A note posted to the messages endpoint is a private remark delivered to
    // a guest, which is the one mistake this screen must not be able to make.
    expect(server.callsTo('POST', 'conversations/con_1/notes')).toHaveLength(1)
    expect(server.callsTo('POST', 'conversations/con_1/messages')).toHaveLength(0)
  })
})

/**
 * The copy-paste loop.
 *
 * Most operators cannot reach Airbnb programmatically, so the guest conversation
 * happens in somebody else's inbox and the work is done by hand: paste in what
 * the guest wrote, let the property's agent draft, copy the reply back. What
 * must hold is that the record never claims this platform delivered something a
 * person carried — a thread is evidence, and "sent" means sent.
 */
describe('logging messages that travelled elsewhere', () => {
  it('logs what a guest wrote somewhere else, with where it came from', async () => {
    const server = renderInbox([])
    server.on('POST conversations/con_1/received', { status: 201, body: { data: message() } })
    renderWithProviders(<InboxPage />)

    await userEvent.click(await screen.findByRole('button', { name: /Log a message the guest sent/ }))
    await userEvent.type(await screen.findByLabelText('Paste what the guest wrote'), 'Is there parking?')
    await userEvent.click(screen.getByRole('button', { name: 'Log it' }))

    const [logged] = server.callsTo('POST', 'conversations/con_1/received')

    expect(logged?.body).toMatchObject({ body: 'Is there parking?', transport: 'airbnb' })
  })

  it('records a reply the operator will send themselves, instead of sending it', async () => {
    const server = renderInbox([])
    server.on('POST conversations/con_1/delivered', { status: 201, body: { data: message() } })
    renderWithProviders(<InboxPage />)

    await userEvent.click(await screen.findByLabelText(/I will send this myself/))
    await userEvent.type(screen.getByPlaceholderText('Reply to the guest…'), 'Metered parking on Rua da Prata.')
    await userEvent.click(screen.getByRole('button', { name: 'Record as sent' }))

    expect(server.callsTo('POST', 'conversations/con_1/delivered')).toHaveLength(1)
    // The distinction the whole workflow rests on.
    expect(server.callsTo('POST', 'conversations/con_1/messages')).toHaveLength(0)
  })

  it('asks the property agent for a draft and shows why it was held', async () => {
    const server = renderInbox([])
    server.on('POST conversations/con_1/agent-draft', {
      body: {
        data: {
          answer: {
            reply: 'There is metered parking two minutes away.',
            intent: 'directions',
            confidence: 0.88,
            would_auto_send: false,
            held_because: 'This property escalates anything mentioning "neighbour".',
            withheld: [],
            used_facts: ['name', 'city'],
            is_simulated: false,
            simulation_reason: null,
            provider: 'claude',
            model: 'claude-haiku-4-5',
            tokens: 512,
          },
        },
      },
    })
    renderWithProviders(<InboxPage />)

    await userEvent.click(await screen.findByRole('button', { name: 'Ask the agent' }))

    const composer = await screen.findByPlaceholderText('Reply to the guest…')

    expect(composer).toHaveValue('There is metered parking two minutes away.')
    expect(screen.getByText(/88% sure/)).toBeInTheDocument()
    expect(screen.getByText(/escalates anything mentioning/)).toBeInTheDocument()
  })

  it('flags a draft as model-written when it is recorded as sent by hand', async () => {
    const server = renderInbox([])
    server.on('POST conversations/con_1/agent-draft', {
      body: {
        data: {
          answer: {
            reply: 'Check-in is from 3pm.',
            intent: 'amenity',
            confidence: 0.95,
            would_auto_send: true,
            held_because: null,
            withheld: [],
            used_facts: [],
            is_simulated: false,
            simulation_reason: null,
            provider: 'claude',
            model: 'claude-haiku-4-5',
            tokens: 400,
          },
        },
      },
    })
    server.on('POST conversations/con_1/delivered', { status: 201, body: { data: message() } })
    renderWithProviders(<InboxPage />)

    await userEvent.click(await screen.findByRole('button', { name: 'Ask the agent' }))
    await userEvent.click(screen.getByLabelText(/I will send this myself/))
    await userEvent.click(screen.getByRole('button', { name: 'Record as sent' }))

    const [recorded] = server.callsTo('POST', 'conversations/con_1/delivered')

    // Provenance survives the trip out of here and back by hand.
    expect(recorded?.body).toMatchObject({ is_ai_generated: true })
  })

  it('stops calling it the agent\'s words once a person edits them', async () => {
    const server = renderInbox([])
    server.on('POST conversations/con_1/agent-draft', {
      body: {
        data: {
          answer: {
            reply: 'Check-in is from 3pm.',
            intent: 'amenity',
            confidence: 0.95,
            would_auto_send: true,
            held_because: null,
            withheld: [],
            used_facts: [],
            is_simulated: false,
            simulation_reason: null,
            provider: 'claude',
            model: 'claude-haiku-4-5',
            tokens: 400,
          },
        },
      },
    })
    server.on('POST conversations/con_1/delivered', { status: 201, body: { data: message() } })
    renderWithProviders(<InboxPage />)

    await userEvent.click(await screen.findByRole('button', { name: 'Ask the agent' }))
    await userEvent.type(screen.getByPlaceholderText('Reply to the guest…'), ' See you then!')
    await userEvent.click(screen.getByLabelText(/I will send this myself/))
    await userEvent.click(screen.getByRole('button', { name: 'Record as sent' }))

    const [recorded] = server.callsTo('POST', 'conversations/con_1/delivered')

    expect(recorded?.body).toMatchObject({ is_ai_generated: false })
  })

  it('explains itself when there is nothing for the agent to answer', async () => {
    const server = renderInbox([])
    server.on('POST conversations/con_1/agent-draft', {
      body: { data: null, reason: 'There is no guest message on this conversation to answer yet.' },
    })
    renderWithProviders(<InboxPage />)

    await userEvent.click(await screen.findByRole('button', { name: 'Ask the agent' }))

    expect(await screen.findByText(/no guest message on this conversation/)).toBeInTheDocument()
  })

  it('lets somebody who may only read the inbox log what a guest said', async () => {
    // Data entry about something that already happened is not an act of
    // reaching a guest, so it does not need permission to send.
    renderInbox([], ['messages.view'])
    renderWithProviders(<InboxPage />)

    expect(
      await screen.findByRole('button', { name: /Log a message the guest sent/ }),
    ).toBeInTheDocument()
    expect(screen.queryByPlaceholderText('Reply to the guest…')).not.toBeInTheDocument()
  })
})
