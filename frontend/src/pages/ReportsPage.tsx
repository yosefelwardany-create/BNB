import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api, ApiError, currentAuth } from '@/api/client'
import type { Money, ReportColumn, ReportDefinition, ReportRun } from '@/api/types'
import { QueryState } from '@/components/QueryState'
import { formatDate, formatMoney, formatNumber, formatPercent } from '@/lib/format'
import { useAuth } from '@/lib/auth'

const PERIODS = [
  { value: 'last_30_days', label: 'Last 30 days' },
  { value: 'this_month', label: 'This month' },
  { value: 'last_month', label: 'Last month' },
  { value: 'last_7_days', label: 'Last 7 days' },
  { value: 'this_year', label: 'This year' },
  { value: 'last_year', label: 'Last year' },
  { value: 'next_30_days', label: 'Next 30 days' },
  { value: 'next_90_days', label: 'Next 90 days' },
]

/**
 * Reports.
 *
 * The catalogue is whatever the server says this user may run — it is filtered
 * there, not here, so a report nobody is allowed to open never appears and
 * then refuses.
 *
 * Column types come from the report itself, so a money column is formatted as
 * money and a percentage as a percentage without this screen keeping its own
 * list of which is which and drifting from it.
 */
export function ReportsPage() {
  const { can } = useAuth()

  const [selected, setSelected] = useState<string | null>(null)
  const [period, setPeriod] = useState('last_30_days')
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState<string | null>(null)

  const catalogue = useQuery({
    queryKey: ['reports'],
    queryFn: () => api.get<{ data: ReportDefinition[] }>('reports'),
  })

  const reports = catalogue.data?.data ?? []
  const activeKey = selected ?? reports[0]?.key ?? null

  const run = useQuery({
    queryKey: ['report-run', activeKey, period],
    queryFn: () => api.get<ReportRun>(`reports/${activeKey}`, { period }),
    enabled: activeKey !== null,
    placeholderData: keepPreviousData,
  })

  const result = run.data
  const columns = result?.report.columns ?? []

  // Grouped so a long catalogue reads as a menu rather than a wall.
  const grouped = new Map<string, ReportDefinition[]>()
  for (const report of reports) {
    grouped.set(report.category, [...(grouped.get(report.category) ?? []), report])
  }

  /**
   * The export is a streamed file rather than JSON, so it is fetched directly
   * with the same credentials the client uses and handed to the browser as a
   * blob. Opening the URL in a new tab would drop the Authorization header and
   * the tenant, and arrive as a 401.
   */
  async function download(): Promise<void> {
    if (activeKey === null) return

    setExporting(true)
    setExportError(null)

    try {
      const auth = currentAuth()
      const query = new URLSearchParams({ period })

      const response = await fetch(`/api/v1/reports/${activeKey}/export?${query.toString()}`, {
        headers: {
          Accept: 'text/csv',
          ...(auth?.token ? { Authorization: `Bearer ${auth.token}` } : {}),
          ...(auth?.organizationId ? { 'X-Organization': auth.organizationId } : {}),
        },
      })

      if (!response.ok) {
        throw new ApiError(response.status, 'That report could not be exported.')
      }

      const blob = await response.blob()
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')

      link.href = url
      link.download = `${activeKey}-${period}.csv`
      link.click()

      URL.revokeObjectURL(url)
    } catch (error) {
      setExportError(
        error instanceof ApiError && error.isForbidden
          ? 'You do not have permission to export reports.'
          : 'That report could not be exported.',
      )
    } finally {
      setExporting(false)
    }
  }

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Reports</h1>
          <div className="page-header__subtitle">
            {result?.report.description ?? 'Choose a report.'}
          </div>
        </div>

        <div className="row">
          <select
            value={period}
            onChange={(event) => setPeriod(event.target.value)}
            style={{ width: 'auto' }}
            aria-label="Period"
          >
            {PERIODS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>

          {can('reports.export') && (
            <button
              type="button"
              className="btn btn--sm"
              onClick={() => void download()}
              disabled={exporting || activeKey === null}
            >
              {exporting ? 'Exporting…' : 'Export CSV'}
            </button>
          )}
        </div>
      </div>

      {exportError !== null && (
        <div className="notice notice--error" role="alert">
          {exportError}
        </div>
      )}

      <div className="reports">
        <aside className="card reports__catalogue">
          <QueryState
            isLoading={catalogue.isLoading}
            error={catalogue.error}
            isEmpty={reports.length === 0}
            emptyTitle="No reports available"
            emptyBody="Your role does not include any reports."
          >
            {[...grouped.entries()].map(([category, entries]) => (
              <div key={category}>
                <div className="sidebar__section">{category}</div>
                {entries.map((report) => (
                  <button
                    key={report.key}
                    type="button"
                    className={
                      report.key === activeKey ? 'nav-link nav-link--active' : 'nav-link'
                    }
                    onClick={() => setSelected(report.key)}
                  >
                    {report.name}
                  </button>
                ))}
              </div>
            ))}
          </QueryState>
        </aside>

        <section className="card reports__result">
          {activeKey === null ? (
            <div className="empty">
              <div className="empty__title">No report selected</div>
            </div>
          ) : (
            <QueryState isLoading={run.isLoading} error={run.error}>
              <header className="card__header">
                <h2>{result?.report.name}</h2>
                <span className="small faint">
                  {formatNumber(result?.rows.length ?? 0)} row(s)
                </span>
              </header>

              {/* Caveats are shown above the numbers, never tucked into a
                  footnote. A report read without them is a report read
                  wrongly, and by the time somebody scrolls to the bottom the
                  decision is already made. */}
              {(result?.notes.length ?? 0) > 0 && (
                <div className="card__body">
                  {result?.notes.map((note) => (
                    <div key={note} className="notice notice--info">
                      {note}
                    </div>
                  ))}
                </div>
              )}

              {result !== undefined && result.rows.length === 0 ? (
                <div className="empty">
                  <div className="empty__title">Nothing to report</div>
                  <p>No rows fall inside this period.</p>
                </div>
              ) : (
                <div className="table-wrap">
                  <table className="data">
                    <thead>
                      <tr>
                        {columns.map((column) => (
                          <th
                            key={column.key}
                            className={isNumeric(column) ? 'numeric' : undefined}
                          >
                            {column.label}
                          </th>
                        ))}
                      </tr>
                    </thead>

                    <tbody>
                      {(result?.rows ?? []).map((row, index) => (
                        <tr key={index}>
                          {columns.map((column) => (
                            <td
                              key={column.key}
                              className={isNumeric(column) ? 'numeric' : undefined}
                            >
                              {renderCell(row[column.key], column)}
                            </td>
                          ))}
                        </tr>
                      ))}
                    </tbody>

                    {/* Totals belong to the report, not to a sum computed here
                        over the page that happens to be loaded. */}
                    {result !== undefined && Object.keys(result.totals).length > 0 && (
                      <tfoot>
                        <tr>
                          {columns.map((column, index) => (
                            <td
                              key={column.key}
                              className={isNumeric(column) ? 'numeric strong' : 'strong'}
                            >
                              {column.key in result.totals
                                ? renderCell(result.totals[column.key], column)
                                : index === 0
                                  ? 'Total'
                                  : ''}
                            </td>
                          ))}
                        </tr>
                      </tfoot>
                    )}
                  </table>
                </div>
              )}
            </QueryState>
          )}
        </section>
      </div>
    </>
  )
}

function isNumeric(column: ReportColumn): boolean {
  return ['integer', 'money', 'percentage'].includes(column.type)
}

/**
 * One cell, formatted the way its column says it should be.
 *
 * A value the column type does not fit is printed as it arrived rather than
 * coerced: showing a raw string is honest, and showing "NaN" or "£0.00" for a
 * value that was never a number is not.
 */
function renderCell(value: unknown, column: ReportColumn): string {
  if (value === null || value === undefined || value === '') return '—'

  switch (column.type) {
    case 'money':
      return isMoney(value) ? formatMoney(value) : String(value)

    case 'percentage':
      return typeof value === 'number' ? formatPercent(value) : String(value)

    case 'integer':
      return typeof value === 'number' ? formatNumber(value) : String(value)

    case 'date':
      return typeof value === 'string' ? formatDate(value) : String(value)

    default:
      return typeof value === 'object' ? JSON.stringify(value) : String(value)
  }
}

function isMoney(value: unknown): value is Money {
  return (
    typeof value === 'object' &&
    value !== null &&
    'amount' in value &&
    'currency' in value &&
    'formatted' in value
  )
}
