import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { currentAuth } from '@/api/client'
import { LoginPage } from '@/pages/LoginPage'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * Signing in.
 *
 * The second-factor screen is where the rule has teeth: a correct password with
 * MFA enabled must leave nothing behind that could be used as a session, and
 * the screen must hold only the challenge reference — which authorises nothing
 * — rather than keeping the password around waiting for a second form.
 */
async function signIn(email = 'ana@habitat.test', password = 'correct-horse') {
  await userEvent.type(screen.getByLabelText('Email'), email)
  await userEvent.type(screen.getByLabelText('Password'), password)
  await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))
}

describe('signing in', () => {
  it('signs in with a password alone when no second factor is enabled', async () => {
    stubApi({
      'POST auth/login': {
        body: {
          user: session().user,
          organizations: [session().organization],
          token: 'issued-token',
        },
      },
      'GET auth/me': { body: session() },
    })

    renderWithProviders(<LoginPage />, { signedIn: false })
    await signIn()

    expect(currentAuth()?.token).toBe('issued-token')
  })

  it('shows the server’s own refusal rather than a generic one', async () => {
    stubApi({
      'POST auth/login': {
        status: 401,
        body: { message: 'Those credentials do not match our records.' },
      },
    })

    renderWithProviders(<LoginPage />, { signedIn: false })
    await signIn()

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Those credentials do not match our records.',
    )
  })

  it('shows a validation message against the field it belongs to', async () => {
    stubApi({
      'POST auth/login': {
        status: 422,
        body: {
          message: 'The given data was invalid.',
          errors: { email: ['That is not a valid email address.'] },
        },
      },
    })

    renderWithProviders(<LoginPage />, { signedIn: false })
    await signIn('not-an-email')

    expect(await screen.findByText('That is not a valid email address.')).toBeInTheDocument()
    expect(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true')
  })
})

describe('the second factor', () => {
  function stubChallenge() {
    return stubApi({
      'POST auth/login': {
        body: {
          mfa_required: true,
          challenge: { reference: 'chal_1', expires_in: 300 },
          message: 'A verification code is required.',
        },
      },
      'GET auth/me': { body: session() },
    })
  }

  it('asks for a code and stores nothing yet', async () => {
    stubChallenge()

    renderWithProviders(<LoginPage />, { signedIn: false })
    await signIn()

    expect(await screen.findByLabelText('Code')).toBeInTheDocument()

    // The whole point: a correct password on its own is not a session.
    expect(currentAuth()).toBeNull()
  })

  it('accepts a recovery code as well as a generated one', async () => {
    stubChallenge()

    renderWithProviders(<LoginPage />, { signedIn: false })
    await signIn()

    await screen.findByLabelText('Code')

    // Somebody who has lost their phone is locked out of their own business
    // otherwise, so the field is not restricted to six digits.
    expect(screen.getByText(/A recovery code works once/)).toBeInTheDocument()
    expect(screen.getByLabelText('Code')).toHaveAttribute('inputMode', 'text')
  })

  it('completes the sign-in when the code is accepted', async () => {
    const server = stubChallenge()
    server.on('POST auth/mfa/challenge', {
      body: {
        user: session().user,
        organizations: [session().organization],
        token: 'issued-after-mfa',
      },
    })

    renderWithProviders(<LoginPage />, { signedIn: false })
    await signIn()

    await userEvent.type(await screen.findByLabelText('Code'), '123456')
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    // The reference identifies the pending sign-in; the password is not sent
    // again and was never kept.
    const [call] = server.callsTo('POST', 'auth/mfa/challenge')

    expect(call?.body).toMatchObject({ challenge: 'chal_1', code: '123456' })
    expect(call?.body).not.toHaveProperty('password')
  })

  it('clears the code and says so when it is wrong', async () => {
    const server = stubChallenge()
    server.on('POST auth/mfa/challenge', {
      status: 422,
      body: { message: 'That code is not valid.' },
    })

    renderWithProviders(<LoginPage />, { signedIn: false })
    await signIn()

    await userEvent.type(await screen.findByLabelText('Code'), '000000')
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('That code is not valid.')
    expect(screen.getByLabelText('Code')).toHaveValue('')
    expect(currentAuth()).toBeNull()
  })

  it('lets somebody start again rather than stranding them on the code screen', async () => {
    stubChallenge()

    renderWithProviders(<LoginPage />, { signedIn: false })
    await signIn()

    await userEvent.click(await screen.findByRole('button', { name: 'Start again' }))

    expect(screen.getByLabelText('Email')).toBeInTheDocument()
  })
})
