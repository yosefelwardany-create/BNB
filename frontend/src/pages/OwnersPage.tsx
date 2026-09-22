import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { ManagementAgreement, Owner, OwnerPayout, Paginated } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { formatDate, formatMoney, formatPercent } from '@/lib/format'
import { useAuth } from '@/lib/auth'

/**
 * Owners.
 *
 * The list is the directory; selecting one shows what is actually contentious
 * — which properties they hold, what share of each and on what dates, and the
 * terms their commission is calculated under. Those three things are what
 * every statement argument turns on, so they are on one screen rather than
 * three.
 */
export function OwnersPage() {
  const { can, canAny } = useAuth()
  const queryClient = useQueryClient()

  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [selectedId, setSelectedId] = useState<string | null>(null)

  const list = useQuery({
    queryKey: ['owners', { search, page }],
    queryFn: () =>
      api.get<Paginated<Owner>>('owners', {
        search: search || undefined,
        page,
        per_page: 25,
      }),
    placeholderData: keepPreviousData,
  })

  const detail = useQuery({
    queryKey: ['owner', selectedId],
    queryFn: () => api.get<{ data: Owner }>(`owners/${selectedId}`),
    enabled: selectedId !== null,
  })

  const agreements = useQuery({
    queryKey: ['owner-agreements', selectedId],
    queryFn: () =>
      api.get<{ data: ManagementAgreement[] }>(`owners/${selectedId}/agreements`),
    enabled: selectedId !== null,
  })

  const payouts = useQuery({
    queryKey: ['owner-payouts', selectedId],
    queryFn: () =>
      api.get<Paginated<OwnerPayout>>('owner-payouts', {
        owner_id: selectedId ?? undefined,
        per_page: 10,
      }),
    enabled: selectedId !== null && canAny(['owner_payouts.manage', 'financials.view']),
  })

  const portal = useMutation({
    mutationFn: ({ owner, enable }: { owner: Owner; enable: boolean }) =>
      enable
        ? api.post<{ message: string }>(`owners/${owner.id}/portal`, { send_invitation: true })
        : api.delete<{ message: string }>(`owners/${owner.id}/portal`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['owners'] })
      void queryClient.invalidateQueries({ queryKey: ['owner', selectedId] })
    },
  })

  const owners = list.data?.data ?? []
  const meta = list.data?.meta
  const owner = detail.data?.data

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Owners</h1>
          <div className="page-header__subtitle">
            {meta ? `${meta.total} owner(s)` : 'Loading…'}
          </div>
        </div>
      </div>

      <div className="filters">
        <div className="field">
          <label className="field__label" htmlFor="owner-search">
            Search
          </label>
          <input
            id="owner-search"
            type="search"
            value={search}
            placeholder="Name, company or email"
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
          />
        </div>
      </div>

      {portal.error !== null && (
        <div className="notice notice--error" role="alert">
          {portal.error instanceof ApiError
            ? portal.error.message
            : 'Portal access could not be changed.'}
        </div>
      )}

      {portal.data !== undefined && (
        <div className="notice notice--info">{portal.data.message}</div>
      )}

      <div className="split">
        <section className="card">
          <QueryState
            isLoading={list.isLoading}
            error={list.error}
            isEmpty={owners.length === 0}
            emptyTitle="No owners"
            emptyBody="Nobody matches this search."
          >
            <div className="table-wrap">
              <table className="data">
                <thead>
                  <tr>
                    <th>Owner</th>
                    <th>Contact</th>
                    <th className="numeric">Properties</th>
                    <th>Portal</th>
                  </tr>
                </thead>
                <tbody>
                  {owners.map((row) => (
                    <tr
                      key={row.id}
                      onClick={() => setSelectedId(row.id)}
                      className={row.id === selectedId ? 'is-selected' : undefined}
                      style={{ cursor: 'pointer' }}
                    >
                      <td>
                        <div className="strong">{row.display_name}</div>
                        <div className="small faint">{row.type}</div>
                      </td>

                      <td className="small">
                        <div className="truncate">{row.email ?? '—'}</div>
                        <div className="faint">{row.phone ?? ''}</div>
                      </td>

                      <td className="numeric">{row.properties_count ?? 0}</td>

                      <td>
                        {row.portal_enabled ? (
                          <Chip label="Enabled" colour="emerald" />
                        ) : (
                          <Chip label="Off" colour="zinc" />
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
                    onClick={() => setPage((current) => current - 1)}
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    className="btn btn--ghost btn--sm"
                    disabled={meta.current_page >= meta.last_page}
                    onClick={() => setPage((current) => current + 1)}
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
          </QueryState>
        </section>

        <section className="card">
          {selectedId === null ? (
            <div className="empty">
              <div className="empty__title">No owner selected</div>
              <p>Choose an owner to see their properties and terms.</p>
            </div>
          ) : (
            <QueryState isLoading={detail.isLoading} error={detail.error}>
              <header className="card__header">
                <div>
                  <h2>{owner?.display_name}</h2>
                  <div className="small faint">{owner?.email ?? 'No email on file'}</div>
                </div>

                {can('owners.portal') && owner !== undefined && (
                  <button
                    type="button"
                    className={owner.portal_enabled ? 'btn btn--danger btn--sm' : 'btn btn--sm'}
                    onClick={() =>
                      portal.mutate({ owner, enable: !owner.portal_enabled })
                    }
                    disabled={portal.isPending}
                  >
                    {owner.portal_enabled ? 'Withdraw portal access' : 'Grant portal access'}
                  </button>
                )}
              </header>

              <div className="card__body">
                <h3 className="mt-0">Properties held</h3>

                {(owner?.ownerships?.length ?? 0) === 0 ? (
                  <p className="small faint">This owner holds no properties.</p>
                ) : (
                  <div className="table-wrap">
                    <table className="data">
                      <thead>
                        <tr>
                          <th>Property</th>
                          <th className="numeric">Share</th>
                          <th>Held</th>
                        </tr>
                      </thead>
                      <tbody>
                        {(owner?.ownerships ?? []).map((ownership) => (
                          <tr key={ownership.id}>
                            <td>
                              <span className="strong">
                                {ownership.property?.name ?? ownership.property_id}
                              </span>
                              {ownership.is_primary && (
                                <span className="ml-2">
                                  <Chip label="Primary" colour="indigo" />
                                </span>
                              )}
                            </td>

                            <td className="numeric">
                              {formatPercent(ownership.ownership_percentage)}
                            </td>

                            <td className="small">
                              {/* Dated, because a property that changed hands
                                  in June is not owned by one person for the
                                  year — and every statement line is attributed
                                  by the share in force on its own night. */}
                              {ownership.starts_on === null
                                ? 'From the beginning'
                                : `From ${formatDate(ownership.starts_on)}`}
                              {ownership.ends_on !== null &&
                                ` until ${formatDate(ownership.ends_on)}`}
                              {!ownership.is_current && (
                                <span className="ml-2">
                                  <Chip label="Not current" colour="zinc" />
                                </span>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}

                <h3>Management terms</h3>

                <QueryState isLoading={agreements.isLoading} error={agreements.error}>
                  {(agreements.data?.data.length ?? 0) === 0 ? (
                    <p className="small faint">
                      No agreement on file. Statements for this owner cannot calculate a
                      commission until one exists.
                    </p>
                  ) : (
                    (agreements.data?.data ?? []).map((agreement) => (
                      <div key={agreement.id} className="panel">
                        <div className="row row--between">
                          <span className="strong">{agreement.name}</span>
                          <Chip
                            label={agreement.is_in_force ? 'In force' : agreement.status}
                            colour={agreement.is_in_force ? 'emerald' : 'zinc'}
                          />
                        </div>

                        <div className="small faint">
                          {agreement.property_id === null
                            ? 'Covers every property'
                            : 'Covers one property'}
                          {' · '}
                          {agreement.commission_model}
                          {agreement.commission_rate !== null &&
                            ` · ${formatPercent(agreement.commission_rate)}`}
                        </div>

                        {/* "20% of what, exactly" is the question every
                            statement dispute turns on, so the bases are
                            listed rather than left to the model to imply. */}
                        <div className="row mt-2">
                          {agreement.commission_on_accommodation && (
                            <Chip label="On accommodation" colour="slate" />
                          )}
                          {agreement.commission_on_fees && (
                            <Chip label="On fees" colour="slate" />
                          )}
                          {agreement.commission_on_taxes && (
                            <Chip label="On taxes" colour="slate" />
                          )}
                          {agreement.owner_pays_cleaning && (
                            <Chip label="Owner pays cleaning" colour="sky" />
                          )}
                          {agreement.owner_pays_maintenance && (
                            <Chip label="Owner pays maintenance" colour="sky" />
                          )}
                        </div>

                        <div className="small faint mt-2">
                          {agreement.starts_on === null
                            ? 'Open-ended'
                            : `From ${formatDate(agreement.starts_on)}`}
                          {agreement.ends_on !== null &&
                            ` until ${formatDate(agreement.ends_on)}`}
                        </div>
                      </div>
                    ))
                  )}
                </QueryState>

                {canAny(['owner_payouts.manage', 'financials.view']) && (
                  <>
                    <h3>Recent payouts</h3>

                    <QueryState isLoading={payouts.isLoading} error={payouts.error}>
                      {(payouts.data?.data.length ?? 0) === 0 ? (
                        <p className="small faint">Nothing has been paid out yet.</p>
                      ) : (
                        <div className="table-wrap">
                          <table className="data">
                            <thead>
                              <tr>
                                <th>Reference</th>
                                <th>Status</th>
                                <th>Destination</th>
                                <th className="numeric">Amount</th>
                              </tr>
                            </thead>
                            <tbody>
                              {(payouts.data?.data ?? []).map((payout) => (
                                <tr key={payout.id}>
                                  <td className="mono">{payout.reference}</td>
                                  <td>
                                    <Chip
                                      label={payout.status}
                                      colour={
                                        payout.is_paid
                                          ? 'emerald'
                                          : payout.failure_message !== null
                                            ? 'rose'
                                            : 'slate'
                                      }
                                    />
                                    {payout.failure_message !== null && (
                                      <div className="small danger mt-1">
                                        {payout.failure_message}
                                      </div>
                                    )}
                                  </td>
                                  <td className="small faint">
                                    {describeDestination(payout)}
                                  </td>
                                  <td className="numeric">{formatMoney(payout.amount)}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}
                    </QueryState>
                  </>
                )}
              </div>
            </QueryState>
          )}
        </section>
      </div>
    </>
  )
}

/**
 * Where a payout went, from the masked snapshot taken when it was made.
 *
 * The snapshot rather than the owner's current details, because a payout that
 * went to an account they have since changed went to the old one, and saying
 * otherwise makes the record useless for tracing a missing payment.
 */
function describeDestination(payout: OwnerPayout): string {
  if (payout.destination === null) return payout.method ?? '—'

  const parts = Object.values(payout.destination).filter((value) => value !== '')

  return parts.length > 0 ? parts.join(' · ') : (payout.method ?? '—')
}
