import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { OwnerPayoutRow, OwnerStatementRow } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'

/**
 * What the owner is paid, and when it arrived.
 *
 * The one screen in the portal carrying net figures. `net_due` is after the
 * management fee, which is why the overview sends people here rather than
 * estimating: this is the document they are paid against, and a second
 * calculation elsewhere would eventually disagree with it.
 *
 * Only statements that were actually sent appear — the server filters drafts
 * out. A draft is a working document that can still change, and an owner who
 * saw one would reasonably treat it as a promise.
 */
export function OwnerMoneyPage() {
  const statements = useQuery({
    queryKey: ['owner-statements'],
    queryFn: () => api.get<{ data: OwnerStatementRow[] }>('portal/owner/statements'),
  })

  const payouts = useQuery({
    queryKey: ['owner-payouts'],
    queryFn: () => api.get<{ data: OwnerPayoutRow[] }>('portal/owner/payouts'),
  })

  const statementRows = statements.data?.data ?? []
  const payoutRows = payouts.data?.data ?? []

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Money</h1>
          <div className="page-header__subtitle">
            Your statements and what has been paid out
          </div>
        </div>
      </div>

      <section className="card mb-3">
        <header className="card__header">
          <h2>Statements</h2>
        </header>

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
                </tr>
              </thead>
              <tbody>
                {statementRows.map((statement) => (
                  <tr key={statement.id}>
                    <td>
                      {statement.period_start} – {statement.period_end}
                    </td>
                    <td className="mono small">{statement.reference ?? '—'}</td>
                    <td>
                      <Chip
                        label={statement.status}
                        colour={statement.status === 'paid' ? 'emerald' : 'amber'}
                      />
                    </td>
                    {/* After our fee and the channel's commission, both
                        itemised on the statement itself. */}
                    <td className="numeric">{statement.net_due.formatted}</td>
                    <td className="numeric">{statement.payout_amount.formatted}</td>
                    <td className="numeric">{statement.closing_balance.formatted}</td>
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
                    <td className="mono small">{payout.destination ?? '—'}</td>
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
