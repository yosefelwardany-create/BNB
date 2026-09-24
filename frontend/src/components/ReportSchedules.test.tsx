import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ReportSchedules } from '@/components/ReportSchedules'
import type { ReportDefinition, SavedReport } from '@/api/types'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { page, stubApi } from '@/test/server'

/**
 * Saved reports and where they go.
 *
 * Two things this screen must not hide. A schedule with nowhere to go runs,
 * produces nothing anybody sees, and looks identical to one that is broken.
 * And `last_error` is the question somebody actually has about a scheduled
 * report — why it stopped arriving — so a silence they mistake for "nothing to
 * say this month" is the failure worth preventing.
 */
const OCCUPANCY: ReportDefinition = {
  key: 'occupancy',
  name: 'Occupancy and RevPAR',
  description: 'Nights sold against nights owned.',
  category: 'Performance',
  columns: [],
}

function saved(overrides: Partial<SavedReport> = {}): SavedReport {
  return {
    id: 'sav_1',
    name: 'Monthly occupancy',
    description: null,
    report_key: 'occupancy',
    parameters: { period: 'last_month' },
    is_shared: false,
    is_active: true,
    schedule_cron: '0 8 1 * *',
    schedule_timezone: 'Europe/Lisbon',
    is_scheduled: true,
    recipients: [],
    has_recipients: true,
    destinations: [{ type: 'email', recipients: ['owner@example.test'] }],
    format: 'csv',
    last_run_at: '2025-06-01T08:00:00+00:00',
    next_run_at: '2025-07-01T08:00:00+00:00',
    run_count: 6,
    last_error: null,
    created_by_id: 'usr_1',
    created_at: '2025-01-01T00:00:00+00:00',
    ...overrides,
  }
}

function renderSchedules(reports: SavedReport[]) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions: ['*'] }) },
    'GET reports/saved': { body: page(reports) },
    'GET reports/destinations': {
      body: {
        data: [
          { key: 'email', name: 'Email', description: 'Sends the report as an attachment.' },
          { key: 'webhook', name: 'Webhook', description: 'Posts the report to an HTTPS URL.' },
          { key: 'storage', name: 'Stored file', description: 'Keeps each run as a file.' },
        ],
      },
    },
    'POST reports/saved': { status: 201, body: { data: saved() } },
  })

  renderWithProviders(<ReportSchedules report={OCCUPANCY} />)

  return server
}

async function rowFor(name: string) {
  const cell = await screen.findByText(name)

  return cell.closest('tr') as HTMLElement
}

describe('what a schedule shows', () => {
  it('names every destination a report goes to', async () => {
    renderSchedules([
      saved({
        destinations: [
          { type: 'email', recipients: ['owner@example.test'] },
          { type: 'webhook', url: 'https://warehouse.example.test/ingest' },
          { type: 'storage', retain_days: 90 },
        ],
      }),
    ])

    const row = await rowFor('Monthly occupancy')

    expect(within(row).getByText(/Email/)).toBeInTheDocument()
    expect(within(row).getByText(/warehouse\.example\.test/)).toBeInTheDocument()
    expect(within(row).getByText(/Stored file · 90d/)).toBeInTheDocument()
  })

  it('calls out a schedule that delivers nowhere', async () => {
    renderSchedules([saved({ destinations: [], has_recipients: false })])

    const row = await rowFor('Monthly occupancy')

    // It runs, produces nothing anybody sees, and looks exactly like one that
    // is broken.
    expect(within(row).getByText('Nowhere')).toBeInTheDocument()
  })

  it('shows why a report stopped arriving', async () => {
    renderSchedules([
      saved({ last_error: 'webhook (https://warehouse.example.test): The receiver answered 500.' }),
    ])

    const row = await rowFor('Monthly occupancy')

    expect(within(row).getByText(/The receiver answered 500/)).toBeInTheDocument()
  })

  it('distinguishes a saved report from a scheduled one', async () => {
    renderSchedules([saved({ is_scheduled: false, schedule_cron: null, next_run_at: null })])

    const row = await rowFor('Monthly occupancy')

    expect(within(row).getByText('Run by hand')).toBeInTheDocument()
  })
})

describe('setting one up', () => {
  it('offers the destinations the server has, not a hard-coded list', async () => {
    renderSchedules([])

    await userEvent.click(await screen.findByRole('button', { name: /Save/ }))

    const select = await screen.findByLabelText('Destination')

    // A destination added server-side appears here without a frontend change,
    // and this form can never offer one that does not exist.
    expect(within(select).getByRole('option', { name: 'Webhook' })).toBeInTheDocument()
    expect(within(select).getByRole('option', { name: 'Stored file' })).toBeInTheDocument()
  })

  it('asks for what a webhook needs, and keeps the secret out of sight', async () => {
    renderSchedules([])

    await userEvent.click(await screen.findByRole('button', { name: /Save/ }))
    await userEvent.selectOptions(await screen.findByLabelText('Destination'), 'webhook')

    expect(screen.getByLabelText('URL')).toBeInTheDocument()
    expect(screen.getByLabelText('Signing secret')).toHaveAttribute('type', 'password')
  })

  it('sends the destinations it was given', async () => {
    const server = renderSchedules([])

    await userEvent.click(await screen.findByRole('button', { name: /Save/ }))
    await userEvent.type(
      await screen.findByLabelText('Email addresses'),
      'accounts@example.test',
    )
    await userEvent.type(screen.getByLabelText('Schedule'), '0 8 1 * *')
    await userEvent.click(screen.getByRole('button', { name: 'Save report' }))

    const [call] = server.callsTo('POST', 'reports/saved')
    const body = call?.body as { destinations: { type: string; recipients?: string[] }[] }

    expect(body.destinations).toEqual([
      { type: 'email', recipients: ['accounts@example.test'] },
    ])
  })

  it('shows the server’s refusal rather than a generic one', async () => {
    const server = renderSchedules([])

    server.on('POST reports/saved', {
      status: 422,
      body: {
        message: 'The given data was invalid.',
        errors: {
          'destinations.0': ['The url must use https.'],
        },
      },
    })

    await userEvent.click(await screen.findByRole('button', { name: /Save/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Save report' }))

    expect(await screen.findByText('The url must use https.')).toBeInTheDocument()
  })
})
