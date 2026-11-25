import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { SettingsPage } from '@/pages/SettingsPage'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * The Team entry in the sidebar opens settings on the team.
 *
 * Under "Developer" the team was out of sight for anybody who manages people
 * without holding API keys, so it has its own entry that lands on it.
 */
describe('opening settings on the team', () => {
  it('lands on the team for somebody who may see it', async () => {
    stubApi({
      'GET auth/me': { body: session({ permissions: ['users.view'] }) },
      'GET team': { body: { data: { members: [], invitations: [] } } },
      'GET team/options': { body: { data: { areas: [], roles: [], properties: [] } } },
    })

    renderWithProviders(<SettingsPage initialTab="team" />)

    expect(await screen.findByRole('heading', { name: 'Your team' })).toBeInTheDocument()
  })

  it('falls back to your own account for somebody who may not', async () => {
    stubApi({
      'GET auth/me': { body: session({ permissions: [] }) },
      'GET auth/mfa': { body: { data: { enabled: false } } },
    })

    renderWithProviders(<SettingsPage initialTab="team" />)

    expect(await screen.findByRole('heading', { name: 'Settings' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Your team' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Team' })).not.toBeInTheDocument()
  })
})
