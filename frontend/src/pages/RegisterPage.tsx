import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Building2 } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import { AuthShell } from '@/components/AuthShell'
import { useAuth } from '@/lib/auth'

/**
 * Creating an organization and its first account.
 *
 * Until now the only ways into this platform were the demo seeder and the
 * platform console, which meant nobody could actually start using it. The API
 * has had `auth/register` all along; it simply had no door.
 *
 * The password rule is the server's and is stated up front rather than after a
 * rejection: twelve characters, letters and numbers, and not one that has turned
 * up in a breach corpus. Telling somebody that *after* they have chosen is how
 * you train people to pick `Password1234!`.
 */
export function RegisterPage() {
  const { signIn } = useAuth()
  const navigate = useNavigate()

  const [values, setValues] = useState({
    organization_name: '',
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    password_confirmation: '',
  })
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  function set(field: keyof typeof values, value: string) {
    setValues((current) => ({ ...current, [field]: value }))
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      await api.anonymous('auth/register', {
        ...values,
        // The browser knows this and the person should not have to; the server
        // validates it and falls back to UTC if it is nonsense.
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
      })

      // Signing them straight in: having just proved the address and chosen the
      // password, being returned to a login form reads as a failure.
      await signIn(values.email, values.password)
      void navigate('/')
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError(0, 'Could not create the account.'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthShell
      badge={<Building2 size={22} />}
      title="Create your company"
      lede="One account, one company. You can invite colleagues once you are in."
      footer={
        <>
          Already have an account? <Link to="/login">Sign in</Link>
        </>
      }
    >
      {error !== null && Object.keys(error.errors).length === 0 && (
        <div className="notice notice--error" role="alert">
          {error.message}
        </div>
      )}

      <form onSubmit={(event) => void submit(event)} noValidate className="stack">
        <Field label="Company name" name="organization_name" error={error}>
          <input
            id="organization_name"
            type="text"
            autoComplete="organization"
            required
            autoFocus
            value={values.organization_name}
            onChange={(event) => set('organization_name', event.target.value)}
          />
        </Field>

        <div className="grid grid--two">
          <Field label="First name" name="first_name" error={error}>
            <input
              id="first_name"
              type="text"
              autoComplete="given-name"
              required
              value={values.first_name}
              onChange={(event) => set('first_name', event.target.value)}
            />
          </Field>

          <Field label="Last name" name="last_name" error={error}>
            <input
              id="last_name"
              type="text"
              autoComplete="family-name"
              value={values.last_name}
              onChange={(event) => set('last_name', event.target.value)}
            />
          </Field>
        </div>

        <Field label="Work email" name="email" error={error}>
          <input
            id="email"
            type="email"
            autoComplete="email"
            required
            value={values.email}
            onChange={(event) => set('email', event.target.value)}
          />
        </Field>

        <Field
          label="Password"
          name="password"
          error={error}
          hint="At least 12 characters, with letters and numbers. Checked against known breached passwords."
        >
          <input
            id="password"
            type="password"
            autoComplete="new-password"
            required
            value={values.password}
            onChange={(event) => set('password', event.target.value)}
          />
        </Field>

        <Field label="Confirm password" name="password_confirmation" error={error}>
          <input
            id="password_confirmation"
            type="password"
            autoComplete="new-password"
            required
            value={values.password_confirmation}
            onChange={(event) => set('password_confirmation', event.target.value)}
          />
        </Field>

        <button type="submit" className="btn btn--primary btn--block" disabled={submitting}>
          {submitting ? 'Creating…' : 'Create company'}
        </button>
      </form>
    </AuthShell>
  )
}

/**
 * A labelled control that shows the server's complaint about its own field.
 */
function Field({
  label,
  name,
  error,
  hint,
  children,
}: {
  label: string
  name: string
  error: ApiError | null
  hint?: string
  children: React.ReactNode
}) {
  const message = error?.fieldError(name)

  return (
    <div className="field">
      <label className="field__label" htmlFor={name}>
        {label}
      </label>
      {children}
      {hint !== undefined && <span className="field__hint">{hint}</span>}
      {message !== undefined && (
        <p className="field__error small" role="alert">
          {message}
        </p>
      )}
    </div>
  )
}
