import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { SessionUser } from '@/api/types'
import { useAuth } from '@/lib/auth'

/**
 * Your own name, email address and password.
 *
 * Until this existed the only route to a new password was a reset link by
 * email, which is no use on a deployment with no mail provider, and there was
 * no route to a new email address at all.
 *
 * The current password is asked for on both, and the reason is said on the
 * form rather than left to be discovered: the realistic threat is a borrowed
 * laptop with a live session, not a stolen password.
 */
export function ProfilePanel() {
  const { session } = useAuth()

  // The form's initial values come from the session, and `useState` only reads
  // its initial value once. Mounting before the session has loaded would leave
  // every field empty for good — and saving would then blank the name of
  // whoever opened the screen too quickly.
  if (session === null) {
    return (
      <div className="empty">
        <span className="spinner" />
        <div className="mt-2 muted">Loading…</div>
      </div>
    )
  }

  return (
    <>
      <DetailsForm user={session.user} />
      <PasswordForm />
    </>
  )
}

function DetailsForm({ user }: { user: SessionUser }) {
  const { refresh } = useAuth()

  const [firstName, setFirstName] = useState(user.first_name)
  const [lastName, setLastName] = useState(user.last_name ?? '')
  const [email, setEmail] = useState(user.email)
  const [currentPassword, setCurrentPassword] = useState('')
  const [saved, setSaved] = useState(false)

  const emailChanged = email.trim().toLowerCase() !== user.email.toLowerCase()

  const save = useMutation({
    mutationFn: () =>
      api.patch<{ data: SessionUser }>('auth/profile', {
        first_name: firstName,
        last_name: lastName,
        email: email.trim(),
        ...(emailChanged ? { current_password: currentPassword } : {}),
      }),
    onSuccess: async () => {
      setSaved(true)
      setCurrentPassword('')
      await refresh()
    },
  })

  const error = save.error instanceof ApiError ? save.error : null

  return (
    <section className="card mb-3">
      <header className="card__header">
        <h2>Your details</h2>
      </header>

      <form
        className="card__body"
        onSubmit={(event) => {
          event.preventDefault()
          setSaved(false)
          save.mutate()
        }}
      >
        {error !== null && !error.isValidation && (
          <div className="notice notice--error" role="alert">
            {error.message}
          </div>
        )}

        {saved && !save.isPending && <div className="notice notice--info">Saved.</div>}

        <div className="field">
          <label className="field__label" htmlFor="first-name">
            First name
          </label>
          <input
            id="first-name"
            value={firstName}
            onChange={(event) => setFirstName(event.target.value)}
            required
          />
          {error?.fieldError('first_name') !== undefined && (
            <span className="field__error">{error.fieldError('first_name')}</span>
          )}
        </div>

        <div className="field">
          <label className="field__label" htmlFor="last-name">
            Last name
          </label>
          <input
            id="last-name"
            value={lastName}
            onChange={(event) => setLastName(event.target.value)}
          />
        </div>

        <div className="field">
          <label className="field__label" htmlFor="profile-email">
            Email
          </label>
          <input
            id="profile-email"
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            required
            aria-invalid={error?.fieldError('email') !== undefined}
          />
          <span className="field__hint">
            This is what you sign in with.
            {!user.email_verified && ' It has not been verified.'}
          </span>
          {error?.fieldError('email') !== undefined && (
            <span className="field__error">{error.fieldError('email')}</span>
          )}
        </div>

        {/* Only when it is actually needed, so nobody is trained to type their
            password to fix a typo in their surname. */}
        {emailChanged && (
          <div className="field">
            <label className="field__label" htmlFor="details-current-password">
              Your current password
            </label>
            <input
              id="details-current-password"
              type="password"
              autoComplete="current-password"
              value={currentPassword}
              onChange={(event) => setCurrentPassword(event.target.value)}
              required
            />
            <span className="field__hint">
              Needed to change the address you sign in with — otherwise anybody who
              found your screen unlocked could point the account at their own.
            </span>
            {error?.fieldError('current_password') !== undefined && (
              <span className="field__error">{error.fieldError('current_password')}</span>
            )}
          </div>
        )}

        <button type="submit" className="btn" disabled={save.isPending}>
          {save.isPending ? 'Saving…' : 'Save details'}
        </button>
      </form>
    </section>
  )
}

function PasswordForm() {
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [done, setDone] = useState(false)

  const change = useMutation({
    mutationFn: () =>
      api.post('auth/password', {
        current_password: current,
        password: next,
        password_confirmation: confirmation,
      }),
    onSuccess: () => {
      setDone(true)
      setCurrent('')
      setNext('')
      setConfirmation('')
    },
  })

  const error = change.error instanceof ApiError ? change.error : null

  return (
    <section className="card mb-3">
      <header className="card__header">
        <h2>Your password</h2>
      </header>

      <form
        className="card__body"
        onSubmit={(event) => {
          event.preventDefault()
          setDone(false)
          change.mutate()
        }}
      >
        {error !== null && !error.isValidation && (
          <div className="notice notice--error" role="alert">
            {error.message}
          </div>
        )}

        {done && !change.isPending && (
          <div className="notice notice--info">
            Your password was changed. Every other session was signed out.
          </div>
        )}

        <div className="field">
          <label className="field__label" htmlFor="current-password">
            Current password
          </label>
          <input
            id="current-password"
            type="password"
            autoComplete="current-password"
            value={current}
            onChange={(event) => setCurrent(event.target.value)}
            required
          />
          {error?.fieldError('current_password') !== undefined && (
            <span className="field__error">{error.fieldError('current_password')}</span>
          )}
        </div>

        <div className="field">
          <label className="field__label" htmlFor="new-password">
            New password
          </label>
          <input
            id="new-password"
            type="password"
            autoComplete="new-password"
            value={next}
            onChange={(event) => setNext(event.target.value)}
            required
          />
          {/* Said before the attempt rather than as a rejection afterwards. */}
          <span className="field__hint">
            At least 12 characters, with letters and numbers, and not one that has
            appeared in a known data breach.
          </span>
          {error?.fieldError('password') !== undefined && (
            <span className="field__error">{error.fieldError('password')}</span>
          )}
        </div>

        <div className="field">
          <label className="field__label" htmlFor="confirm-password">
            Repeat the new password
          </label>
          <input
            id="confirm-password"
            type="password"
            autoComplete="new-password"
            value={confirmation}
            onChange={(event) => setConfirmation(event.target.value)}
            required
          />
        </div>

        <p className="small faint">
          Changing this signs out every other device. The one you are using now stays
          signed in.
        </p>

        <button type="submit" className="btn" disabled={change.isPending}>
          {change.isPending ? 'Changing…' : 'Change password'}
        </button>
      </form>
    </section>
  )
}
