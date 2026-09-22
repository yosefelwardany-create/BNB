import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { Expense, OwnerStatement, Paginated, Payment } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { formatDate, formatMoney } from '@/lib/format'
import { useAuth } from '@/lib/auth'

type Tab = 'payments' | 'expenses' | 'statements'

/**
 * Money in, money out, and what each owner is owed.
 *
 * One screen with three tabs rather than three screens, because the question
 * an operator has at month end moves between them constantly: what came in,
 * what it cost, and what is therefore owed.
 */
export function FinancialsPage() {
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('payments')

  const tabs: { key: Tab; label: string; visible: boolean }[] = [
    { key: 'payments', label: 'Payments', visible: can('payments.view') || can('financials.view') },
    { key: 'expenses', label: 'Expenses', visible: can('expenses.manage') || can('financials.view') },
    {
      key: 'statements',
      label: 'Owner statements',
      visible: can('owner_statements.view') || can('financials.view'),
    },
  ]

  const visible = tabs.filter((item) => item.visible)

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Financials</h1>
        </div>

        <div className="row">
          {visible.map((item) => (
            <button
              key={item.key}
              type="button"
              className={tab === item.key ? 'btn btn--sm' : 'btn btn--ghost btn--sm'}
              onClick={() => setTab(item.key)}
            >
              {item.label}
            </button>
          ))}
        </div>
      </div>

      {tab === 'payments' && <PaymentsTab />}
      {tab === 'expenses' && <ExpensesTab />}
      {tab === 'statements' && <StatementsTab />}
    </>
  )
}

function PaymentsTab() {
  const [page, setPage] = useState(1)
  const [collectedOnly, setCollectedOnly] = useState(false)

  const query = useQuery({
    queryKey: ['payments', { page, collectedOnly }],
    queryFn: () =>
      api.get<Paginated<Payment>>('payments', {
        page,
        per_page: 25,
        collected_by_us_only: collectedOnly ? 1 : undefined,
      }),
    placeholderData: keepPreviousData,
  })

  const payments = query.data?.data ?? []

  return (
    <>
      <div className="filters">
        <label className="row small">
          <input
            type="checkbox"
            checked={collectedOnly}
            onChange={(event) => {
              setCollectedOnly(event.target.checked)
              setPage(1)
            }}
          />
          {/* Two different questions, and the difference is every OTA booking:
              what the guest paid, and what reached our bank. */}
          Only money we actually banked
        </label>
      </div>

      <div className="card">
        <QueryState
          isLoading={query.isLoading}
          error={query.error}
          isEmpty={payments.length === 0}
          emptyTitle="No payments yet"
        >
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Taken</th>
                  <th>Kind</th>
                  <th>Method</th>
                  <th>Status</th>
                  <th className="numeric">Amount</th>
                  <th className="numeric">Captured</th>
                  <th className="numeric">Refunded</th>
                  <th>Provenance</th>
                </tr>
              </thead>
              <tbody>
                {payments.map((payment) => (
                  <tr key={payment.id}>
                    <td className="mono">{payment.reference}</td>
                    <td>{formatDate(payment.created_at)}</td>
                    <td>{payment.kind_label}</td>
                    <td>
                      {payment.instrument_last4 !== null
                        ? `${payment.instrument_brand ?? 'Card'} ••${payment.instrument_last4}`
                        : (payment.method ?? '—')}
                    </td>
                    <td>
                      <Chip label={payment.status_label} colour={payment.status_colour} />
                    </td>
                    <td className="numeric">{formatMoney(payment.amount)}</td>
                    <td className="numeric">{formatMoney(payment.captured_amount)}</td>
                    <td className="numeric">{formatMoney(payment.refunded_amount)}</td>
                    <td>
                      <div className="row">
                        {/* Both flags, always. A simulated payment moved no
                            money at all; a channel-collected one moved real
                            money that never reached this bank account. */}
                        {payment.is_simulated && <Chip label="Simulated" colour="amber" />}
                        {!payment.is_collected_by_us && (
                          <Chip label="Channel collected" colour="sky" />
                        )}
                        {!payment.is_simulated && payment.is_collected_by_us && (
                          <span className="small faint">Banked</span>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </QueryState>
      </div>

      <Pager meta={query.data?.meta} onPage={setPage} />
    </>
  )
}

function ExpensesTab() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [awaiting, setAwaiting] = useState(false)

  const query = useQuery({
    queryKey: ['expenses', { page, awaiting }],
    queryFn: () =>
      api.get<Paginated<Expense>>('expenses', {
        page,
        per_page: 25,
        awaiting_statement: awaiting ? 1 : undefined,
      }),
    placeholderData: keepPreviousData,
  })

  const approve = useMutation({
    mutationFn: (expense: Expense) => api.post(`expenses/${expense.id}/approve`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['expenses'] }),
  })

  const expenses = query.data?.data ?? []

  return (
    <>
      <div className="filters">
        <label className="row small">
          <input
            type="checkbox"
            checked={awaiting}
            onChange={(event) => {
              setAwaiting(event.target.checked)
              setPage(1)
            }}
          />
          Approved and not yet on a statement
        </label>
      </div>

      {approve.error !== null && (
        <div className="notice notice--error" role="alert">
          {approve.error instanceof ApiError ? approve.error.message : 'That could not be approved.'}
        </div>
      )}

      <div className="card">
        <QueryState
          isLoading={query.isLoading}
          error={query.error}
          isEmpty={expenses.length === 0}
          emptyTitle="No expenses recorded"
        >
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Date</th>
                  <th>Property</th>
                  <th>Description</th>
                  <th>Bearer</th>
                  <th>Status</th>
                  <th className="numeric">Cost</th>
                  <th className="numeric">Charged</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {expenses.map((expense) => (
                  <tr key={expense.id}>
                    <td className="mono">{expense.reference}</td>
                    <td>{formatDate(expense.expense_date)}</td>
                    <td className="truncate" style={{ maxWidth: 160 }}>
                      {expense.property?.name ?? '—'}
                    </td>
                    <td className="truncate" style={{ maxWidth: 220 }}>
                      {expense.description}
                    </td>
                    <td>
                      {/* The most consequential field on the record: who
                          actually pays for this. */}
                      <Chip
                        label={expense.billable_to}
                        colour={expense.billable_to === 'owner' ? 'indigo' : 'slate'}
                      />
                    </td>
                    <td>
                      <Chip
                        label={expense.status}
                        colour={
                          expense.status === 'paid'
                            ? 'emerald'
                            : expense.status === 'rejected'
                              ? 'rose'
                              : expense.status === 'approved'
                                ? 'sky'
                                : 'slate'
                        }
                      />
                    </td>
                    <td className="numeric">{formatMoney(expense.amount)}</td>
                    <td className="numeric">{formatMoney(expense.chargeable_amount)}</td>
                    <td className="numeric">
                      {expense.status === 'draft' && can('financials.update') && (
                        <button
                          type="button"
                          className="btn btn--ghost btn--sm"
                          onClick={() => approve.mutate(expense)}
                          disabled={approve.isPending}
                        >
                          Approve
                        </button>
                      )}

                      {expense.owner_statement_id !== null && (
                        <span className="small faint">Billed</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </QueryState>
      </div>

      <Pager meta={query.data?.meta} onPage={setPage} />
    </>
  )
}

function StatementsTab() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)

  const query = useQuery({
    queryKey: ['owner-statements', { page }],
    queryFn: () => api.get<Paginated<OwnerStatement>>('owner-statements', { page, per_page: 25 }),
    placeholderData: keepPreviousData,
  })

  const approve = useMutation({
    mutationFn: (statement: OwnerStatement) =>
      api.post(`owner-statements/${statement.id}/approve`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['owner-statements'] }),
  })

  const send = useMutation({
    mutationFn: (statement: OwnerStatement) => api.post(`owner-statements/${statement.id}/send`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['owner-statements'] }),
  })

  const statements = query.data?.data ?? []
  const failure = approve.error ?? send.error

  return (
    <>
      {failure !== null && failure !== undefined && (
        <div className="notice notice--error" role="alert">
          {failure instanceof ApiError ? failure.message : 'That could not be done.'}
        </div>
      )}

      <div className="card">
        <QueryState
          isLoading={query.isLoading}
          error={query.error}
          isEmpty={statements.length === 0}
          emptyTitle="No statements yet"
          emptyBody="Statements are built for a period once it has closed."
        >
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Owner</th>
                  <th>Period</th>
                  <th className="numeric">Nights</th>
                  <th className="numeric">Gross</th>
                  <th className="numeric">Fee</th>
                  <th className="numeric">Costs</th>
                  <th className="numeric">Net due</th>
                  <th>Status</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {statements.map((statement) => (
                  <tr key={statement.id}>
                    <td className="mono">{statement.reference}</td>
                    <td className="truncate" style={{ maxWidth: 160 }}>
                      {statement.owner?.display_name ?? '—'}
                    </td>
                    <td>
                      {formatDate(statement.period_start)} – {formatDate(statement.period_end)}
                    </td>
                    <td className="numeric">{statement.nights_sold}</td>
                    <td className="numeric">{formatMoney(statement.gross_revenue)}</td>
                    <td className="numeric">{formatMoney(statement.management_fee)}</td>
                    <td className="numeric">{formatMoney(statement.expenses_total)}</td>
                    <td className="numeric">
                      {formatMoney(statement.net_due)}
                      {/* A period that ended owing the manager money. Carried
                          forward rather than invoiced back, so it is said
                          rather than shown as a negative payout. */}
                      {statement.is_in_deficit && (
                        <div>
                          <Chip label="In deficit" colour="amber" />
                        </div>
                      )}
                    </td>
                    <td>
                      <Chip
                        label={statement.status}
                        colour={
                          statement.status === 'paid'
                            ? 'emerald'
                            : statement.status === 'draft'
                              ? 'slate'
                              : statement.status === 'void'
                                ? 'rose'
                                : 'sky'
                        }
                      />
                    </td>
                    <td className="numeric">
                      {statement.status === 'draft' && can('owner_statements.approve') && (
                        <button
                          type="button"
                          className="btn btn--ghost btn--sm"
                          onClick={() => approve.mutate(statement)}
                          disabled={approve.isPending}
                        >
                          Approve
                        </button>
                      )}

                      {statement.status === 'approved' && can('owner_statements.send') && (
                        <button
                          type="button"
                          className="btn btn--ghost btn--sm"
                          onClick={() => send.mutate(statement)}
                          disabled={send.isPending}
                        >
                          Send
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </QueryState>
      </div>

      <Pager meta={query.data?.meta} onPage={setPage} />
    </>
  )
}

function Pager({
  meta,
  onPage,
}: {
  meta: Paginated<unknown>['meta'] | undefined
  onPage: (page: number) => void
}) {
  if (meta === undefined || meta.last_page <= 1) return null

  return (
    <div className="row row--between mt-3">
      <span className="small faint">
        {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
      </span>

      <div className="row">
        <button
          type="button"
          className="btn btn--ghost btn--sm"
          disabled={meta.current_page <= 1}
          onClick={() => onPage(meta.current_page - 1)}
        >
          Previous
        </button>
        <button
          type="button"
          className="btn btn--ghost btn--sm"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPage(meta.current_page + 1)}
        >
          Next
        </button>
      </div>
    </div>
  )
}
