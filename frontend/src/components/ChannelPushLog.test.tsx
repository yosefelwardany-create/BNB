import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { ChannelPushLog } from '@/components/ChannelPushLog'
import { formatDate, formatDateRange } from '@/lib/format'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { page, stubApi } from '@/test/server'

/**
 * The calls Habitat made to a channel, as staff show them to Channex.
 *
 * A call names the nights it carried. A booking moved a week on sends its old
 * night and its new one and nothing between; shown as "9 – 16 Mar", the row
 * said the opposite of what was sent.
 */
function job(overrides: Record<string, unknown> = {}) {
  return {
    id: 'job_1',
    kind: 'availability',
    direction: 'push',
    status: 'succeeded',
    channel_account_id: 'acc_1',
    channel_listing_id: 'map_1',
    listing_name: 'Twin Room',
    external_reference: 'task-1',
    range_start: '2027-03-09',
    range_end: '2027-03-17',
    error_message: null,
    is_simulated: false,
    started_at: '2026-11-12T13:58:56+00:00',
    created_at: '2026-11-12T13:58:56+00:00',
    ...overrides,
  }
}

function renderLog(jobs: Record<string, unknown>[]) {
  stubApi({
    'GET auth/me': { body: session() },
    'GET channel-sync/jobs': { body: page(jobs) },
  })

  renderWithProviders(<ChannelPushLog accounts={[]} />)
}

describe('the nights a call sent', () => {
  it('names each night sent, not the span between them', async () => {
    renderLog([job({ payload_summary: { days: 2, sent: [['2027-03-09', '2027-03-09'], ['2027-03-16', '2027-03-16']] } })])

    expect(await screen.findByText(`${formatDate('2027-03-09')}, ${formatDate('2027-03-16')}`)).toBeInTheDocument()
    expect(screen.queryByText(formatDateRange('2027-03-09', '2027-03-16'))).not.toBeInTheDocument()
  })

  it('joins the nights of the room types that went in one call', async () => {
    renderLog([
      job({ id: 'job_1', listing_name: 'Twin Room', range_start: '2027-11-21', range_end: '2027-11-22', payload_summary: { sent: [['2027-11-21', '2027-11-21']] } }),
      job({ id: 'job_2', listing_name: 'Double Room', range_start: '2027-11-25', range_end: '2027-11-26', payload_summary: { sent: [['2027-11-25', '2027-11-25']] } }),
    ])

    expect(await screen.findByText(`${formatDate('2027-11-21')}, ${formatDate('2027-11-25')}`)).toBeInTheDocument()
  })

  it('shows a run of nights as one range', async () => {
    renderLog([job({ range_start: '2027-11-01', range_end: '2027-11-11', payload_summary: { sent: [['2027-11-01', '2027-11-10']] } })])

    expect(await screen.findByText(formatDateRange('2027-11-01', '2027-11-10'))).toBeInTheDocument()
  })

  it('falls back to the first and last night for a call that did not say', async () => {
    renderLog([job({ payload_summary: { days: 8 } })])

    expect(await screen.findByText(formatDateRange('2027-03-09', '2027-03-16'))).toBeInTheDocument()
  })
})
