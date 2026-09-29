import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { RegisterPage } from '@/pages/RegisterPage'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * Creating a company.
 *
 * Until this screen existed the only ways into the platform were the demo
 * seeder and the platform console — which is to say there was no way in. The
 * API had `auth/register` all along; it had no door.
 */
function renderRegister() {
  const server = stubApi({
    'GET auth/me': { status: 401, body: { message: 'Unauthenticated.' } },
    'POST auth/register': { status: 201, body: { organization: { id: 'org_1' }, user: { id: 'usr_1' } } },
    'POST auth/login': { body: { token: 'tok_1', user: { id: 'usr_1' } } },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
  })

  renderWithProviders(<RegisterPage />, { signedIn: false })

  return server
}

async function fillTheForm() {
  await userEvent.type(await screen.findByLabelText('Company name'), 'Probe Lettings')
  await userEvent.type(screen.getByLabelText('First name'), 'Ana')
  await userEvent.type(screen.getByLabelText('Work email'), 'ana@example.test')
  await userEvent.type(screen.getByLabelText('Password'), 'correct-horse-battery-9')
  await userEvent.type(screen.getByLabelText('Confirm password'), 'correct-horse-battery-9')
}

describe('creating a company', () => {
  it('registers and signs the person straight in', async () => {
    const server = renderRegister()

    await fillTheForm()
    await userEvent.click(screen.getByRole('button', { name: 'Create company' }))

    const [registered] = server.callsTo('POST', 'auth/register')

    expect(registered?.body).toMatchObject({
      organization_name: 'Probe Lettings',
      first_name: 'Ana',
      email: 'ana@example.test',
      password_confirmation: 'correct-horse-battery-9',
    })

    // Having just chosen a password, being handed back to a login form reads as
    // a failure.
    expect(server.callsTo('POST', 'auth/login')).toHaveLength(1)
  })

  it('states the password rule before it is broken, not after', async () => {
    renderRegister()

    expect(await screen.findByText(/At least 12 characters/)).toBeInTheDocument()
    expect(screen.getByText(/known breached passwords/)).toBeInTheDocument()
  })

  it('puts the server’s complaint on the field it belongs to', async () => {
    const server = renderRegister()
    server.on('POST auth/register', {
      status: 422,
      body: {
        message: 'Invalid.',
        errors: { email: ['An account with this email already exists. Please sign in instead.'] },
      },
    })

    await fillTheForm()
    await userEvent.click(screen.getByRole('button', { name: 'Create company' }))

    expect(await screen.findByText(/already exists/)).toBeInTheDocument()
  })

  it('sends the browser’s timezone so nobody has to pick one', async () => {
    const server = renderRegister()

    await fillTheForm()
    await userEvent.click(screen.getByRole('button', { name: 'Create company' }))

    const [registered] = server.callsTo('POST', 'auth/register')

    expect(registered?.body).toHaveProperty('timezone')
  })
})
