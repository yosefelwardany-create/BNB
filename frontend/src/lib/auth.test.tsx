import { describe, expect, it } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { currentAuth } from '@/api/client'
import { useAuth } from '@/lib/auth'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * Who is signed in and what they may do.
 *
 * The permission list decides what the interface *shows*. It is not a control —
 * every action is authorised again on the server — but it is the difference
 * between a coherent screen and one covered in buttons that answer 403. And the
 * sign-in half carries a rule with teeth: a correct password is not a session
 * when a second factor is enabled, and nothing may be stored until the code is
 * right.
 */

function Probe() {
  const { can, canAny, session: me, loading } = useAuth()

  if (loading) return <p>loading</p>

  return (
    <ul>
      <li>user: {me?.user.name ?? 'nobody'}</li>
      <li>reservations.view: {String(can('reservations.view'))}</li>
      <li>payments.refund: {String(can('payments.refund'))}</li>
      <li>any money: {String(canAny(['payments.view', 'expenses.manage']))}</li>
    </ul>
  )
}

async function renderProbe(overrides: Parameters<typeof session>[0] = {}) {
  stubApi({ 'GET auth/me': { body: session(overrides) } })

  renderWithProviders(<Probe />)

  await screen.findByText(/^user:/)
}

describe('permissions', () => {
  it('grants only what the server listed', async () => {
    await renderProbe({ permissions: ['reservations.view', 'payments.view'] })

    expect(screen.getByText('reservations.view: true')).toBeInTheDocument()
    expect(screen.getByText('payments.refund: false')).toBeInTheDocument()
  })

  it('canAny needs one of them, not all', async () => {
    await renderProbe({ permissions: ['expenses.manage'] })

    expect(screen.getByText('any money: true')).toBeInTheDocument()
  })

  it('treats a wildcard as every permission', async () => {
    // The owner role holds '*' rather than an enumeration, so a permission
    // added next week is granted without a migration.
    await renderProbe({ permissions: ['*'] })

    expect(screen.getByText('reservations.view: true')).toBeInTheDocument()
    expect(screen.getByText('payments.refund: true')).toBeInTheDocument()
  })

  it('treats a platform administrator as unrestricted', async () => {
    await renderProbe({ permissions: [], is_platform_admin: true })

    expect(screen.getByText('payments.refund: true')).toBeInTheDocument()
  })

  it('grants nothing when the permission list is empty', async () => {
    await renderProbe({ permissions: [] })

    expect(screen.getByText('reservations.view: false')).toBeInTheDocument()
    expect(screen.getByText('any money: false')).toBeInTheDocument()
  })
})

function SignInProbe() {
  const { signIn, completeMfa, session: me } = useAuth()

  return (
    <div>
      <p>signed in as: {me?.user.name ?? 'nobody'}</p>

      <button
        type="button"
        onClick={() => {
          void signIn('ana@habitat.test', 'correct-horse').then((result) => {
            document.title = result.mfaRequired ? `challenge:${result.reference}` : 'signed-in'
          })
        }}
      >
        Sign in
      </button>

      <button type="button" onClick={() => void completeMfa('chal_1', '123456')}>
        Submit code
      </button>
    </div>
  )
}

describe('signing in', () => {
  it('stores the token and loads the session', async () => {
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

    renderWithProviders(<SignInProbe />, { signedIn: false })

    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    await screen.findByText('signed in as: Ana Ferreira')
    expect(currentAuth()?.token).toBe('issued-token')
  })

  it('stores nothing when a second factor is still owed', async () => {
    stubApi({
      'POST auth/login': {
        body: {
          mfa_required: true,
          challenge: { reference: 'chal_1', expires_in: 300 },
          message: 'A verification code is required.',
        },
      },
      'GET auth/me': { body: session() },
    })

    renderWithProviders(<SignInProbe />, { signedIn: false })

    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    await waitFor(() => expect(document.title).toBe('challenge:chal_1'))

    // The whole point of the second factor: a correct password on its own
    // leaves nothing behind that could be used as a session.
    expect(currentAuth()).toBeNull()
    expect(screen.getByText('signed in as: nobody')).toBeInTheDocument()
  })

  it('finishes the sign-in once the code is accepted', async () => {
    stubApi({
      'POST auth/mfa/challenge': {
        body: {
          user: session().user,
          organizations: [session().organization],
          token: 'issued-after-mfa',
        },
      },
      'GET auth/me': { body: session() },
    })

    renderWithProviders(<SignInProbe />, { signedIn: false })

    await userEvent.click(screen.getByRole('button', { name: 'Submit code' }))

    await screen.findByText('signed in as: Ana Ferreira')
    expect(currentAuth()?.token).toBe('issued-after-mfa')
  })

  it('drops the session when the server rejects the token', async () => {
    stubApi({ 'GET auth/me': { body: session() } })

    renderWithProviders(<Probe />)
    await screen.findByText('user: Ana Ferreira')

    // Dispatched by the client on any 401, so an expired token returns the
    // user to sign-in rather than leaving them on a screen that has silently
    // stopped working.
    window.dispatchEvent(new CustomEvent('habitat:unauthenticated'))

    await screen.findByText('user: nobody')
  })
})
