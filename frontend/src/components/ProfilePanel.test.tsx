import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ProfilePanel } from '@/components/ProfilePanel'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * Changing your own details and password.
 *
 * The rule worth holding is when the current password is asked for. Demanding
 * it to fix a typo in a surname teaches people to type their password without
 * reading the screen; not demanding it to change the address you sign in with
 * hands the account to anybody who finds the screen unlocked.
 */
function renderPanel() {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions: ['*'] }) },
    'PATCH auth/profile': { body: { data: session().user } },
    'POST auth/password': { body: { data: session().user, meta: { other_sessions_ended: true } } },
  })

  renderWithProviders(<ProfilePanel />)

  return server
}

describe('your details', () => {
  it('shows what you are currently signed in as', async () => {
    renderPanel()

    expect(await screen.findByLabelText('Email')).toHaveValue('ana@habitat.test')
    expect(screen.getByLabelText('First name')).toHaveValue('Ana')
  })

  it('does not ask for a password to change a name', async () => {
    renderPanel()

    await userEvent.clear(await screen.findByLabelText('First name'))
    await userEvent.type(screen.getByLabelText('First name'), 'Osama')

    expect(screen.queryByLabelText('Your current password')).not.toBeInTheDocument()
  })

  it('asks for the current password as soon as the email is edited', async () => {
    renderPanel()

    const email = await screen.findByLabelText('Email')

    await userEvent.clear(email)
    await userEvent.type(email, 'osama@insharo.consulting')

    // Appears because the field changed, not after a round trip that refused.
    expect(await screen.findByLabelText('Your current password')).toBeInTheDocument()
    expect(screen.getByText(/point the account at their own/)).toBeInTheDocument()
  })

  it('sends the password along with a changed email', async () => {
    const server = renderPanel()

    const email = await screen.findByLabelText('Email')
    await userEvent.clear(email)
    await userEvent.type(email, 'osama@insharo.consulting')
    await userEvent.type(screen.getByLabelText('Your current password'), 'the-old-one')
    await userEvent.click(screen.getByRole('button', { name: 'Save details' }))

    const [call] = server.callsTo('PATCH', 'auth/profile')
    const body = call?.body as { email: string; current_password?: string }

    expect(body.email).toBe('osama@insharo.consulting')
    expect(body.current_password).toBe('the-old-one')
  })

  it('shows the server’s refusal against the field it belongs to', async () => {
    const server = renderPanel()

    server.on('PATCH auth/profile', {
      status: 422,
      body: {
        message: 'The given data was invalid.',
        errors: { current_password: ['That is not your current password.'] },
      },
    })

    const email = await screen.findByLabelText('Email')
    await userEvent.clear(email)
    await userEvent.type(email, 'osama@insharo.consulting')
    await userEvent.type(screen.getByLabelText('Your current password'), 'wrong')
    await userEvent.click(screen.getByRole('button', { name: 'Save details' }))

    expect(await screen.findByText('That is not your current password.')).toBeInTheDocument()
  })
})

describe('your password', () => {
  it('states the rules before the attempt, not as a rejection after it', async () => {
    renderPanel()

    expect(
      await screen.findByText(/At least 12 characters.*known data breach/s),
    ).toBeInTheDocument()
  })

  it('warns that other devices will be signed out', async () => {
    renderPanel()

    // The consequence is real and surprising, so it is said before the button
    // rather than discovered on a phone an hour later.
    expect(
      await screen.findByText(/signs out every other device/),
    ).toBeInTheDocument()
  })

  it('sends the change and confirms what happened', async () => {
    const server = renderPanel()

    await userEvent.type(await screen.findByLabelText('Current password'), 'the-old-one')
    await userEvent.type(screen.getByLabelText('New password'), 'Insharo2026Habitat')
    await userEvent.type(screen.getByLabelText('Repeat the new password'), 'Insharo2026Habitat')
    await userEvent.click(screen.getByRole('button', { name: 'Change password' }))

    const [call] = server.callsTo('POST', 'auth/password')
    const body = call?.body as Record<string, string>

    expect(body.current_password).toBe('the-old-one')
    expect(body.password).toBe('Insharo2026Habitat')
    expect(body.password_confirmation).toBe('Insharo2026Habitat')

    expect(await screen.findByText(/Every other session was signed out/)).toBeInTheDocument()
  })

  it('surfaces a weak-password refusal', async () => {
    const server = renderPanel()

    server.on('POST auth/password', {
      status: 422,
      body: {
        message: 'The given data was invalid.',
        errors: { password: ['The given password has appeared in a data leak.'] },
      },
    })

    await userEvent.type(await screen.findByLabelText('Current password'), 'the-old-one')
    await userEvent.type(screen.getByLabelText('New password'), '123456789')
    await userEvent.type(screen.getByLabelText('Repeat the new password'), '123456789')
    await userEvent.click(screen.getByRole('button', { name: 'Change password' }))

    expect(
      await screen.findByText('The given password has appeared in a data leak.'),
    ).toBeInTheDocument()
  })
})
