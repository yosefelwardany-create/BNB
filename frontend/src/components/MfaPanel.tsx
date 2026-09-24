import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { MfaStatus } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'

/**
 * Your own two-factor authentication.
 *
 * Shows the secret as text as well as a URI. Not every person enrolling has a
 * camera pointed at the right screen — somebody setting this up on the machine
 * the app is open on cannot scan their own monitor — and an enrolment flow that
 * only offers a QR code excludes them.
 *
 * Recovery codes appear exactly once, and the panel says so before generating
 * them rather than after.
 */
export function MfaPanel() {
  const queryClient = useQueryClient()

  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [setup, setSetup] = useState<{ secret: string; uri: string } | null>(null)
  const [codes, setCodes] = useState<string[] | null>(null)
  const [disabling, setDisabling] = useState(false)

  const status = useQuery({
    queryKey: ['mfa'],
    queryFn: () => api.get<{ data: MfaStatus }>('auth/mfa'),
  })

  const begin = useMutation({
    mutationFn: () => api.post<{ data: { secret: string; uri: string } }>('auth/mfa/begin', { password }),
    onSuccess: (result) => {
      setSetup(result.data)
      setPassword('')
    },
  })

  const confirm = useMutation({
    mutationFn: () =>
      api.post<{ data: { recovery_codes: string[] } }>('auth/mfa/confirm', { code }),
    onSuccess: (result) => {
      setCodes(result.data.recovery_codes)
      setSetup(null)
      setCode('')
      void queryClient.invalidateQueries({ queryKey: ['mfa'] })
    },
  })

  const disable = useMutation({
    mutationFn: () => api.delete('auth/mfa', { password, code }),
    onSuccess: () => {
      setDisabling(false)
      setPassword('')
      setCode('')
      void queryClient.invalidateQueries({ queryKey: ['mfa'] })
    },
  })

  const regenerate = useMutation({
    mutationFn: () =>
      api.post<{ data: { recovery_codes: string[] } }>('auth/mfa/recovery-codes', { password }),
    onSuccess: (result) => {
      setCodes(result.data.recovery_codes)
      setPassword('')
      void queryClient.invalidateQueries({ queryKey: ['mfa'] })
    },
  })

  const failed = begin.error ?? confirm.error ?? disable.error ?? regenerate.error
  const enabled = status.data?.data.enabled === true

  return (
    <section className="card">
      <header className="card__header">
        <h2>Two-factor authentication</h2>
        {enabled ? <Chip label="On" colour="emerald" /> : <Chip label="Off" colour="amber" />}
      </header>

      <div className="card__body">
        {failed !== null && failed !== undefined && (
          <div className="notice notice--error" role="alert">
            {failed instanceof ApiError ? failed.message : 'That did not work.'}
          </div>
        )}

        {codes !== null && (
          <div className="notice notice--warning">
            <div className="strong">Store these recovery codes now.</div>
            <p className="small">
              Each works once, and they are the only way in if you lose your phone. Only
              hashes are kept, so they cannot be shown again.
            </p>
            <pre className="secret">{codes.join('\n')}</pre>
            <button type="button" className="btn btn--sm" onClick={() => setCodes(null)}>
              I have stored them
            </button>
          </div>
        )}

        <QueryState isLoading={status.isLoading} error={status.error}>
          {enabled ? (
            <>
              <p className="mt-0">
                Your account asks for a code from your authenticator app at every sign-in.
                You have{' '}
                <strong>{status.data?.data.recovery_codes_remaining ?? 0}</strong> recovery
                code(s) left.
              </p>

              {disabling ? (
                <form
                  onSubmit={(event) => {
                    event.preventDefault()
                    disable.mutate()
                  }}
                >
                  <p className="small">
                    {/* Said before they type, not after. */}
                    Your password <em>and</em> a current code are both needed — a stolen
                    password must not be enough to remove the thing protecting against a
                    stolen password.
                  </p>

                  <div className="field">
                    <label className="field__label" htmlFor="off-password">
                      Password
                    </label>
                    <input
                      id="off-password"
                      type="password"
                      autoComplete="current-password"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                    />
                  </div>

                  <div className="field">
                    <label className="field__label" htmlFor="off-code">
                      Code
                    </label>
                    <input
                      id="off-code"
                      type="text"
                      autoComplete="one-time-code"
                      value={code}
                      onChange={(e) => setCode(e.target.value)}
                    />
                  </div>

                  <div className="row">
                    <button
                      type="button"
                      className="btn btn--ghost btn--sm"
                      onClick={() => setDisabling(false)}
                    >
                      Keep it on
                    </button>
                    <button
                      type="submit"
                      className="btn btn--danger btn--sm"
                      disabled={disable.isPending || password === '' || code === ''}
                    >
                      Turn off
                    </button>
                  </div>
                </form>
              ) : (
                <form
                  onSubmit={(event) => {
                    event.preventDefault()
                    regenerate.mutate()
                  }}
                >
                  <div className="field">
                    <label className="field__label" htmlFor="regen-password">
                      Password
                    </label>
                    <input
                      id="regen-password"
                      type="password"
                      autoComplete="current-password"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                    />
                    <span className="field__hint">
                      Needed to issue new recovery codes. The old ones stop working.
                    </span>
                  </div>

                  <div className="row">
                    <button
                      type="submit"
                      className="btn btn--sm"
                      disabled={regenerate.isPending || password === ''}
                    >
                      New recovery codes
                    </button>
                    <button
                      type="button"
                      className="btn btn--ghost btn--sm"
                      onClick={() => setDisabling(true)}
                    >
                      Turn off
                    </button>
                  </div>
                </form>
              )}
            </>
          ) : setup !== null ? (
            <form
              onSubmit={(event) => {
                event.preventDefault()
                confirm.mutate()
              }}
            >
              <p className="mt-0">
                Add this to your authenticator app, then enter the code it shows. Nothing
                changes on your account until you do.
              </p>

              <pre className="secret">{setup.secret}</pre>

              <p className="small faint">
                Most apps accept the key above typed by hand. If yours scans a code, use{' '}
                <span className="mono wrap">{setup.uri}</span>
              </p>

              <div className="field">
                <label className="field__label" htmlFor="confirm-code">
                  Code from the app
                </label>
                <input
                  id="confirm-code"
                  type="text"
                  autoComplete="one-time-code"
                  autoFocus
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                />
              </div>

              <div className="row">
                <button
                  type="button"
                  className="btn btn--ghost btn--sm"
                  onClick={() => {
                    setSetup(null)
                    setCode('')
                  }}
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="btn btn--primary btn--sm"
                  disabled={confirm.isPending || code.trim() === ''}
                >
                  {confirm.isPending ? 'Checking…' : 'Turn on'}
                </button>
              </div>
            </form>
          ) : (
            <form
              onSubmit={(event) => {
                event.preventDefault()
                begin.mutate()
              }}
            >
              <p className="mt-0">
                A code from your phone as well as your password. Without it, anybody who
                learns your password has your account.
              </p>

              <div className="field">
                <label className="field__label" htmlFor="on-password">
                  Password
                </label>
                <input
                  id="on-password"
                  type="password"
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                />
              </div>

              <button
                type="submit"
                className="btn btn--primary btn--sm"
                disabled={begin.isPending || password === ''}
              >
                {begin.isPending ? 'Starting…' : 'Set up two-factor authentication'}
              </button>
            </form>
          )}
        </QueryState>
      </div>
    </section>
  )
}
