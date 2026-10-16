import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { NotificationBell } from '@/components/NotificationBell'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { page, stubApi } from '@/test/server'

/**
 * Somewhere for an agent to tell you what it did.
 *
 * Notifications were written to a table and served by an API for as long as
 * this platform existed, and nothing displayed them — so an agent could answer
 * a guest, decline to, or fail outright, and the only trace was a row somebody
 * would have to go looking for. An inbox that had been handled and one that had
 * been ignored looked the same.
 */
function notification(overrides: Record<string, unknown> = {}) {
  return {
    id: 'ntf_1',
    type: 'agent.held',
    title: 'Alex was not sure how to answer Haaris',
    body: 'What time does the pool open?\n\nThe agent could not share some details.',
    url: '/inbox?conversation=cnv_1',
    icon: 'help-circle',
    priority: 'normal',
    subject_type: null,
    subject_id: null,
    data: { question: 'What time does the pool open?' },
    read_at: null,
    is_read: false,
    created_at: '2026-10-16T13:00:00+00:00',
    ...overrides,
  }
}

function renderBell({ unread = 2, items = [notification()] } = {}) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions: ['*'] }) },
    'GET notifications/unread-count': { body: { data: { unread } } },
    'GET notifications': { body: page(items) },
    'POST notifications/ntf_1/read': { body: { data: notification({ is_read: true }) } },
    'POST notifications/read-all': { body: { data: { marked: unread } } },
  })

  renderWithProviders(<NotificationBell />)

  return server
}

describe('the notification bell', () => {
  it('counts what is unread, and says so to a screen reader', async () => {
    renderBell({ unread: 3 })

    expect(await screen.findByRole('button', { name: /3 unread/ })).toBeInTheDocument()
    expect(screen.getByText('3')).toBeInTheDocument()
  })

  it('wears no badge when there is nothing to report', async () => {
    const server = renderBell({ unread: 0 })

    await screen.findByRole('button', { name: 'Notifications' })

    // A control that is always decorated stops being read.
    expect(server.callsTo('GET', 'notifications/unread-count').length).toBeGreaterThan(0)
    expect(screen.queryByText('0')).toBeNull()
  })

  it('fetches nothing until it is opened', async () => {
    const user = userEvent.setup()
    const server = renderBell()

    await screen.findByRole('button', { name: /unread/ })
    expect(server.callsTo('GET', 'notifications')).toHaveLength(0)

    await user.click(screen.getByRole('button', { name: /unread/ }))

    expect(await screen.findByText(/was not sure how to answer/)).toBeInTheDocument()
  })

  it('marks one read when it is opened', async () => {
    const user = userEvent.setup()
    const server = renderBell()

    await user.click(await screen.findByRole('button', { name: /unread/ }))
    await user.click(await screen.findByText(/was not sure how to answer/))

    /*
     * And it carries you to the thread. A notification you cannot act on is
     * half a notification, and the ones that matter here — the agent was not
     * sure what to say — are useless without the conversation they came from.
     */
    expect(server.callsTo('POST', 'notifications/ntf_1/read')).toHaveLength(1)
  })

  it('explains itself when there is nothing yet', async () => {
    const user = userEvent.setup()
    renderBell({ unread: 0, items: [] })

    await user.click(await screen.findByRole('button', { name: 'Notifications' }))

    // An empty panel that says only "nothing" teaches nobody what would ever
    // appear in it.
    expect(await screen.findByText(/answer a guest on their own/)).toBeInTheDocument()
  })
})
