import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { UserPlus } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import { AuthShell } from '@/components/AuthShell'
import { useAuth } from '@/lib/auth'

interface Invitation {
  email: string
  first_name: string | null
  last_name: string | null
  organization_name: string
  expires_at: string
  /** False when this address already has a login, so only the join is needed. */
  requires_password: boolean
}

/**
 * Joining a company somebody invited you to.
 *
 * The invitation is fetched before anything is asked, so the person can see
 * which company they are about to join and under which address. An invitation
 * that has expired or been withdrawn says so here rather than after they have
 * chosen a password.
 */
export function AcceptInvitationPage() {
  const { token = '' } = useParams()
  const navigate = useNavigate()
  const { signIn } = useAuth()

  const invitation = useQuery({
    queryKey: ['invitation', token],
    queryFn: () => api.anonymousGet<Invitation>(`auth/invitations/${token}`),
    retry: false,
  })

  const [firstName, setFirstName] = useState('')
  const [lastName, setLastName] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const details = invitation.data

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      await api.anonymous('auth/invitations/accept', {
        token,
        first_name: firstName || details?.first_name || undefined,
        last_name: lastName || details?.last_name || undefined,
        password: details?.requires_password === true ? password : undefined,
        password_confirmation: details?.requires_password === true ? confirmation : undefined,
      })

      if (details?.requires_password === true) {
        await signIn(details.email, password)
        void navigate('/')

        return
      }

      // They already had a login, so the invitation only added a membership.
      void navigate('/login')
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError(0, 'Could not accept the invitation.'))
    } finally {
      setSubmitting(false)
    }
  }

  if (invitation.isLoading) {
    return <AuthShell title="Checking the invitation…" badge={<UserPlus size={22} />}>{null}</AuthShell>
  }

  if (invitation.isError || details === undefined) {
    return (
      <AuthShell
        badge={<UserPlus size={22} />}
        title="That invitation is not valid"
        lede="It may have expired, been withdrawn, or already been used. Ask whoever invited you to send another."
        footer={<Link to="/login">Back to sign in</Link>}
      >
        {null}
      </AuthShell>
    )
  }

  return (
    <AuthShell
      badge={<UserPlus size={22} />}
      title={`Join ${details.organization_name}`}
      lede={`Invited as ${details.email}.`}
      footer={<Link to="/login">Back to sign in</Link>}
    >
      {error !== null && (
        <div className="notice notice--error" role="alert">
          {error.message}
        </div>
      )}

      <form onSubmit={(event) => void submit(event)} noValidate className="stack">
        <div className="grid grid--two">
          <div className="field">
            <label className="field__label" htmlFor="first_name">
              First name
            </label>
            <input
              id="first_name"
              type="text"
              autoComplete="given-name"
              value={firstName || (details.first_name ?? '')}
              onChange={(event) => setFirstName(event.target.value)}
            />
          </div>

          <div className="field">
            <label className="field__label" htmlFor="last_name">
              Last name
            </label>
            <input
              id="last_name"
              type="text"
              autoComplete="family-name"
              value={lastName || (details.last_name ?? '')}
              onChange={(event) => setLastName(event.target.value)}
            />
          </div>
        </div>

        {details.requires_password ? (
          <>
            <div className="field">
              <label className="field__label" htmlFor="password">
                Choose a password
              </label>
              <input
                id="password"
                type="password"
                autoComplete="new-password"
                required
                value={password}
                onChange={(event) => setPassword(event.target.value)}
              />
              <span className="field__hint">At least 12 characters, with letters and numbers.</span>
              {error?.fieldError('password') !== undefined && (
                <p className="field__error small" role="alert">
                  {error.fieldError('password')}
                </p>
              )}
            </div>

            <div className="field">
              <label className="field__label" htmlFor="password_confirmation">
                Confirm password
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
          </>
        ) : (
          <p className="small muted">
            You already have a login for this address, so nothing else is needed — sign in as usual
            once you have joined.
          </p>
        )}

        <button type="submit" className="btn btn--primary btn--block" disabled={submitting}>
          {submitting ? 'Joining…' : `Join ${details.organization_name}`}
        </button>
      </form>
    </AuthShell>
  )
}
