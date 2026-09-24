import { useState } from 'react'
import { ApiError } from '@/api/client'
import { useAuth } from '@/lib/auth'

export function LoginPage() {
  const { signIn, completeMfa } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  // Set when the password was right and a code is still needed. Holding only
  // the reference is the point: it authorises nothing, and the password is not
  // kept around waiting for a second screen.
  const [challenge, setChallenge] = useState<string | null>(null)
  const [code, setCode] = useState('')

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      const result = await signIn(email, password)

      if (result.mfaRequired && result.reference !== undefined) {
        setChallenge(result.reference)
        setPassword('')
      }
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError(0, 'Unable to sign in.'))
    } finally {
      setSubmitting(false)
    }
  }

  async function handleChallenge(event: React.FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      await completeMfa(challenge ?? '', code)
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError(0, 'That code was not accepted.'))
      setCode('')
    } finally {
      setSubmitting(false)
    }
  }

  if (challenge !== null) {
    return (
      <div className="auth">
        <div className="auth__card card">
          <div className="card__body">
            <h1 className="mb-2">Two-factor authentication</h1>
            <p className="muted small" style={{ marginTop: 0, marginBottom: 20 }}>
              Enter the six-digit code from your authenticator app, or one of your recovery
              codes.
            </p>

            {error !== null && (
              <div className="notice notice--error" role="alert">
                {error.message}
              </div>
            )}

            <form onSubmit={handleChallenge} noValidate>
              <div className="field">
                <label className="field__label" htmlFor="code">
                  Code
                </label>
                <input
                  id="code"
                  type="text"
                  inputMode="text"
                  autoComplete="one-time-code"
                  autoFocus
                  required
                  value={code}
                  onChange={(event) => setCode(event.target.value)}
                />
                <span className="field__hint">
                  A recovery code works once and looks like <span className="mono">abcde-12345</span>.
                </span>
              </div>

              <button
                type="submit"
                className="btn btn--primary"
                disabled={submitting || code.trim() === ''}
                style={{ width: '100%' }}
              >
                {submitting ? 'Checking…' : 'Sign in'}
              </button>

              <button
                type="button"
                className="btn btn--ghost btn--sm mt-2"
                style={{ width: '100%' }}
                onClick={() => {
                  setChallenge(null)
                  setCode('')
                  setError(null)
                }}
              >
                Start again
              </button>
            </form>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="auth">
      <div className="auth__card card">
        <div className="card__body">
          <h1 className="mb-2">Sign in</h1>
          <p className="muted small" style={{ marginTop: 0, marginBottom: 20 }}>
            Habitat property management
          </p>

          {error !== null && !error.isValidation && (
            <div className="notice notice--error" role="alert">
              {error.message}
            </div>
          )}

          <form onSubmit={handleSubmit} noValidate>
            <div className="field">
              <label className="field__label" htmlFor="email">
                Email
              </label>
              <input
                id="email"
                type="email"
                autoComplete="username"
                required
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                aria-invalid={error?.fieldError('email') !== undefined}
              />
              {error?.fieldError('email') !== undefined && (
                <span className="field__error">{error.fieldError('email')}</span>
              )}
            </div>

            <div className="field">
              <label className="field__label" htmlFor="password">
                Password
              </label>
              <input
                id="password"
                type="password"
                autoComplete="current-password"
                required
                value={password}
                onChange={(event) => setPassword(event.target.value)}
              />
              {error?.fieldError('password') !== undefined && (
                <span className="field__error">{error.fieldError('password')}</span>
              )}
            </div>

            <button type="submit" className="btn btn--primary" disabled={submitting} style={{ width: '100%' }}>
              {submitting ? <span className="spinner" /> : null}
              {submitting ? 'Signing in…' : 'Sign in'}
            </button>
          </form>
        </div>
      </div>
    </div>
  )
}
