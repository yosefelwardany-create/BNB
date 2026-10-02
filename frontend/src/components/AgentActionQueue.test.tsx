import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AgentActionQueue } from '@/components/AgentActionQueue'
import type { AgentAction } from '@/api/types'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * The screen between the agent and the calendar.
 *
 * Two things it must not do, and both are about `is_open` rather than `status`.
 * A proposal past its window is still `proposed` until the sweeper runs, so a
 * screen keyed on the status would offer Approve on a situation that has moved
 * on. And an action that already happened must not offer the button again — a
 * second approval on a cancellation is a second cancellation.
 *
 * One thing it must do: say what the thing costs before somebody agrees to it.
 */
function action(overrides: Partial<AgentAction> = {}): AgentAction {
  return {
    id: 'act_1',
    property_id: 'prp_1',
    reservation_id: null,
    conversation_id: null,
    capability: 'block_dates',
    capability_label: 'Block nights',
    consequence:
      'Nights stop being bookable. An empty calendar is not noticed the way a double booking is.',
    summary: 'Close the first weekend of March for painting.',
    arguments: { from: '2026-03-06', to: '2026-03-08' },
    status: 'proposed',
    is_open: true,
    was_autonomous: false,
    requested_by: 'Ana',
    approved_by: null,
    outcome: null,
    external_reference: null,
    expires_at: '2026-03-04T10:00:00+00:00',
    decided_at: null,
    executed_at: null,
    created_at: '2026-03-02T10:00:00+00:00',
    ...overrides,
  }
}

function renderQueue(rows: AgentAction[]) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions: ['*'] }) },
    'GET properties/prp_1/agent/actions': {
      body: { data: rows, meta: { waiting: rows.filter((row) => row.is_open).length } },
    },
    'POST properties/prp_1/agent/actions/act_1/approve': {
      body: { data: action({ status: 'executed', is_open: false }) },
    },
    'POST properties/prp_1/agent/actions/act_1/reject': {
      body: { data: action({ status: 'rejected', is_open: false }) },
    },
  })

  renderWithProviders(<AgentActionQueue propertyId="prp_1" />)

  return server
}

describe('what a proposal shows', () => {
  it('states what the action costs, not only what it is', async () => {
    renderQueue([action()])

    expect(await screen.findByText(/Close the first weekend of March/)).toBeInTheDocument()
    // The sentence that makes this screen worth reading rather than clicking
    // through.
    expect(screen.getByText(/An empty calendar is not noticed/)).toBeInTheDocument()
    // And the detail, so Approve is not a leap of faith.
    expect(screen.getByText('2026-03-06')).toBeInTheDocument()
  })

  it('says when nobody asked, because that is what autonomous means', async () => {
    renderQueue([action({ requested_by: null, was_autonomous: true, status: 'executed', is_open: false })])

    expect(await screen.findByText(/the agent raised this itself/)).toBeInTheDocument()
    expect(screen.getByText(/ran without being read/)).toBeInTheDocument()
  })

  it('shows the channel’s own words when it refused', async () => {
    renderQueue([
      action({
        capability: 'send_message',
        capability_label: 'Reply to a guest',
        status: 'failed',
        is_open: false,
        outcome: 'This conversation is closed.',
      }),
    ])

    expect(await screen.findByText('This conversation is closed.')).toBeInTheDocument()
    expect(screen.getByText('The channel refused it')).toBeInTheDocument()
  })
})

describe('what can be decided', () => {
  it('approves a proposal that is still open', async () => {
    const server = renderQueue([action()])

    await userEvent.click(await screen.findByRole('button', { name: /Approve/ }))

    expect(server.callsTo('POST', 'properties/prp_1/agent/actions/act_1/approve')).toHaveLength(1)
  })

  it('takes a reason before turning one down, and sends it', async () => {
    const server = renderQueue([action()])

    // Two presses on purpose: the first opens the box, the second commits. A
    // single-press refusal with no reason is the version nobody can read back.
    await userEvent.click(await screen.findByRole('button', { name: /Turn it down/ }))
    await userEvent.type(
      screen.getByPlaceholderText(/already sold/),
      'Those nights are already sold.',
    )
    await userEvent.click(screen.getByRole('button', { name: /Confirm turning it down/ }))

    const calls = server.callsTo('POST', 'properties/prp_1/agent/actions/act_1/reject')

    expect(calls).toHaveLength(1)
    expect(calls[0]?.body).toEqual({ because: 'Those nights are already sold.' })
  })

  it('offers nothing on a proposal whose window has passed', async () => {
    /*
     * Still `proposed`, and `is_open` false: the sweeper has not run yet.
     * Approving here would approve a weekend that has since been sold.
     */
    renderQueue([action({ is_open: false, expires_at: '2026-03-01T10:00:00+00:00' })])

    expect(await screen.findByText(/Close the first weekend of March/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Approve/ })).not.toBeInTheDocument()
  })

  it('offers nothing on an action that already ran', async () => {
    renderQueue([action({ status: 'executed', is_open: false })])

    expect(await screen.findByText('Done')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Approve/ })).not.toBeInTheDocument()
  })

  it('explains the empty queue rather than showing an empty box', async () => {
    renderQueue([])

    expect(await screen.findByText(/Nothing waiting/)).toBeInTheDocument()
  })
})
