import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { FinancialsPage } from '@/pages/FinancialsPage'
import { payment, session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { page, stubApi } from '@/test/server'

/**
 * Money, and where it actually is.
 *
 * This is the screen with the most potential to mislead, because every row on
 * it looks like money in the bank. Two of them usually are not:
 *
 *  - A **simulated** payment moved nothing at all. No processor saw it.
 *  - A **channel-collected** one moved real money that never reached this bank
 *    account, because the platform holds it and pays out later.
 *
 * Both are true of a row at once, both must be visible on it, and neither may
 * be inferred from the other. An operator reconciling a bank statement against
 * this screen is the person who gets hurt when they are not.
 */
function renderFinancials(payments: ReturnType<typeof payment>[]) {
  stubApi({
    'GET auth/me': { body: session({ permissions: ['*'] }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    'GET payments': { body: page(payments) },
  })

  return renderWithProviders(<FinancialsPage />)
}

async function rowFor(reference: string) {
  const cell = await screen.findByText(reference)

  return cell.closest('tr') as HTMLElement
}

describe('payment provenance', () => {
  it('marks a simulated payment as simulated', async () => {
    renderFinancials([payment({ is_simulated: true, is_collected_by_us: true })])

    const row = await rowFor('PAY-0001')

    expect(within(row).getByText('Simulated')).toBeInTheDocument()
    expect(within(row).queryByText('Banked')).not.toBeInTheDocument()
  })

  it('marks a payment the channel holds as channel-collected', async () => {
    renderFinancials([
      payment({ reference: 'PAY-OTA', is_simulated: false, is_collected_by_us: false }),
    ])

    const row = await rowFor('PAY-OTA')

    expect(within(row).getByText('Channel collected')).toBeInTheDocument()
    // Real money, so not simulated — and still not in our account, so not
    // banked either. The two facts are independent.
    expect(within(row).queryByText('Simulated')).not.toBeInTheDocument()
    expect(within(row).queryByText('Banked')).not.toBeInTheDocument()
  })

  it('shows both when both are true', async () => {
    renderFinancials([
      payment({ reference: 'PAY-BOTH', is_simulated: true, is_collected_by_us: false }),
    ])

    const row = await rowFor('PAY-BOTH')

    expect(within(row).getByText('Simulated')).toBeInTheDocument()
    expect(within(row).getByText('Channel collected')).toBeInTheDocument()
  })

  it('says banked only when the money is genuinely ours and genuinely moved', async () => {
    renderFinancials([
      payment({ reference: 'PAY-REAL', is_simulated: false, is_collected_by_us: true }),
    ])

    const row = await rowFor('PAY-REAL')

    expect(within(row).getByText('Banked')).toBeInTheDocument()
    expect(within(row).queryByText('Simulated')).not.toBeInTheDocument()
    expect(within(row).queryByText('Channel collected')).not.toBeInTheDocument()
  })

  it('formats amounts from the minor units the API sent', async () => {
    renderFinancials([payment({ reference: 'PAY-SUM' })])

    const row = await rowFor('PAY-SUM')

    expect(within(row).getAllByText('€450.00').length).toBeGreaterThan(0)
  })

  it('asks the server for the banked subset rather than filtering the page it has', async () => {
    const server = stubApi({
      'GET auth/me': { body: session({ permissions: ['*'] }) },
      'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
      'GET payments': { body: page([payment()]) },
    })

    renderWithProviders(<FinancialsPage />)

    await screen.findByText('PAY-0001')

    await userEvent.click(screen.getByRole('checkbox'))

    // Filtering client-side would quietly answer the question for one page of
    // twenty-five rather than for the account.
    await screen.findByText('PAY-0001')

    const calls = server.callsTo('GET', 'payments')

    expect(calls.at(-1)?.query.get('collected_by_us_only')).toBe('1')
  })
})

describe('financial tabs', () => {
  it('offers only the tabs the user may open', async () => {
    stubApi({
      'GET auth/me': { body: session({ permissions: ['payments.view'] }) },
      'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
      'GET payments': { body: page([]) },
    })

    renderWithProviders(<FinancialsPage />)

    expect(await screen.findByRole('button', { name: 'Payments' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Owner statements' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Expenses' })).not.toBeInTheDocument()
  })
})
