import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { PlatformUser } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { formatNumber } from '@/lib/format'
import { useAuth } from '@/lib/auth'

interface Page {
  data: PlatformUser[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

/**
 * Everybody with an account.
 *
 * The only write here is platform administration itself, which makes it the most
 * sensitive control in the product: it is the only way to create somebody who can
 * reach this console. Nothing else about a person is editable — their name, email
 * and password belong to them, and an operator changing a customer's email is
 * indistinguishable from an account takeover.
 */
export function PlatformPeoplePage() {
  const queryClient = useQueryClient()
  const { session } = useAuth()

  const [search, setSearch] = useState('')
  const [adminsOnly, setAdminsOnly] = useState(false)
  const [page, setPage] = useState(1)
  const [granting, setGranting] = useState<PlatformUser | null>(null)
  const [reason, setReason] = useState('')

  const list = useQuery({
    queryKey: ['platform-people', { search, adminsOnly, page }],
    queryFn: () =>
      api.get<Page>('platform/users', {
        search: search || undefined,
        platform_admins_only: adminsOnly ? true : undefined,
        page,
        per_page: 25,
      }),
    placeholderData: keepPreviousData,
  })

  const grant = useMutation({
    mutationFn: ({ user, admin, why }: { user: PlatformUser; admin: boolean; why: string }) =>
      api.patch<{ message: string }>(`platform/users/${user.id}`, {
        is_platform_admin: admin,
        reason: why,
      }),
    onSuccess: () => {
      setGranting(null)
      setReason('')
      void queryClient.invalidateQueries({ queryKey: ['platform-people'] })
    },
  })

  const people = list.data?.data ?? []
  const meta = list.data?.meta

  return (
    <>
      <div className="page-header">
        <div>
          <h1>People</h1>
          <div className="page-header__subtitle">
            {meta ? `${formatNumber(meta.total)} account(s)` : 'Loading…'}
          </div>
        </div>

        <button
          type="button"
          className={adminsOnly ? 'btn btn--sm' : 'btn btn--ghost btn--sm'}
          onClick={() => {
            setAdminsOnly(!adminsOnly)
            setPage(1)
          }}
        >
          Platform administrators only
        </button>
      </div>

      <div className="filters">
        <div className="field">
          <label className="field__label" htmlFor="people-search">
            Search
          </label>
          <input
            id="people-search"
            type="search"
            value={search}
            placeholder="Name or email"
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
          />
        </div>
      </div>

      {grant.error !== null && (
        <div className="notice notice--error" role="alert">
          {grant.error instanceof ApiError
            ? grant.error.message
            : 'That change could not be made.'}
        </div>
      )}

      {granting !== null && (
        <section className="card mb-3">
          <header className="card__header">
            <h2>
              {granting.is_platform_admin ? 'Revoke' : 'Grant'} platform administration
            </h2>
          </header>

          <form
            className="card__body"
            onSubmit={(event) => {
              event.preventDefault()

              if (reason.trim() !== '') {
                grant.mutate({
                  user: granting,
                  admin: !granting.is_platform_admin,
                  why: reason,
                })
              }
            }}
          >
            <p className="small mt-0">
              {granting.is_platform_admin ? (
                <>
                  <strong>{granting.email}</strong> will lose access to this console
                  immediately. Their access to their own organizations is unchanged.
                </>
              ) : (
                <>
                  <strong>{granting.email}</strong> will be able to see and govern every
                  organization on the platform, and to open read-only support sessions into
                  any of them.
                </>
              )}
            </p>

            <div className="field">
              <label className="field__label" htmlFor="grant-reason">
                Why?
              </label>
              <input
                id="grant-reason"
                type="text"
                value={reason}
                autoFocus
                onChange={(event) => setReason(event.target.value)}
              />
              <span className="field__hint">Recorded in the platform audit trail.</span>
            </div>

            <div className="row">
              <button
                type="button"
                className="btn btn--ghost btn--sm"
                onClick={() => {
                  setGranting(null)
                  setReason('')
                }}
              >
                Cancel
              </button>
              <button
                type="submit"
                className="btn btn--danger btn--sm"
                disabled={grant.isPending || reason.trim() === ''}
              >
                {grant.isPending ? 'Working…' : 'Confirm'}
              </button>
            </div>
          </form>
        </section>
      )}

      <QueryState
        isLoading={list.isLoading}
        error={list.error}
        isEmpty={people.length === 0}
        emptyTitle="Nobody matches"
      >
        <section className="card">
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Person</th>
                  <th className="numeric">Organizations</th>
                  <th>Security</th>
                  <th>Last signed in</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {people.map((person) => (
                  <tr key={person.id}>
                    <td>
                      <div className="strong">{person.name}</div>
                      <div className="small faint">{person.email}</div>
                      {person.is_platform_admin && (
                        <div className="mt-1">
                          <Chip label="Platform administrator" colour="rose" />
                        </div>
                      )}
                    </td>

                    <td className="numeric">{person.organizations_count}</td>

                    <td>
                      <div className="row wrap">
                        {person.mfa_enabled ? (
                          <Chip label="Two-factor on" colour="emerald" />
                        ) : (
                          <Chip label="No two-factor" colour="amber" />
                        )}
                        {!person.email_verified && (
                          <Chip label="Email unverified" colour="zinc" />
                        )}
                      </div>
                    </td>

                    <td className="small faint nowrap">
                      {person.last_login_at === null
                        ? 'Never'
                        : new Date(person.last_login_at).toLocaleDateString()}
                    </td>

                    <td>
                      {/* Hidden for yourself: the API refuses it anyway, and
                          offering a button that always fails is worse than not
                          offering it. */}
                      {person.id !== session?.user.id && (
                        <button
                          type="button"
                          className={
                            person.is_platform_admin ? 'btn btn--danger btn--sm' : 'btn btn--ghost btn--sm'
                          }
                          onClick={() => setGranting(person)}
                        >
                          {person.is_platform_admin ? 'Revoke' : 'Make administrator'}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {meta !== undefined && meta.last_page > 1 && (
            <div className="card__footer row row--between">
              <span className="small faint">
                Page {meta.current_page} of {meta.last_page}
              </span>
              <div className="row">
                <button
                  type="button"
                  className="btn btn--ghost btn--sm"
                  disabled={meta.current_page <= 1}
                  onClick={() => setPage((p) => p - 1)}
                >
                  Previous
                </button>
                <button
                  type="button"
                  className="btn btn--ghost btn--sm"
                  disabled={meta.current_page >= meta.last_page}
                  onClick={() => setPage((p) => p + 1)}
                >
                  Next
                </button>
              </div>
            </div>
          )}
        </section>
      </QueryState>
    </>
  )
}
