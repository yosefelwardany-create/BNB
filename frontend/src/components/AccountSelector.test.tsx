import { describe, expect, it } from 'vitest'
import { useQuery } from '@tanstack/react-query'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes, useLocation } from 'react-router-dom'
import { api, currentAuth } from '@/api/client'
import { AccountSelector } from '@/components/AccountSelector'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi, type StubbedCall } from '@/test/server'

/**
 * Switching accounts.
 *
 * The platform owner moves between client accounts without signing out, and
 * everything on screen is about the selected one. Three things have to hold:
 * the next request names the new account, nothing fetched for the old account
 * is still shown, and the URL does not carry ids from the old account.
 */

const ACCOUNTS = [
  { id: 'org_1', name: 'Demo Hospitality Group', slug: 'demo', status: 'active', base_currency: 'EUR', timezone: 'Europe/Lisbon' },
  { id: 'org_2', name: 'Seaside Lets', slug: 'seaside', status: 'active', base_currency: 'GBP', timezone: 'Europe/London' },
]

function ownerSession(call: StubbedCall) {
  const selected = ACCOUNTS.find((account) => account.id === call.headers['X-Organization']) ?? null
  const base = session()

  return session({
    is_platform_admin: true,
    permissions: [],
    organizations: ACCOUNTS,
    organization: selected === null ? null : { ...base.organization!, ...selected },
  })
}

/** Shows what the API said, under the account it was asked for. */
function Probe() {
  const location = useLocation()
  const probe = useQuery({
    queryKey: ['probe'],
    queryFn: () => api.get<{ data: { answered_for: string } }>('probe'),
  })

  return (
    <div>
      <AccountSelector />
      <p>path: {location.pathname}</p>
      <p>answered for: {probe.data?.data.answered_for ?? 'nothing yet'}</p>
    </div>
  )
}

function renderSelector(route = '/properties/prp_from_org_1') {
  const server = stubApi({
    'GET auth/me': (call) => ({ body: ownerSession(call) }),
    'GET probe': (call) => ({ body: { data: { answered_for: call.headers['X-Organization'] } } }),
  })

  renderWithProviders(
    <Routes>
      <Route path="*" element={<Probe />} />
    </Routes>,
    { route },
  )

  return server
}

describe('AccountSelector', () => {
  it('names every client account and marks the selected one', async () => {
    renderSelector()

    const select = await screen.findByLabelText('Switch account')

    expect(select).toHaveValue('org_1')
    expect(screen.getByRole('option', { name: 'Seaside Lets' })).toBeInTheDocument()
  })

  it('scopes the next request to the new account and drops the old payload', async () => {
    const server = renderSelector()

    await screen.findByText('answered for: org_1')

    await userEvent.selectOptions(screen.getByLabelText('Switch account'), 'org_2')

    await screen.findByText('answered for: org_2')

    // The stored selection drives every later request.
    expect(currentAuth()?.organizationId).toBe('org_2')

    const probes = server.callsTo('GET', 'probe')
    expect(probes[probes.length - 1]?.headers['X-Organization']).toBe('org_2')

    // The old account's answer is gone, not merely superseded: the cache it
    // lived in was thrown away with the switch.
    expect(screen.queryByText('answered for: org_1')).not.toBeInTheDocument()
  })

  it('returns to the dashboard so no URL carries the previous account’s ids', async () => {
    renderSelector('/properties/prp_from_org_1')

    await screen.findByText('path: /properties/prp_from_org_1')

    await userEvent.selectOptions(screen.getByLabelText('Switch account'), 'org_2')

    await waitFor(() => expect(screen.getByText('path: /')).toBeInTheDocument())
  })

  it('shows nothing to somebody who is not the platform owner', async () => {
    stubApi({ 'GET auth/me': { body: session({ permissions: ['*'], is_platform_admin: false }) } })

    renderWithProviders(<Probe />)

    await screen.findByText(/answered for:/)

    expect(screen.queryByLabelText('Switch account')).not.toBeInTheDocument()
  })
})
