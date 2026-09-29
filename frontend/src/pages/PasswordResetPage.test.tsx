import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ForgotPasswordPage } from '@/pages/PasswordResetPage'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * Recovering an account.
 *
 * The claim worth protecting is a security one: the answer must be the same
 * whether or not the address has an account. The server is written that way, and
 * a screen that said "no account found" would undo it — a sign-in page that
 * confirms which addresses exist is a list of who to attack.
 */
function renderForgot() {
  const server = stubApi({
    'GET auth/me': { status: 401, body: { message: 'Unauthenticated.' } },
    'POST auth/password/forgot': { body: { message: 'If that address has an account, a link is on its way.' } },
  })

  renderWithProviders(<ForgotPasswordPage />, { signedIn: false })

  return server
}

describe('forgotten password', () => {
  it('asks for the link', async () => {
    const server = renderForgot()

    await userEvent.type(await screen.findByLabelText('Email'), 'ana@example.test')
    await userEvent.click(screen.getByRole('button', { name: 'Send the link' }))

    const [asked] = server.callsTo('POST', 'auth/password/forgot')

    expect(asked?.body).toEqual({ email: 'ana@example.test' })
  })

  it('says the same thing whether or not the account exists', async () => {
    renderForgot()

    await userEvent.type(await screen.findByLabelText('Email'), 'nobody@example.test')
    await userEvent.click(screen.getByRole('button', { name: 'Send the link' }))

    expect(await screen.findByText(/If that address has an account/)).toBeInTheDocument()
    expect(screen.queryByText(/not found/i)).not.toBeInTheDocument()
  })

  it('admits when this deployment cannot actually send email', async () => {
    renderForgot()

    await userEvent.type(await screen.findByLabelText('Email'), 'ana@example.test')
    await userEvent.click(screen.getByRole('button', { name: 'Send the link' }))

    // The same honesty rule as everywhere else: with no mail transport the link
    // went to a log, and somebody waiting for an email should know that.
    expect(await screen.findByText(/written to the log rather than sent/)).toBeInTheDocument()
  })
})
