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
