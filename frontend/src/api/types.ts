/**
 * The API's response shapes.
 *
 * Money always arrives as an object carrying the integer minor units, the
 * currency and a preformatted decimal string. The frontend never does
 * arithmetic on a formatted string, and never divides by 100 itself.
 */

export interface Money {
  amount: number
  currency: string
  formatted: string
}

export interface Paginated<T> {
  data: T[]
  links: { first: string | null; last: string | null; prev: string | null; next: string | null }
  meta: { current_page: number; from: number | null; last_page: number; per_page: number; to: number | null; total: number }
}

export interface SessionUser {
  id: string
  first_name: string
  last_name: string | null
  name: string
  email: string
  phone: string | null
  timezone: string
  locale: string
  status: string
  email_verified: boolean
  mfa_enabled: boolean
  last_login_at: string | null
}

export interface OrganizationSummary {
  id: string
  name: string
  slug: string
  status: string
  base_currency: string
  timezone: string
  default_portal?: string
}

export interface LoginResponse {
  user: SessionUser
  organizations: OrganizationSummary[]
  token?: string
  token_expires_at?: string
}

export interface MeResponse {
  user: SessionUser
  organization: OrganizationSummary & { locale: string; branding: unknown }
  membership: {
    id: string
    job_title: string | null
    default_portal: string
    restricted_to_properties: boolean
    roles: { id: string; slug: string; name: string }[]
  } | null
  permissions: string[]
  is_platform_admin: boolean
  restricted_property_ids: string[] | null
}

export interface Property {
  id: string
  name: string
  internal_name: string | null
  display_name: string
  slug: string
  property_type: string
  property_type_label: string
  rental_kind: string
  status: string
  is_multi_unit: boolean
  tracks_availability_per_unit: boolean
  portfolio_id: string | null
  address: {
    line_1: string | null
    line_2: string | null
    city: string | null
    state: string | null
    postal_code: string | null
    country_code: string | null
    latitude: number | null
    longitude: number | null
  }
  timezone: string
  currency: string
  local_time: string
  capacity: {
    bedrooms: number
    bathrooms: number
    beds: number
    max_occupancy: number
    max_pets: number
  }
  pricing: {
    base_rate: Money
    cleaning_fee: Money
    minimum_nights: number
    maximum_nights: number | null
    instant_book: boolean
  }
  counts?: { units: number; listings: number }
  created_at: string | null
}

export interface Listing {
  id: string
  property_id: string
  name: string
  status: string
  is_primary: boolean
  is_bookable: boolean
  inventory_scope: string
  title: string
  currency: string
  pricing: {
    base_rate: Money
    cleaning_fee: Money
    minimum_nights: number
    maximum_nights: number | null
  }
  overridden_fields: string[]
  published_at: string | null
}

export interface Reservation {
  id: string
  confirmation_code: string
  status: string
  status_label: string
  status_colour: string
  blocks_inventory: boolean
  source: string
  property_id: string
  listing_id: string | null
  unit_id: string | null
  guest_id: string | null
  stay: {
    check_in_date: string
    check_out_date: string
    nights: number
    check_in_time: string | null
    check_out_time: string | null
    days_until_arrival: number
  }
  guests: { adults: number; children: number; infants: number; pets: number; total: number }
  currency: string
  financials: {
    accommodation: Money
    fees: Money
    taxes: Money
    discounts: Money
    grand_total: Money
    paid: Money
    refunded: Money
    balance_due: Money
    average_daily_rate: Money
  }
  guest?: Guest
  property?: Property
  booked_at: string | null
  created_at: string | null
}

export interface Guest {
  id: string
  first_name: string
  last_name: string | null
  display_name: string
  email: string | null
  phone: string | null
  country_code: string | null
  language: string | null
  stats: {
    reservations: number
    nights: number
    lifetime_value: Money
    first_stay_date: string | null
    last_stay_date: string | null
    is_returning: boolean
  }
}

export interface CalendarDay {
  date: string
  available: boolean
  total_units: number
  sold_units: number
  blocked_units: number
  remaining_units: number
  occupancy_rate: number
  manually_blocked: boolean
  minimum_nights: number | null
  closed_to_arrival: boolean
  closed_to_departure: boolean
  rate_override: number | null
  note: string | null
  reservation_ids: string[]
  block_ids: string[]
}

export interface CalendarRow {
  listing_id: string
  listing_name: string
  property_id: string
  property_name: string
  timezone: string
  currency: string
  inventory_scope: string
  days: CalendarDay[]
  summary: { nights: number; sold: number; blocked: number; occupancy_rate: number }
}

export interface CalendarResponse {
  from: string
  to: string
  listings: CalendarRow[]
  reservations: {
    id: string
    confirmation_code: string
    property_id: string
    listing_id: string | null
    unit_id: string | null
    status: string
    status_colour: string
    guest_name: string | null
    check_in_date: string
    check_out_date: string
    nights: number
    guests: number
    source: string
    balance_due: number
    currency: string
  }[]
  blocks: {
    id: string
    property_id: string
    unit_id: string | null
    kind: string
    label: string
    start_date: string
    end_date: string
    nights: number
  }[]
}

export interface PriceQuote {
  currency: string
  nights: {
    date: string
    rate: Money
    base_rate: Money
    steps: { source: string; label: string; before: number; after: number; delta: number; description: string }[]
  }[]
  fees: QuoteLine[]
  discounts: QuoteLine[]
  taxes: QuoteLine[]
  totals: {
    accommodation: Money
    fees: Money
    discounts: Money
    taxes: Money
    grand_total: Money
    average_nightly_rate: Money
  }
  nights_count: number
  notices: string[]
}

export interface QuoteLine {
  kind: string
  code: string
  label: string
  description: string | null
  quantity: number
  amount: Money
  is_taxable: boolean
  is_refundable: boolean
  trace: Record<string, unknown>
}
