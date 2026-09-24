import { describe, expect, it } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AnnouncementBanner } from '@/components/AnnouncementBanner'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * Notices the platform has published to a customer.
 *
 * Two rules with a reason behind each. A critical notice cannot be dismissed,
 * because the point of marking it critical is that everybody reads it. And a
 * failure shows nothing at all — a banner is not worth an error message on
 * every screen, and a platform whose announcement endpoint is down should not
 * make the product look broken.
 */
function announcement(overrides: Record<string, unknown> = {}) {
  return {
    id: 'ann_1',
    title: 'Scheduled maintenance on Sunday',
    body: 'Channel synchronisation will pause between 02:00 and 03:00 UTC.',
    level: 'info',
    is_dismissible: true,
    starts_at: null,
    ends_at: null,
    ...overrides,
  }
}

function renderBanner(body: unknown, status = 200) {
  stubApi({ 'GET organization/announcements': { status, body } })

  return renderWithProviders(<AnnouncementBanner />, { signedIn: false })
}

describe('AnnouncementBanner', () => {
  it('shows a published notice', async () => {
    renderBanner({ data: [announcement()], meta: { maintenance_notice: null } })

    expect(await screen.findByText('Scheduled maintenance on Sunday')).toBeInTheDocument()
  })

  it('shows the platform-wide maintenance notice', async () => {
    renderBanner({
      data: [],
      meta: { maintenance_notice: 'We are upgrading the database this evening.' },
    })

    expect(
      await screen.findByText('We are upgrading the database this evening.'),
    ).toBeInTheDocument()
  })

  it('will not let a critical notice be dismissed', async () => {
    renderBanner({
      data: [announcement({ level: 'critical', is_dismissible: false })],
      meta: { maintenance_notice: null },
    })

    expect(await screen.findByRole('alert')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Dismiss' })).not.toBeInTheDocument()
  })

  it('keeps a dismissal to this browser', async () => {
    renderBanner({ data: [announcement()], meta: { maintenance_notice: null } })

    await userEvent.click(await screen.findByRole('button', { name: 'Dismiss' }))

    expect(screen.queryByText('Scheduled maintenance on Sunday')).not.toBeInTheDocument()

    // Local, and deliberately: a notice one person dismissed for the whole
    // company is a notice nobody else ever saw.
    expect(JSON.parse(localStorage.getItem('habitat.dismissed') ?? '[]')).toContain('ann_1')
  })

  it('shows nothing rather than an error when the endpoint fails', async () => {
    const { container } = renderBanner({ message: 'Server error.' }, 500)

    await waitFor(() => expect(container).toBeEmptyDOMElement())
  })
})
