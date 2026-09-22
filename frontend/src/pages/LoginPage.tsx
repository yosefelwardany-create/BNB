import { useState } from 'react'
import { ApiError } from '@/api/client'
import { useAuth } from '@/lib/auth'

export function LoginPage() {
  const { signIn } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      await signIn(email, password)
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError(0, 'Unable to sign in.'))
    } finally {
      setSubmitting(false)
    }
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
