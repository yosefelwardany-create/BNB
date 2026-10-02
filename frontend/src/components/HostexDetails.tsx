import { useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { HostexReservation, Property, SourceMoney, Paginated } from '@/api/types'
import { formatDate, formatSourceMoney } from '@/lib/format'
import { QueryState } from './QueryState'

export function HostexReservationDetails({ source }: { source: HostexReservation }) {
  const f = source.financials
  const amounts: [string, SourceMoney | null | undefined][] = [
    ['Accommodation subtotal', f.accommodation], ['Average nightly accommodation (derived)', f.average_nightly_accommodation],
    ['Cleaning fee', f.cleaning_fee], ['Taxes', f.tax], ['Stay rate (includes commission)', f.reservation_rate],
    ['Stay commission', f.commission], ['Order rate (all stays)', f.order_rate], ['Order commission', f.order_commission],
    ['Guest total', f.guest_total], ['Host payout', f.host_payout], ['Cancellation refund detail', f.refund_detail],
    ['Order settlement basis', f.payment?.total_amount], ['Recorded received (order)', f.payment?.received_amount],
    ['Recorded outstanding (order)', f.payment?.balance_amount],
  ]
  if (!source.synced_at && amounts.every(([, amount]) => amount == null)) {
    return <p className="small muted" style={{ maxWidth: 280, whiteSpace: 'normal' }}>Booking and guest details have not been refreshed from Hostex. Run Pull in Channels; any request failure will appear there.</p>
  }
  return <details className="small" style={{ minWidth: 240, whiteSpace: 'normal' }}>
    <summary>Hostex details</summary>
    <p>Hostex order: {source.reservation_code ?? 'Unavailable'}<br />Stay: {source.stay_code ?? 'Unavailable'}<br />Updated: {formatDate(source.synced_at)}</p>
    {source.guest_notes && <p>Guest notes: {source.guest_notes}</p>}
    {source.host_notes && <p>Host notes from Hostex: {source.host_notes}</p>}
    {!!source.guest_details?.length && <details><summary>Guest details from Hostex</summary><ul>{source.guest_details.map((guest, i) => <li key={guest.id ?? i}>
      {guest.name || 'Guest'}{guest.is_booker ? ' (booker)' : ''} · Email: {guest.email || 'Unavailable'} · Phone: {guest.phone || 'Unavailable'} · Country: {guest.country || 'Unavailable'}
    </li>)}</ul></details>}
    <dl>{amounts.map(([label, amount]) => <div key={label}><dt>{label}</dt><dd>{formatSourceMoney(amount)}</dd></div>)}</dl>
    <p>Collection status in Hostex: {f.payment?.status ?? 'Unavailable'}. Payout status: {f.payout_status ?? 'Unavailable'}.</p>
    <p>Collections apply to the whole order. Do not add them across stays. “Unreceived” does not prove the Airbnb guest has not paid.</p>
    {f.details.length > 0 && <ul>{f.details.map((line, i) => <li key={i}>{line.description || line.type || 'Rate detail'}: {formatSourceMoney(line.money)}</li>)}</ul>}
    {f.additional_fees.length > 0 && <ul>{f.additional_fees.map((line, i) => <li key={i}>{line.name ?? 'Additional fee'}: {formatSourceMoney(line.money)}</li>)}</ul>}
    {source.limitations.map((note) => <p key={note} className="muted">{note}</p>)}
  </details>
}

export function HostexPropertyDetails({ property }: { property: Pick<Property, 'hostex'> }) {
  const [date, setDate] = useState('')
  const source = property.hostex
  if (!source) return null
  const day = source.calendar?.find((entry) => entry.date === date)
  const rules = source.price_rules
  return <details className="small mt-2">
    <summary>Hostex listing and nightly prices</summary>
    <p>Hostex property: {source.property_id ?? 'Unavailable'}<br />Channel listing: {source.listing_id ?? 'Unavailable'}<br />Listing status: {source.shelf_status ?? 'Unavailable'}</p>
    {source.url?.startsWith('https://') && <p><a href={source.url} target="_blank" rel="noreferrer">View source listing</a></p>}
    <p>Source base nightly price: {rules?.base_price != null ? `${rules.base_price} ${rules.listing_currency ?? '(currency unavailable)'}` : 'Unavailable'}</p>
    {rules && <HostexBookingRules rules={rules} />}
    <label>Calendar night <input type="date" value={date} min={source.calendar_coverage?.from} max={source.calendar_coverage?.to} onChange={(e) => setDate(e.target.value)} /></label>
    {date && <p>Date-specific price: {formatSourceMoney(day?.price)}<br />Inventory: {day?.inventory ?? 'Unavailable'}</p>}
    {day?.restrictions && <ul>{Object.entries(day.restrictions).map(([key, value]) => <li key={key}>{key.replaceAll('_', ' ')}: {String(value)}</li>)}</ul>}
    {source.calendar_coverage && <p>Calendar coverage: {source.calendar_coverage.from} – {source.calendar_coverage.to}. Refreshed {formatDate(source.calendar_coverage.synced_at)}.</p>}
    {source.limitations?.map((note) => <p key={note} className="muted">{note}</p>)}
  </details>
}

const bookingRuleLabels: Record<string, string> = {
  weekend_price: 'Weekend nightly price', cleaning_fee: 'Cleaning fee', short_term_cleaning_fee: 'Short stay cleaning fee',
  security_deposit: 'Security deposit', pet_fee: 'Pet fee', pet_fee_obj: 'Pet fee terms', extra_guest_fee: 'Extra guest fee',
  max_guests: 'Guests included in base price', check_in_start_time: 'Check-in from', check_in_end_time: 'Check-in until', check_out_before: 'Check-out before',
  minimum_stay: 'Minimum nights', maximum_stay: 'Maximum nights', instant_booking: 'Instant booking',
  advance_notice: 'Advance notice (hours)', availability_window: 'Booking window (days)', preparation_time: 'Preparation time (days)',
  days_of_week_check_in: 'Allowed check-in weekdays', days_of_week_check_out: 'Allowed check-out weekdays',
  early_bird_discount: 'Early booking discounts (%)', last_minute_discount: 'Last minute discounts (%)', long_term_discount: 'Long stay discounts (%)',
  cancellation_policy: 'Cancellation policy', long_term_cancellation_policy: 'Long stay cancellation policy',
  non_refundable_price_factor: 'Non-refundable discount (%)', allow_rtb_above_max_nights: 'Allow requests above maximum nights',
  day_of_week_min_nights: 'Minimum nights by weekday', seasonal_min_stay: 'Seasonal minimum nights', new_listing_promotion: 'New listing promotion',
  high_rated_guest_discount: 'Highly rated guest discount', high_rated_guest_discount_blackout_dates: 'Guest discount excluded dates',
  high_rated_guest_discount_blackout_dates_quota: 'Guest discount excluded date allowance',
  mobile_only_discount: 'Mobile booking discount', mobile_only_discount_blackout_dates: 'Mobile discount excluded dates',
  mobile_only_discount_blackout_dates_quota: 'Mobile discount excluded date allowance', eligible_cancellation_policies: 'Available cancellation policies',
}
const moneyRules = new Set(['weekend_price', 'cleaning_fee', 'short_term_cleaning_fee', 'security_deposit', 'pet_fee', 'extra_guest_fee'])
const weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

function ruleValue(value: unknown): ReactNode {
  if (value == null) return 'Unavailable'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (Array.isArray(value)) return value.length ? <ul>{value.map((entry, i) => <li key={i}>{ruleValue(entry)}</li>)}</ul> : 'None specified'
  if (typeof value === 'object') return <dl>{Object.entries(value).map(([key, entry]) => <div key={key}><dt>{key.replaceAll('_', ' ')}</dt><dd>{ruleValue(entry)}</dd></div>)}</dl>
  return typeof value === 'string' || typeof value === 'number' ? String(value).replaceAll('_', ' ') : 'Unavailable'
}

function HostexBookingRules({ rules }: { rules: NonNullable<NonNullable<Property['hostex']>['price_rules']> }) {
  function display(key: string, value: unknown): ReactNode {
    if (value == null) return 'Unavailable'
    if (moneyRules.has(key) && (typeof value === 'number' || typeof value === 'string')) return `${value} ${rules.listing_currency ?? '(currency unavailable)'}`
    if (key.startsWith('check_in_') || key === 'check_out_before') {
      if (value === 'FLEXIBLE') return 'Flexible'
      if (typeof value === 'number' && value >= 0 && value <= 26) return `${String(value % 24).padStart(2, '0')}:00${value >= 24 ? ' (following day)' : ''}`
    }
    if ((key === 'days_of_week_check_in' || key === 'days_of_week_check_out') && Array.isArray(value)) {
      return value.map((day) => weekdays[Number(day)] ?? String(day)).join(', ') || 'None specified'
    }
    return ruleValue(value)
  }
  return <details><summary>Source booking rules and fees</summary>
    <p>Source fee currency: {rules.listing_currency ?? 'Unavailable'}. Included guests is a pricing threshold; capacity is managed separately.</p>
    <dl>{Object.entries(bookingRuleLabels).filter(([key]) => Object.hasOwn(rules, key)).map(([key, label]) => <div key={key}><dt>{label}</dt><dd>{display(key, rules[key])}</dd></div>)}</dl>
  </details>
}

interface SourceTransaction {
  id: string; external_id: string; property_id: string | null; reservation_code?: string | null
  direction?: string; status?: string; item_name?: string; payment_method_name?: string
  action_at?: string; synced_at: string; money: SourceMoney | null
}

export function HostexTransactions() {
  const [page, setPage] = useState(1)
  const query = useQuery({ queryKey: ['hostex-transactions', page], queryFn: () => api.get<Paginated<SourceTransaction>>('hostex-transactions', { page, per_page: 25 }) })
  return <section className="card">
    <div className="card__header"><h2>Hostex income and expense records</h2><p className="small muted">Source records only. These are not local ledger entries or verified Airbnb payouts. Order-linked entries cover all stays in that order.</p></div>
    <QueryState isLoading={query.isLoading} error={query.error} isEmpty={query.data?.data.length === 0} emptyTitle="No source transactions imported" emptyBody="Run Pull on the Hostex connection. Its result reports coverage and unavailable data.">
      <div className="table-wrap"><table className="data"><thead><tr><th>Source entry</th><th>Order</th><th>Type</th><th>Category / method</th><th>Amount</th><th>Status</th><th>Date / last seen</th></tr></thead>
        <tbody>{query.data?.data.map((entry) => <tr key={entry.id}><td>{entry.external_id}</td><td>{entry.reservation_code ?? 'Property or account entry'}</td><td>{entry.direction}</td><td>{entry.item_name ?? 'Unavailable'} / {entry.payment_method_name ?? 'Unavailable'}</td><td>{formatSourceMoney(entry.money)}</td><td>{entry.status ?? 'Unavailable'}</td><td>{formatDate(entry.action_at)} / {formatDate(entry.synced_at)}</td></tr>)}</tbody></table></div>
    </QueryState>
    <div className="card__footer row"><button className="btn btn--sm" disabled={page === 1} onClick={() => setPage(page - 1)}>Previous</button><span>Page {page}</span><button className="btn btn--sm" disabled={!query.data || page >= query.data.meta.last_page} onClick={() => setPage(page + 1)}>Next</button></div>
  </section>
}
