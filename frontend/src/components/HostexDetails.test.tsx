import { describe, expect, it } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { HostexReservationDetails, HostexPropertyDetails } from './HostexDetails'
import type { HostexReservation, Property } from '@/api/types'
import { formatSourceMoney } from '@/lib/format'
import { money } from '@/test/fixtures'

const source: HostexReservation = {
  reservation_code: 'HX-ORDER', stay_code: 'HX-STAY', channel_id: 'HMTEST1234', channel_type: 'airbnb', synced_at: null, limitations: [],
  financials: {
    accommodation: money(60000, 'CAD'), average_nightly_accommodation: money(20000, 'CAD'), cleaning_fee: money(5000, 'CAD'),
    reservation_rate: money(70525, 'CAD'), order_rate: money(141050, 'CAD'), commission: money(2116, 'CAD'), order_commission: null,
    tax: money(5525, 'CAD'), refund_detail: null, guest_total: null, host_payout: null, payout_status: null,
    payment: { scope: 'order', status: 'partial', received_amount: money(50000, 'CAD'), balance_amount: money(86818, 'CAD'), total_amount: money(136818, 'CAD') },
    details: [], additional_fees: [],
  },
}

describe('Hostex source values', () => {
  it('uses the actual booking channel rather than relabeling every import Airbnb', () => {
    render(<HostexReservationDetails source={{ ...source, channel_type: 'booking.com' }} />)
    expect(screen.getByText('Booking.com details')).toBeInTheDocument()
    expect(screen.queryByText('Airbnb details')).not.toBeInTheDocument()
  })
  it('explains an unrefreshed legacy booking without expanding a table of empty amounts', () => {
    render(<HostexReservationDetails source={{ ...source, financials: {
      accommodation: null, average_nightly_accommodation: null, cleaning_fee: null, reservation_rate: null,
      order_rate: null, commission: null, order_commission: null, tax: null, refund_detail: null,
      guest_total: null, host_payout: null, payout_status: null, payment: null, details: [], additional_fees: [],
    } }} />)
    expect(screen.getByText(/Airbnb booking and guest details have not refreshed yet/)).toBeInTheDocument()
    expect(screen.queryByText('Accommodation subtotal')).not.toBeInTheDocument()
  })
  it('distinguishes nightly accommodation, stay rate and order collections', () => {
    render(<HostexReservationDetails source={source} />)
    expect(screen.getByText('Airbnb details')).toBeInTheDocument()
    expect(screen.getByText('Average nightly accommodation (derived)').nextElementSibling).toHaveTextContent('200.00 CAD')
    expect(screen.getByText('Stay rate (includes commission)').nextElementSibling).toHaveTextContent('705.25 CAD')
    expect(screen.getByText('Recorded received (order)').nextElementSibling).toHaveTextContent('500.00 CAD')
    expect(screen.getByText('Guest total').nextElementSibling).toHaveTextContent('Unavailable')
    expect(screen.getByText('Host payout').nextElementSibling).toHaveTextContent('Unavailable')
    expect(screen.getByText(/Do not add them across stays/)).toBeInTheDocument()
  })

  it('preserves other currencies and distinguishes absent amounts from real zero', () => {
    expect(formatSourceMoney(money(12345, 'USD'))).toContain('123.45 USD')
    expect(formatSourceMoney(money(0, 'CAD'))).toContain('0.00 CAD')
    expect(formatSourceMoney(null)).toBe('Unavailable')
    expect(formatSourceMoney({ formatted: '125.50', amount: null, currency: null })).toBe('125.50 (currency unavailable)')
  })

  it('shows supplied additional guest details and missing contacts honestly', () => {
    render(<HostexReservationDetails source={{ ...source, guest_details: [{ id: 2, name: 'Example Companion', country: 'CA' }] }} />)
    expect(screen.getByText(/Example Companion/)).toHaveTextContent('Email: Unavailable')
    expect(screen.getByText(/Example Companion/)).toHaveTextContent('Country: CA')
  })

  it('shows calendar prices independently from base prices and absent nights as unavailable', () => {
    const property: Pick<Property, 'hostex'> = { hostex: {
      property_id: '101', listing_id: '900001', channel_type: 'airbnb', price_rules: { listing_currency: 'CAD', base_price: 200 },
      calendar: [{ date: '2026-11-01', price: money(22500, 'CAD'), inventory: 1, restrictions: null }],
    } }
    render(<HostexPropertyDetails property={property} />)
    expect(screen.getByText('Airbnb base nightly price: 200 CAD')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Calendar night'), { target: { value: '2026-11-01' } })
    expect(screen.getByText(/Date-specific price:/)).toHaveTextContent('225.00 CAD')
    fireEvent.change(screen.getByLabelText('Calendar night'), { target: { value: '2026-11-02' } })
    expect(screen.getByText(/Date-specific price:/)).toHaveTextContent('Unavailable')
  })

  it('labels source rules without turning included guests into capacity or relabeling fee currency', () => {
    const property: Pick<Property, 'hostex'> = { hostex: { price_rules: {
      listing_currency: 'USD', weekend_price: 275, max_guests: 2, check_in_start_time: 'FLEXIBLE', check_in_end_time: 25,
      days_of_week_check_in: [0, 6], instant_booking: false, early_bird_discount: [{ days: 30, discount: 10 }],
    } } }
    render(<HostexPropertyDetails property={property} />)
    expect(screen.getByText('Weekend nightly price').nextElementSibling).toHaveTextContent('275 USD')
    expect(screen.getByText('Guests included in base price').nextElementSibling).toHaveTextContent('2')
    expect(screen.getByText('Check-in from').nextElementSibling).toHaveTextContent('Flexible')
    expect(screen.getByText('Check-in until').nextElementSibling).toHaveTextContent('01:00 (following day)')
    expect(screen.getByText('Allowed check-in weekdays').nextElementSibling).toHaveTextContent('Sunday, Saturday')
    expect(screen.getByText('Instant booking').nextElementSibling).toHaveTextContent('No')
  })
})
