import { describe, expect, it } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { TeamMember, TeamOptions } from '@/api/types'
import { TeamPanel } from '@/components/TeamPanel'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * Who on the team sees what.
 *
 * A person's access is a role, single permissions given or taken away on top
 * of it, and optionally a limit to some properties. What the screen has to get
 * right is that the role stays the starting point: unticking something the
 * role includes takes it away, ticking something it does not gives it, and
 * neither rewrites the role itself.
 */
const options: TeamOptions = {
  areas: [
    {
      name: 'Reservations',
      permissions: [
        { key: 'reservations.view', label: 'View reservations' },
        { key: 'reservations.cancel', label: 'Cancel reservations' },
      ],
    },
    {
      name: 'Financials',
      permissions: [
        { key: 'financials.view', label: 'View financial records' },
        { key: 'ledger.post', label: 'Post manual journal entries' },
      ],
    },
  ],
  roles: [
    {
      id: 'role_agent',
      slug: 'reservations-agent',
      name: 'Reservations Agent',
      description: 'Handles bookings.',
      permissions: ['reservations.view', 'reservations.cancel'],
    },
    {
      id: 'role_cleaner',
      slug: 'cleaner',
      name: 'Cleaner',
      description: 'Does the cleaning.',
      permissions: [],
    },
  ],
  properties: [
    { id: 'prop_north', name: 'North Room' },
    { id: 'prop_south', name: 'South Room' },
  ],
}

function member(overrides: Partial<TeamMember> = {}): TeamMember {
  return {
    id: 'mem_2',
    user: { id: 'usr_2', name: 'Rana Haddad', email: 'rana@example.test', last_login_at: null },
    status: 'active',
    job_title: 'Front desk',
    joined_at: null,
    roles: [{ id: 'role_agent', slug: 'reservations-agent', name: 'Reservations Agent' }],
    allow: [],
    deny: [],
    permissions: ['reservations.view', 'reservations.cancel'],
    restricted_to_properties: false,
    property_ids: [],
    is_you: false,
    ...overrides,
  }
}

const you = member({
  id: 'mem_1',
  user: { id: 'usr_1', name: 'Ana Ferreira', email: 'ana@habitat.test', last_login_at: null },
  roles: [{ id: 'role_admin', slug: 'organization-admin', name: 'Organization Admin' }],
  is_you: true,
})

function renderTeam(permissions: string[] = ['users.view', 'users.update', 'users.invite', 'users.deactivate', 'reservations.view', 'reservations.cancel', 'financials.view']) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions }) },
    'GET team': { body: { data: { members: [you, member({ restricted_to_properties: true, property_ids: ['prop_north'], deny: ['reservations.cancel'] })], invitations: [] } } },
    'GET team/options': { body: { data: options } },
    'PATCH team/mem_2': { body: { message: 'Access updated.', data: member() } },
    'POST team/invitations': { status: 201, body: { message: 'Invitation sent to maya@example.test.', data: {} } },
  })

  renderWithProviders(<TeamPanel />)

  return server
}

describe('the team', () => {
  it('shows each person with their role and the properties they see', async () => {
    renderTeam()

    const rana = (await screen.findByText('Rana Haddad')).closest('tr') as HTMLElement
    expect(within(rana).getByText('Reservations Agent')).toBeInTheDocument()
    expect(within(rana).getByText('1 removed')).toBeInTheDocument()
    expect(await within(rana).findByText('Only North Room')).toBeInTheDocument()

    // Your own access is somebody else's to change.
    const ana = screen.getByText('Ana Ferreira').closest('tr') as HTMLElement
    expect(within(ana).getByText('You')).toBeInTheDocument()
    expect(within(ana).queryByRole('button', { name: 'Edit access' })).not.toBeInTheDocument()
  })

  it('takes away and gives against the role, and limits the properties', async () => {
    const server = renderTeam()

    const rana = (await screen.findByText('Rana Haddad')).closest('tr') as HTMLElement
    await userEvent.click(await within(rana).findByRole('button', { name: 'Edit access' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit access' })

    // Given back what was taken away, and something the role does not have.
    await userEvent.click(within(dialog).getByText('Reservations'))
    await userEvent.click(within(dialog).getByLabelText(/Cancel reservations/))
    await userEvent.click(within(dialog).getByText('Financials'))
    await userEvent.click(within(dialog).getByLabelText(/View financial records/))

    // Nothing more than you hold yourself can be ticked.
    expect(within(dialog).getByLabelText(/Post manual journal entries/)).toBeDisabled()

    await userEvent.click(within(dialog).getByLabelText('South Room'))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save access' }))

    await waitFor(() => expect(server.callsTo('PATCH', 'team/mem_2')).toHaveLength(1))
    expect(server.callsTo('PATCH', 'team/mem_2')[0]?.body).toEqual({
      role_ids: ['role_agent'],
      allow: ['financials.view'],
      deny: [],
      restricted_to_properties: true,
      property_ids: ['prop_north', 'prop_south'],
      job_title: 'Front desk',
    })
  })

  it('invites someone with the access they will have', async () => {
    const server = renderTeam()

    await userEvent.click(await screen.findByRole('button', { name: 'Invite someone' }))
    const dialog = await screen.findByRole('dialog', { name: 'Invite someone' })

    await userEvent.type(within(dialog).getByLabelText('Email'), 'maya@example.test')
    await userEvent.type(within(dialog).getByLabelText('First name'), 'Maya')
    await userEvent.selectOptions(within(dialog).getByLabelText('Role'), 'role_cleaner')
    await userEvent.click(within(dialog).getByLabelText('Only these'))
    await userEvent.click(within(dialog).getByLabelText('North Room'))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Send invitation' }))

    await waitFor(() => expect(server.callsTo('POST', 'team/invitations')).toHaveLength(1))
    expect(server.callsTo('POST', 'team/invitations')[0]?.body).toMatchObject({
      email: 'maya@example.test',
      first_name: 'Maya',
      role_ids: ['role_cleaner'],
      allow: [],
      deny: [],
      restricted_to_properties: true,
      property_ids: ['prop_north'],
    })
  })

  it('lets someone who may only look, look', async () => {
    renderTeam(['users.view'])

    expect(await screen.findByText('Rana Haddad')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Edit access' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Switch off' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Invite someone' })).not.toBeInTheDocument()
  })
})
