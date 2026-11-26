import { describe, expect, it } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AccountsPage } from '@/pages/AccountsPage'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { page, stubApi } from '@/test/server'

/**
 * Account administration.
 *
 * Creating a client is the one action that produces a sign-in for somebody
 * else, so the test pins down what travels: the client's details, and nothing
 * that could grant a role or a flag. The onboarding panel is what tells the
 * owner whether provisioning finished, so its warnings are checked too.
 */
const TENANT = {
  id: 'org_2',
  name: 'Seaside Lets',
  legal_name: 'Seaside Lets Ltd',
  slug: 'seaside-lets',
  status: 'active',
  status_label: 'Active',
  is_operational: true,
  base_currency: 'GBP',
  timezone: 'Europe/London',
  country_code: 'GB',
  contact_email: 'owner@seaside.test',
  contact_phone: null,
  suspended_at: null,
  suspension_reason: null,
  platform_notes: null,
  users_count: 1,
  created_at: '2026-09-01T09:00:00+00:00',
}

const META = {
  counts: { properties: 3, guests: 0, owners: 1, reservations: 12, open_tasks: 0, channel_accounts: 1, api_keys: 0, webhook_endpoints: 0 },
  last_activity_at: null,
  last_reservation_at: '2026-10-01T09:00:00+00:00',
  client: {
    account_holder: { id: 'own_2', display_name: 'Seaside Lets', email: 'owner@seaside.test', has_login: true },
    agreement: { id: 'agr_2', commission_model: 'percent_of_revenue', commission_rate: 10, starts_on: '2026-09-01', deduct_channel_commission_first: false },
    properties_without_ownership: 1,
    properties_with_other_owners: 0,
    client_logins: 1,
    staff_logins: [],
    organization_name: 'Seaside Lets',
  },
}

interface Login {
  membership_id: string
  user_id: string
  name: string
  email: string
  status: string
  job_title: string | null
  roles: string[]
  is_client: boolean
  is_platform_admin: boolean
  last_login_at: string | null
}

const HOLDER: Login = {
  membership_id: 'mem_2',
  user_id: 'usr_2',
  name: 'Sam Shore',
  email: 'owner@seaside.test',
  status: 'active',
  job_title: 'Client',
  roles: ['Client'],
  is_client: true,
  is_platform_admin: false,
  last_login_at: null,
}

/** The same logins as the client's team screen lists them. */
function team(logins: Login[]) {
  return {
    body: {
      data: {
        members: logins.map((login) => ({
          id: login.membership_id,
          user: { id: login.user_id, name: login.name, email: login.email, last_login_at: login.last_login_at },
          status: login.status,
          job_title: login.job_title,
          joined_at: null,
          roles: login.roles.map((name) => ({ id: `role_${name}`, slug: name.toLowerCase(), name })),
          allow: [],
          deny: [],
          permissions: [],
          restricted_to_properties: false,
          property_ids: [],
          is_you: false,
        })),
        invitations: [],
      },
    },
  }
}

const OPTIONS = { body: { data: { areas: [], roles: [], properties: [] } } }

function renderAccounts(extra: Record<string, unknown> = {}, logins: Login[] = [HOLDER]) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions: [], is_platform_admin: true }) },
    'GET platform/organizations': { body: page([TENANT]) },
    'GET platform/organizations/org_2': { body: { data: TENANT, meta: META } },
    'GET platform/organizations/org_2/users': { body: { data: logins } },
    'GET platform/organizations/org_2/team': team(logins),
    'GET platform/organizations/org_2/team/options': OPTIONS,
    ...(extra as Record<string, { body: unknown }>),
  })

  renderWithProviders(<AccountsPage />)

  return server
}

describe('AccountsPage', () => {
  it('lists the client accounts and shows where one stands', async () => {
    renderAccounts()

    await userEvent.click(await screen.findByText('Seaside Lets', { selector: '.strong' }))

    expect(await screen.findByText('10% of revenue from 1 Sept 2026')).toBeInTheDocument()
    expect(screen.getByText('1 not attributed')).toBeInTheDocument()
    expect(screen.getByText('Sam Shore')).toBeInTheDocument()
  })

  it('creates a client with the details typed and nothing else', async () => {
    const server = renderAccounts({
      'POST platform/organizations': {
        status: 201,
        body: {
          message: 'The client account was created and an invitation sent.',
          data: { ...TENANT, id: 'org_3', name: 'Harbour Homes' },
        },
      },
      'GET platform/organizations/org_3': { body: { data: { ...TENANT, id: 'org_3', name: 'Harbour Homes' }, meta: META } },
      'GET platform/organizations/org_3/users': { body: { data: [] } },
      'GET platform/organizations/org_3/team': team([]),
      'GET platform/organizations/org_3/team/options': OPTIONS,
    })

    await userEvent.click(await screen.findByRole('button', { name: /New client/ }))

    const dialog = screen.getByRole('dialog')

    await userEvent.type(within(dialog).getByLabelText(/Client name/), 'Harbour Homes')
    await userEvent.selectOptions(within(dialog).getByLabelText(/Base currency/), 'GBP')
    await userEvent.clear(within(dialog).getByLabelText(/Timezone/))
    await userEvent.type(within(dialog).getByLabelText(/Timezone/), 'Europe/London')
    await userEvent.type(within(dialog).getByLabelText(/Contact first name/), 'Hana')
    await userEvent.type(within(dialog).getByLabelText(/Contact email/), 'hana@harbour.test')

    await userEvent.click(within(dialog).getByRole('button', { name: 'Create client' }))

    await waitFor(() => expect(server.callsTo('POST', 'platform/organizations')).toHaveLength(1))

    const body = server.callsTo('POST', 'platform/organizations')[0]?.body as Record<string, unknown>

    expect(body).toMatchObject({
      organization_name: 'Harbour Homes',
      base_currency: 'GBP',
      timezone: 'Europe/London',
      first_name: 'Hana',
      email: 'hana@harbour.test',
      send_invitation: true,
    })
    // Nothing a form could use to escalate.
    expect(body).not.toHaveProperty('is_platform_admin')
    expect(body).not.toHaveProperty('roles')

    // The new account is selected so the owner sees its onboarding state.
    expect(await screen.findByRole('heading', { name: 'Harbour Homes' })).toBeInTheDocument()
  })

  it('lists the client\u2019s logins with what each can do, and offers to add one', async () => {
    renderAccounts()

    await userEvent.click(await screen.findByText('Seaside Lets', { selector: '.strong' }))

    const logins = (await screen.findByRole('heading', { name: 'Logins' })).closest('section') as HTMLElement
    expect(await within(logins).findByText('Sam Shore')).toBeInTheDocument()
    expect(within(logins).getByText('Client')).toBeInTheDocument()
    expect(within(logins).getByRole('button', { name: 'Add login' })).toBeInTheDocument()
    // Several logins with their own access are what an account is for now,
    // not a state to be warned about.
    expect(screen.queryByText(/still holds? staff roles/)).not.toBeInTheDocument()
  })

  it('requires a reason to suspend', async () => {
    const server = renderAccounts({
      'POST platform/organizations/org_2/suspend': {
        body: { message: 'The organization has been suspended.', data: { ...TENANT, status: 'suspended' } },
      },
    })

    await userEvent.click(await screen.findByText('Seaside Lets', { selector: '.strong' }))
    await userEvent.click(await screen.findByRole('button', { name: 'Suspend' }))

    const dialog = screen.getByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText(/Reason/), 'Unpaid invoices')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Suspend' }))

    await waitFor(() =>
      expect(server.callsTo('POST', 'platform/organizations/org_2/suspend')[0]?.body).toEqual({
        reason: 'Unpaid invoices',
      }),
    )
  })

  it('makes a staff login the account\u2019s only, read-only login, with a reason', async () => {
    const staff = {
      membership_id: 'mem_9',
      user_id: 'usr_9',
      name: 'Ana Ribeiro',
      email: 'admin@seaside.test',
      status: 'active',
      job_title: 'Managing Director',
      roles: ['Organization administrator'],
      is_client: false,
      is_platform_admin: false,
      last_login_at: null,
    }

    const server = renderAccounts(
      {
        'POST platform/organizations/org_2/logins/mem_9/sole-client': {
          body: { message: 'admin@seaside.test is now the only login for this account, and it can only read.' },
        },
      },
      [staff],
    )

    await userEvent.click(await screen.findByText('Seaside Lets', { selector: '.strong' }))

    // Its role is on its row, so the owner can see what it can do.
    expect(await screen.findByText('Organization administrator')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Make this the only login' }))

    const dialog = screen.getByRole('dialog')
    expect(within(dialog).getByText(/Every other login in this account is suspended/)).toBeInTheDocument()

    await userEvent.type(within(dialog).getByLabelText(/Reason/), 'One login per client')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Make it the only login' }))

    await waitFor(() =>
      expect(server.callsTo('POST', 'platform/organizations/org_2/logins/mem_9/sole-client')[0]?.body).toEqual({
        reason: 'One login per client',
      }),
    )
  })

  it('offers nothing to change when the client login is already the only one', async () => {
    renderAccounts()

    await userEvent.click(await screen.findByText('Seaside Lets', { selector: '.strong' }))

    expect(await screen.findByText('Sam Shore')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Make this the only login' })).not.toBeInTheDocument()
  })

  it('sets a new password for a login and shows it once', async () => {
    const server = renderAccounts({
      'POST platform/organizations/org_2/logins/mem_2/password': {
        body: {
          message: 'A new password was set for owner@seaside.test and every signed-in session ended. It is shown once.',
          meta: { email: 'owner@seaside.test', password: 'Kq7mPz2xRt9vLw4n' },
        },
      },
    })

    await userEvent.click(await screen.findByText('Seaside Lets', { selector: '.strong' }))
    await userEvent.click(await screen.findByRole('button', { name: /Reset password/ }))

    const dialog = screen.getByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText(/Reason/), 'Client locked out')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Reset password' }))

    await waitFor(() =>
      expect(server.callsTo('POST', 'platform/organizations/org_2/logins/mem_2/password')[0]?.body).toEqual({
        method: 'generate',
        reason: 'Client locked out',
      }),
    )

    expect(await screen.findByText('Kq7mPz2xRt9vLw4n')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Done' }))
    expect(screen.queryByText('Kq7mPz2xRt9vLw4n')).not.toBeInTheDocument()
  })

  it('offers deletion only for a suspended account, with the name typed back', async () => {
    renderAccounts()

    await userEvent.click(await screen.findByText('Seaside Lets', { selector: '.strong' }))
    await screen.findByRole('button', { name: 'Suspend' })
    expect(screen.queryByRole('button', { name: /Delete account/ })).not.toBeInTheDocument()
  })

  it('deletes a suspended account', async () => {
    const suspended = { ...TENANT, status: 'suspended', status_label: 'Suspended', suspended_at: '2026-10-07T09:00:00+00:00', suspension_reason: 'Leaving' }

    const server = renderAccounts({
      'GET platform/organizations': { body: page([suspended]) },
      'GET platform/organizations/org_2': { body: { data: suspended, meta: META } },
      'DELETE platform/organizations/org_2': { body: { message: 'Seaside Lets was deleted with everything it held.' } },
    })

    await userEvent.click(await screen.findByText('Seaside Lets', { selector: '.strong' }))
    await userEvent.click(await screen.findByRole('button', { name: /Delete account/ }))

    const dialog = screen.getByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText(/Account name/), 'Seaside Lets')
    await userEvent.type(within(dialog).getByLabelText(/Reason/), 'Client left')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Delete for good' }))

    await waitFor(() =>
      expect(server.callsTo('DELETE', 'platform/organizations/org_2')[0]?.body).toEqual({
        confirm_name: 'Seaside Lets',
        reason: 'Client left',
      }),
    )
  })
})
