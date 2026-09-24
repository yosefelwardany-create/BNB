import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Home } from 'lucide-react'
import { AppLayout } from '@/components/AppLayout'
import { CommandPalette, type Command } from '@/components/CommandPalette'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * The command palette.
 *
 * It is a second way into every screen, so it has to keep the navigation's one
 * promise: a page the user's role would hide is not offered here either. The
 * rest is the keyboard contract — type to narrow, arrows to move, Enter to go,
 * Escape to leave.
 */

function command(overrides: Partial<Command> = {}): Command {
  return { id: 'x', label: 'Reservations', group: 'Go to', icon: Home, run: vi.fn(), ...overrides }
}

describe('CommandPalette', () => {
  it('narrows the list as you type', async () => {
    render(
      <CommandPalette
        open
        onClose={vi.fn()}
        commands={[
          command({ id: 'a', label: 'Reservations' }),
          command({ id: 'b', label: 'Revenue' }),
        ]}
      />,
    )

    await userEvent.type(screen.getByRole('combobox'), 'rese')

    expect(screen.getByRole('option', { name: 'Reservations' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Revenue' })).not.toBeInTheDocument()
  })

  it('matches on keywords as well as the label', async () => {
    render(
      <CommandPalette
        open
        onClose={vi.fn()}
        commands={[command({ label: 'Operations', keywords: 'cleaning housekeeping' })]}
      />,
    )

    await userEvent.type(screen.getByRole('combobox'), 'cleaning')

    expect(screen.getByRole('option', { name: 'Operations' })).toBeInTheDocument()
  })

  it('runs the highlighted command on Enter, and closes', async () => {
    const first = vi.fn()
    const second = vi.fn()
    const onClose = vi.fn()

    render(
      <CommandPalette
        open
        onClose={onClose}
        commands={[
          command({ id: 'a', label: 'Calendar', run: first }),
          command({ id: 'b', label: 'Inbox', run: second }),
        ]}
      />,
    )

    await userEvent.keyboard('{ArrowDown}{Enter}')

    expect(second).toHaveBeenCalledOnce()
    expect(first).not.toHaveBeenCalled()
    expect(onClose).toHaveBeenCalled()
  })

  it('closes on Escape without running anything', async () => {
    const run = vi.fn()
    const onClose = vi.fn()

    render(<CommandPalette open onClose={onClose} commands={[command({ run })]} />)

    await userEvent.keyboard('{Escape}')

    expect(onClose).toHaveBeenCalled()
    expect(run).not.toHaveBeenCalled()
  })

  it('says so when nothing matches', async () => {
    render(<CommandPalette open onClose={vi.fn()} commands={[command()]} />)

    await userEvent.type(screen.getByRole('combobox'), 'zzz')

    expect(screen.getByText(/Nothing matches/)).toBeInTheDocument()
  })
})

describe('the palette in the tenant shell', () => {
  async function openPalette(overrides: Parameters<typeof session>[0] = {}) {
    stubApi({
      'GET auth/me': { body: session(overrides) },
      'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    })

    renderWithProviders(
      <AppLayout>
        <p>content</p>
      </AppLayout>,
    )

    await screen.findByText('Demo Hospitality Group', { selector: 'strong' })
    await userEvent.keyboard('{Control>}k{/Control}')

    return screen.findByRole('dialog', { name: 'Command palette' })
  }

  it('opens with Ctrl+K', async () => {
    expect(await openPalette({ permissions: ['reservations.view'] })).toBeInTheDocument()
  })

  it('offers only the pages the navigation shows', async () => {
    await openPalette({ permissions: ['reservations.view'] })

    expect(screen.getByRole('option', { name: /Reservations/ })).toBeInTheDocument()
    // Revenue needs a permission this user does not hold, so it is hidden in
    // the sidebar — and must not be one keystroke away here instead.
    expect(screen.queryByRole('option', { name: /Revenue/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('option', { name: /Owners/ })).not.toBeInTheDocument()
  })

  it('does not offer the platform console to an ordinary administrator', async () => {
    await openPalette({ permissions: ['*'], is_platform_admin: false })

    expect(screen.queryByRole('option', { name: /Platform console/ })).not.toBeInTheDocument()
  })

  it('offers it to a platform administrator', async () => {
    await openPalette({ permissions: [], is_platform_admin: true })

    expect(screen.getByRole('option', { name: /Platform console/ })).toBeInTheDocument()
  })
})
