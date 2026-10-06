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

function renderAccounts(extra: Record<string, unknown> = {}) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions: [], is_platform_admin: true }) },
    'GET platform/organizations': { body: page([TENANT]) },
    'GET platform/organizations/org_2': { body: { data: TENANT, meta: META } },
    'GET platform/organizations/org_2/users': {
      body: {
        data: [
          {
            membership_id: 'mem_2',
            user_id: 'usr_2',
            name: 'Sam Shore',
            email: 'owner@seaside.test',
            status: 'active',
            job_title: 'Client',
            roles: ['Client'],
            is_platform_admin: false,
            last_login_at: null,
          },
        ],
      },
    },
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

  it('warns when logins still hold staff roles instead of converting them', async () => {
    renderAccounts({
      'GET platform/organizations/org_2': {
        body: {
          data: TENANT,
          meta: {
            ...META,
            client: {
              ...META.client,
              staff_logins: [{ membership_id: 'mem_9', email: 'staff@seaside.test', roles: ['organization-admin'] }],
            },
          },
        },
      },
    })

    await userEvent.click(await screen.findByText('Seaside Lets', { selector: '.strong' }))

    expect(await screen.findByText(/still holds staff roles/)).toBeInTheDocument()
    expect(screen.getByText(/clients:convert-login/)).toBeInTheDocument()
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
})
