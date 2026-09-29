import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { KeyRound, MailCheck } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import { AuthShell } from '@/components/AuthShell'

/**
 * Asking for a reset link.
 *
 * The answer is deliberately the same whether or not the address belongs to an
 * account — the server is written that way, and the screen must not undo it by
 * saying "no account found". A sign-in page that confirms which addresses exist
 * is a list of who to attack.
 */
export function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      await api.anonymous('auth/password/forgot', { email })
      setSent(true)
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError(0, 'Could not send the link.'))
    } finally {
      setSubmitting(false)
    }
  }

  if (sent) {
    return (
      <AuthShell
        badge={<MailCheck size={22} />}
        title="Check your email"
        lede="If that address has an account, a link to choose a new password is on its way. It expires in an hour."
        footer={<Link to="/login">Back to sign in</Link>}
      >
        <p className="small muted">
          Nothing arrived? Look in spam, then try again — and note that this deployment may have no
          mail transport configured, in which case the link was written to the log rather than sent.
        </p>
      </AuthShell>
    )
  }

  return (
    <AuthShell
      badge={<KeyRound size={22} />}
      title="Forgotten password"
      lede="We will email you a link to choose a new one."
      footer={<Link to="/login">Back to sign in</Link>}
    >
      {error !== null && (
        <div className="notice notice--error" role="alert">
          {error.message}
        </div>
      )}

      <form onSubmit={(event) => void submit(event)} noValidate className="stack">
        <div className="field">
          <label className="field__label" htmlFor="email">
            Email
          </label>
          <input
            id="email"
            type="email"
            autoComplete="email"
            required
            autoFocus
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />
        </div>

        <button type="submit" className="btn btn--primary btn--block" disabled={submitting}>
          {submitting ? 'Sending…' : 'Send the link'}
        </button>
      </form>
    </AuthShell>
  )
}

/**
 * Choosing the new password, with the token from the emailed link.
 */
export function ResetPasswordPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()

  const [email, setEmail] = useState(params.get('email') ?? '')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const token = params.get('token') ?? ''

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      await api.anonymous('auth/password/reset', {
        token,
        email,
        password,
        password_confirmation: confirmation,
      })

      // Not signed in automatically: a reset link can arrive in the hands of
      // somebody who should not have it, and making them type the new password
      // once more is the cheapest possible check that they know it.
      void navigate('/login')
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError(0, 'Could not reset the password.'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthShell
      badge={<KeyRound size={22} />}
      title="Choose a new password"
      lede="At least 12 characters, with letters and numbers."
      footer={<Link to="/login">Back to sign in</Link>}
    >
      {token === '' && (
        <div className="notice notice--warning" role="alert">
          This link is missing its token. Open the link from the email exactly as it was sent.
        </div>
      )}

      {error !== null && (
        <div className="notice notice--error" role="alert">
          {error.message}
        </div>
      )}

      <form onSubmit={(event) => void submit(event)} noValidate className="stack">
        <div className="field">
          <label className="field__label" htmlFor="email">
            Email
          </label>
          <input
            id="email"
            type="email"
            autoComplete="email"
            required
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />
        </div>

        <div className="field">
          <label className="field__label" htmlFor="password">
            New password
          </label>
          <input
            id="password"
            type="password"
            autoComplete="new-password"
            required
            autoFocus
            value={password}
            onChange={(event) => setPassword(event.target.value)}
          />
          {error?.fieldError('password') !== undefined && (
            <p className="field__error small" role="alert">
              {error.fieldError('password')}
            </p>
          )}
        </div>

        <div className="field">
          <label className="field__label" htmlFor="password_confirmation">
            Confirm new password
          </label>
          <input
            id="password_confirmation"
            type="password"
            autoComplete="new-password"
            required
            value={confirmation}
            onChange={(event) => setConfirmation(event.target.value)}
          />
        </div>

        <button type="submit" className="btn btn--primary btn--block" disabled={submitting || token === ''}>
          {submitting ? 'Saving…' : 'Set the password'}
        </button>
      </form>
    </AuthShell>
  )
}
