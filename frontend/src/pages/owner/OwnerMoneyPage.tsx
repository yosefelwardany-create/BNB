import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Download, Info, TriangleAlert } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type { ClientFinancials, OwnerPayoutRow, OwnerStatementRow } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { formatDate } from '@/lib/format'

/**
 * The client's money, in three parts.
 *
 * **Revenue after commission**, per property and per currency for a period
 * the client chooses. Computed from the nights sold and the management
 * agreement in force; a revenue figure, never a payment, and the explanation
 * says so in the server's words. Anything uncertain — a night with no rate, a
 * refund still being resolved, a stay before the agreement began — is shown
 * with a note and the row marked as not final, rather than quietly left out.
 *
 * **Statements** that were actually sent, each downloadable as the PDF they
 * were sent. Drafts never appear: a draft is a working document that can
 * still change, and a client who saw one would reasonably treat it as a
 * promise.
 *
 * **Payouts**, with where each one went, masked before it left the server.
 */
export function OwnerMoneyPage() {
  const [period, setPeriod] = useState(() => defaultPeriod())

  const financials = useQuery({
    queryKey: ['client-financials', period.from, period.to],
    queryFn: () => api.get<{ data: ClientFinancials }>('portal/owner/financials', period),
  })

  const statements = useQuery({
    queryKey: ['owner-statements'],
    queryFn: () => api.get<{ data: OwnerStatementRow[] }>('portal/owner/statements'),
  })

  const payouts = useQuery({
    queryKey: ['owner-payouts'],
    queryFn: () => api.get<{ data: OwnerPayoutRow[] }>('portal/owner/payouts'),
  })

  const pdf = useMutation({
    mutationFn: (statement: OwnerStatementRow) =>
      api.download(
        `portal/owner/statements/${statement.id}/document`,
        `statement-${statement.reference ?? statement.id}.pdf`,
        { accept: 'application/pdf', failureMessage: 'The statement could not be downloaded.' },
      ),
  })

  const money = financials.data?.data
  const statementRows = statements.data?.data ?? []
  const payoutRows = payouts.data?.data ?? []

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Money</h1>
          <div className="page-header__subtitle">
            Your revenue after the management commission, your statements, and what has been paid out
          </div>
        </div>
      </div>

      <section className="card mb-3">
        <header className="card__header">
          <h2>Revenue after commission</h2>
          <div className="row gap-2">
            <label className="field field--inline">
              <span className="field__label">From</span>
              <input
                type="date"
                value={period.from}
                max={period.to}
                onChange={(event) => setPeriod((current) => ({ ...current, from: event.target.value }))}
              />
            </label>
            <label className="field field--inline">
              <span className="field__label">To</span>
              <input
                type="date"
                value={period.to}
                min={period.from}
                onChange={(event) => setPeriod((current) => ({ ...current, to: event.target.value }))}
              />
            </label>
          </div>
        </header>

        <QueryState
          isLoading={financials.isLoading}
          error={financials.error}
          isEmpty={money !== undefined && money.properties.length === 0}
          emptyTitle="Nothing in this period"
          emptyBody="No nights were sold at your properties between these dates."
        >
          {money !== undefined && (
            <div className="card__body stack">
              {money.has_incomplete_data && (
                <div className="notice notice--warning" role="status">
                  <TriangleAlert size={14} aria-hidden /> Some figures in this period are not final. Each
                  row says why. They will be updated once the underlying records are resolved.
                </div>
              )}

              <div className="row row--wrap gap-3">
                {money.totals_by_currency.map((total) => (
                  <div key={total.currency} className="panel" style={{ minWidth: 260 }}>
                    <div className="small faint">
                      {total.currency} · {total.properties} propert{total.properties === 1 ? 'y' : 'ies'}
                    </div>
                    <dl className="definition definition--tight">
                      <dt>The properties earned</dt>
                      <dd>{total.revenue_before_commission.formatted}</dd>
                      <dt>
                        Management commission
                        {money.commission.rate !== null && ` (${money.commission.rate}%)`}
                      </dt>
                      <dd>− {total.commission.formatted}</dd>
                      <dt className="strong">Revenue after commission</dt>
                      <dd className="strong">{total.revenue_after_commission.formatted}</dd>
                    </dl>
                    {!total.is_final && (
                      <div className="small faint">
                        <TriangleAlert size={12} aria-hidden /> Not final
                      </div>
                    )}
                  </div>
                ))}
              </div>

              <div className="table-wrap">
                <table className="data">
                  <thead>
                    <tr>
                      <th>Property</th>
                      <th className="numeric">Nights sold</th>
                      <th className="numeric">It earned</th>
                      <th className="numeric">Commission</th>
                      <th className="numeric">After commission</th>
                      <th>Notes</th>
                    </tr>
                  </thead>
                  <tbody>
                    {money.properties.map((row) => (
                      <tr key={`${row.property_id}-${row.currency}`}>
                        <td>
                          <div className="strong">{row.property_name ?? '—'}</div>
                          <div className="small faint">
                            {row.currency}
                            {row.ownership_percentage !== 100 && ` · your share ${row.ownership_percentage}%`}
                          </div>
                        </td>
                        <td className="numeric">{row.nights_sold}</td>
                        <td className="numeric">{row.revenue_before_commission.formatted}</td>
                        <td className="numeric">
                          {row.commission.formatted}
                          {row.commission_rate !== null && (
                            <div className="small faint">{row.commission_rate}%</div>
                          )}
                        </td>
                        <td className="numeric strong">{row.revenue_after_commission.formatted}</td>
                        <td className="small">
                          {row.flags.length === 0 ? (
                            <span className="faint">Final</span>
                          ) : (
                            <ul className="plain">
                              {row.flags.map((flag) => (
                                <li key={flag.code}>
                                  <TriangleAlert size={12} aria-hidden /> {flag.message}
                                </li>
                              ))}
                            </ul>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <div className="stack stack--tight small faint">
                <p>
                  <Info size={13} aria-hidden /> {money.explanation.revenue_before_commission}
                </p>
                <p>{money.explanation.commission}</p>
                <p>{money.explanation.revenue_after_commission}</p>
                {money.commission.effective_from !== null && (
                  <p>Commission applies to nights from {formatDate(money.commission.effective_from)}.</p>
                )}
              </div>
            </div>
          )}
        </QueryState>
      </section>

      <section className="card mb-3">
        <header className="card__header">
          <h2>Statements</h2>
        </header>

        {pdf.error !== null && (
          <div className="notice notice--error" role="alert">
            {pdf.error instanceof ApiError ? pdf.error.message : 'The statement could not be downloaded.'}
          </div>
        )}

        <QueryState
          isLoading={statements.isLoading}
          error={statements.error}
          isEmpty={statementRows.length === 0}
          emptyTitle="No statements yet"
          emptyBody="Your first statement appears here once a period has closed and been sent to you."
        >
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Period</th>
                  <th>Reference</th>
                  <th />
                  <th className="numeric">Due to you</th>
                  <th className="numeric">Paid out</th>
                  <th className="numeric">Balance after</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {statementRows.map((statement) => (
                  <tr key={statement.id}>
                    <td>
                      {formatDate(statement.period_start)} – {formatDate(statement.period_end)}
                    </td>
                    <td className="mono small">{statement.reference ?? '—'}</td>
                    <td>
                      <Chip
                        label={statement.status}
                        colour={statement.status === 'paid' ? 'emerald' : 'amber'}
                      />
                    </td>
                    {/* After the management commission, itemised on the
                        statement itself. */}
                    <td className="numeric">{statement.net_due.formatted}</td>
                    <td className="numeric">{statement.payout_amount.formatted}</td>
                    <td className="numeric">{statement.closing_balance.formatted}</td>
                    <td>
                      <button
                        type="button"
                        className="btn btn--ghost btn--sm"
                        disabled={pdf.isPending}
                        onClick={() => pdf.mutate(statement)}
                      >
                        <Download size={14} aria-hidden /> PDF
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </QueryState>
      </section>

      <section className="card mb-3">
        <header className="card__header">
          <h2>Payouts</h2>
        </header>

        <QueryState
          isLoading={payouts.isLoading}
          error={payouts.error}
          isEmpty={payoutRows.length === 0}
          emptyTitle="No payouts yet"
          emptyBody="Once a statement is settled the payment appears here, with where it went."
        >
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th />
                  <th className="numeric">Amount</th>
                  <th>Method</th>
                  <th>To</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                {payoutRows.map((payout) => (
                  <tr key={payout.id}>
                    <td className="mono small">{payout.reference ?? '—'}</td>
                    <td>
                      <Chip
                        label={payout.status}
                        colour={payout.status === 'paid' ? 'emerald' : 'slate'}
                      />
                    </td>
                    <td className="numeric">{payout.amount.formatted}</td>
                    <td>{payout.method ?? '—'}</td>
                    {/* Masked before it ever left the server — enough to
                        recognise the account, not enough to use it. */}
                    <td className="mono small">{describeDestination(payout.destination)}</td>
                    <td>
                      {payout.paid_at !== null
                        ? new Date(payout.paid_at).toLocaleDateString()
                        : payout.scheduled_for ?? '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </QueryState>
      </section>
    </>
  )
}

/**
 * The masked destination as text.
 *
 * The server sends a snapshot object (bank name, masked account) or a string
 * depending on the method; either way it must read as words, not "[object
 * Object]".
 */
function describeDestination(destination: OwnerPayoutRow['destination']): string {
  if (destination === null) return '—'
  if (typeof destination === 'string') return destination

  const parts = Object.values(destination).filter(
    (value): value is string => typeof value === 'string' && value !== '',
  )

  return parts.length > 0 ? parts.join(' · ') : '—'
}

/** The year so far, as local dates. */
function defaultPeriod(): { from: string; to: string } {
  const today = new Date()
  const pad = (value: number) => String(value).padStart(2, '0')
  const to = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`

  return { from: `${today.getFullYear()}-01-01`, to }
}
