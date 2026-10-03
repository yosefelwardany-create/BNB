import { expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { RevenuePage } from './RevenuePage'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'
import { session } from '@/test/fixtures'

it('shows one explanation when all revenue panels reject the same incomplete selection', async () => {
  const error = { status: 422, body: { message: 'Revenue amounts need review.' } }
  stubApi({
    'GET auth/me': { body: session() },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    ...Object.fromEntries(['summary', 'daily', 'by-source', 'by-property', 'pace'].map(path => [`GET revenue/${path}`, error])),
  })
  renderWithProviders(<RevenuePage />)
  expect(await screen.findByText('Revenue amounts need review.')).toBeInTheDocument()
  expect(screen.getAllByText('Revenue amounts need review.')).toHaveLength(1)
  expect(screen.getByRole('link', { name: 'Review reservation amounts' })).toHaveAttribute('href', '/app/reservations')
  expect(screen.queryByText('US$0.00')).not.toBeInTheDocument()
})
