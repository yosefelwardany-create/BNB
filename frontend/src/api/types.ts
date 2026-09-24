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

/**
 * What a correct password returns when a second factor is enabled.
 *
 * Deliberately carries no user, no token and nothing else: the reference
 * identifies a pending sign-in and authorises nothing.
 */
export interface MfaChallengeResponse {
  mfa_required: true
  challenge: { reference: string; expires_in: number }
  message: string
}

export interface MfaStatus {
  enabled: boolean
  confirmed_at: string | null
  /** A count, never the codes — only hashes are stored. */
  recovery_codes_remaining: number
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

// ---------------------------------------------------------------------------
// Operations
// ---------------------------------------------------------------------------

export interface Task {
  id: string
  reference: string
  kind: string
  kind_label: string
  title: string
  description: string | null
  status: string
  status_label: string
  status_colour: string
  priority: string
  priority_label: string
  priority_colour: string
  property_id: string
  property?: { id: string; name: string } | null
  unit_id: string | null
  reservation_id: string | null
  assigned_to_id: string | null
  is_assigned: boolean
  assignee?: { id: string; name: string } | null
  team_id: string | null
  vendor_id: string | null
  due_at: string | null
  started_at: string | null
  completed_at: string | null
  is_overdue: boolean
  breaches_sla: boolean
  /** A percentage, and only present when the checklist was loaded. */
  checklist_progress?: number
  /** What the lifecycle permits next, decided by the server's enum. */
  allowed_transitions: string[]
  billable_to: string | null
  estimated_minutes: number | null
}

/**
 * The operations board: a day's work already grouped by the server, so the
 * interface does not re-derive which column a task belongs in and then
 * disagree with the list view about it.
 *
 * Overdue work carries forward onto today's board rather than disappearing at
 * midnight, which is why it is a group of its own.
 */
export interface TaskBoard {
  date: string
  data: {
    unassigned: Task[]
    overdue: Task[]
    scheduled: Task[]
    completed: Task[]
  }
  summary: {
    total: number
    open: number
    overdue: number
    unassigned: number
    breaching_sla: number
  }
}

export interface Team {
  id: string
  name: string
  kind: string | null
  member_count?: number
  is_active: boolean
}

// ---------------------------------------------------------------------------
// Messaging
// ---------------------------------------------------------------------------

export interface Conversation {
  id: string
  subject: string | null
  title: string
  status: string
  priority: string | null
  channel: string | null
  participant_type: string
  guest_id: string | null
  guest?: Guest | null
  reservation_id: string | null
  reservation?: Reservation | null
  property?: Property | null
  assigned_to_id: string | null
  assignee?: { id: string; name: string } | null
  last_message_at: string | null
  last_message_preview: string | null
  last_message_direction: string | null
  unread_count: number
  messages_count: number
  /** A guest waiting on us. The number the inbox is really sorted by. */
  is_awaiting_reply: boolean
  minutes_waiting: number | null
  first_response_minutes: number | null
  messages?: Message[]
}

export interface Message {
  id: string
  conversation_id: string
  body: string
  body_html: string | null
  preview: string
  direction: string
  author_type: string
  author_name: string | null
  transport: string
  channel: string | null
  status: string
  is_internal_note: boolean

  // Provenance. A manager must always be able to tell what a person wrote,
  // what a template produced and what a model drafted.
  is_ai_generated: boolean
  template_id: string | null
  automation_rule_id: string | null

  sent_at: string | null
  delivered_at: string | null
  failed_at: string | null
  failure_reason: string | null

  /**
   * Whether anything actually left the building. A message recorded by a
   * local transport is never shown to an operator as delivered.
   */
  delivery: {
    transport: string | null
    simulated: boolean
    reason: string | null
    fallback_from: string | null
  } | null

  created_at: string
}

// ---------------------------------------------------------------------------
// Financials
// ---------------------------------------------------------------------------

export interface Payment {
  id: string
  reference: string
  kind: string
  kind_label: string
  status: string
  status_label: string
  status_colour: string
  amount: Money
  captured_amount: Money
  refunded_amount: Money
  refundable_amount: Money
  capturable_amount: Money
  currency: string
  method: string | null
  provider: string | null
  /** No live processor handled this. Shown wherever a payment is shown. */
  is_simulated: boolean
  /** Whether the money is ours to hold, or a channel's. */
  is_collected_by_us: boolean
  instrument_brand: string | null
  instrument_last4: string | null
  reservation_id: string | null
  created_at: string | null
}

export interface Expense {
  id: string
  reference: string
  expense_date: string | null
  category: string
  description: string
  amount: Money
  tax_amount: Money
  markup_amount: Money
  chargeable_amount: Money
  billable_to: string
  status: string
  is_editable: boolean
  is_paid: boolean
  owner_statement_id: string | null
  property_id: string | null
  vendor_id: string | null
  property?: Property | null
}

export interface OwnerStatement {
  id: string
  reference: string
  owner_id: string
  owner?: { id: string; display_name: string } | null
  period_start: string | null
  period_end: string | null
  currency: string
  gross_revenue: Money
  management_fee: Money
  expenses_total: Money
  net_due: Money
  payout_amount: Money
  closing_balance: Money
  nights_sold: number
  status: string
  is_editable: boolean
  is_in_deficit: boolean
  sent_at: string | null
  paid_at: string | null
}

export interface Owner {
  id: string
  display_name: string
  type: string
  email: string | null
  phone: string | null
  payout_currency: string | null
  payout_method: string | null
  statement_frequency: string | null
  status: string
  portal_enabled: boolean
  /** Set once the owner has a login; null while the portal is only enabled. */
  user_id: string | null
  properties_count?: number
  ownerships?: PropertyOwnership[]
  agreements?: ManagementAgreement[]
}

export interface PropertyOwnership {
  id: string
  property_id: string
  owner_id: string
  property?: Property | null
  ownership_percentage: number
  is_primary: boolean
  /** Null on either side means open-ended. */
  starts_on: string | null
  ends_on: string | null
  is_current: boolean
}

export interface ManagementAgreement {
  id: string
  owner_id: string
  property_id: string | null
  name: string
  reference: string | null
  commission_model: string
  commission_rate: number | null
  commission_amount: number | null
  currency: string
  commission_on_accommodation: boolean
  commission_on_fees: boolean
  commission_on_taxes: boolean
  owner_pays_cleaning: boolean
  owner_pays_maintenance: boolean
  starts_on: string | null
  ends_on: string | null
  status: string
  is_in_force: boolean
}

export interface OwnerPayout {
  id: string
  reference: string
  owner_id: string
  owner_statement_id: string | null
  owner?: Owner | null
  amount: Money
  currency: string
  status: string
  is_paid: boolean
  method: string | null
  external_reference: string | null
  /** Already masked server-side — there is no account number here. */
  destination: Record<string, string> | null
  scheduled_for: string | null
  paid_at: string | null
  failure_message: string | null
}

// ---------------------------------------------------------------------------
// Channels
// ---------------------------------------------------------------------------

export interface ChannelAccount {
  id: string
  channel: string
  name: string
  status: string
  is_connected: boolean
  has_credentials: boolean
  commission_percent: number
  collects_payment: boolean
  listings_count?: number
  last_verified_at: string | null
  last_synced_at: string | null
  last_error: string | null
}

export interface AvailableChannel {
  channel: string
  name: string
  /** False where no partner agreement exists and a simulation stands in. */
  is_live: boolean
  simulation_reason: string | null
  capabilities: string[]
  connected: boolean
}

export interface ChannelListing {
  id: string
  channel_account_id: string
  listing_id: string
  external_listing_id: string
  external_name: string | null
  status: string
  is_active: boolean
  availability_dirty: boolean
  rates_dirty: boolean
  is_failing: boolean
  consecutive_failures: number
  last_error: string | null
  account?: ChannelAccount | null
}

export interface SyncHealth {
  window_hours: number
  jobs: Record<string, { total: number; simulated: number }>
  accounts: Record<string, number>
  listings_behind: number
  listings_failing: number
  retries_waiting: number
}

// ---------------------------------------------------------------------------
// Revenue
// ---------------------------------------------------------------------------

export interface RevenueSummary {
  period: { from: string; to: string }
  currency: string
  nights_sold: number
  nights_available: number
  occupancy_rate: number
  accommodation_revenue: Money
  adr: Money
  revpar: Money
  reservations: number
  average_stay_nights: number
}

export interface RevenueDay {
  date: string
  nights_sold: number
  nights_available: number
  occupancy_rate: number
  accommodation_revenue: Money
  adr: Money
}

export interface RevenueSource {
  source: string
  nights_sold: number
  reservations: number
  accommodation_revenue: Money
  adr: Money
  share_of_revenue: number
}

export interface RevenueProperty {
  property_id: string
  property_name: string
  nights_sold: number
  nights_available: number
  occupancy_rate: number
  reservations: number
  accommodation_revenue: Money
  adr: Money
  revpar: Money
}

export interface RevenuePace {
  period: { from: string; to: string }
  as_at: string
  currency: string
  reservations_on_the_books: number
  nights_on_the_books: number
  nights_available: number
  occupancy_on_the_books: number
  revenue_on_the_books: Money
  average_lead_time_days: number | null
}

// ---------------------------------------------------------------------------
// Reporting
// ---------------------------------------------------------------------------

export interface ReportColumn {
  key: string
  label: string
  type: string
}

export interface ReportDefinition {
  key: string
  name: string
  description: string
  category: string
  columns: ReportColumn[]
}

export interface ReportRun {
  report: ReportDefinition
  rows: Record<string, unknown>[]
  totals: Record<string, unknown>
  /** Caveats the reader needs. Shown, never hidden behind a tooltip. */
  notes: string[]
  meta: Record<string, unknown>
}

/**
 * Where a scheduled report goes.
 *
 * A list rather than one choice, because the useful case is several at once:
 * emailed to the accountant, posted to the warehouse, and kept as a file so
 * somebody can answer "what did this say in March?" six months later.
 *
 * `secret` is write-only. The API never returns one, and a form that displayed
 * it would turn a compromised operator account into a forged payload at every
 * receiver.
 */
export interface ReportDestination {
  type: string
  recipients?: string[]
  url?: string
  secret?: string
  retain_days?: number
}

export interface ReportDestinationOption {
  key: string
  name: string
  description: string
}

export interface SavedReport {
  id: string
  name: string
  description: string | null
  report_key: string
  parameters: Record<string, unknown>
  is_shared: boolean
  is_active: boolean
  schedule_cron: string | null
  schedule_timezone: string | null
  is_scheduled: boolean
  recipients: string[]
  has_recipients: boolean
  destinations: ReportDestination[]
  format: string
  last_run_at: string | null
  next_run_at: string | null
  run_count: number
  /** Why it stopped arriving, which is the question somebody actually has. */
  last_error: string | null
  created_by_id: string | null
  created_at: string | null
}

// ---------------------------------------------------------------------------
// Reviews
// ---------------------------------------------------------------------------

export interface Review {
  id: string
  direction: string
  source: string
  rating: number | null
  rating_scale: number
  rating_percent: number | null
  is_negative: boolean
  title: string | null
  public_comment: string | null
  private_comment: string | null
  response: string | null
  has_response: boolean
  responded_at: string | null
  response_hours: number | null
  status: string
  is_hidden_internally: boolean
  property_id: string | null
  property?: Property | null
  guest?: Guest | null
  submitted_at: string | null
}

export interface ReviewSummary {
  reviews: number
  average_percent: number | null
  average_out_of_five: number | null
  negative: number
  responded: number
  response_rate: number
  awaiting_response: number
}

// ---------------------------------------------------------------------------
// Platform
// ---------------------------------------------------------------------------

export interface ApiKey {
  id: string
  name: string
  prefix: string
  abilities: string[]
  rate_limit_per_minute: number
  last_used_at: string | null
  request_count: number
  expires_at: string | null
  revoked_at: string | null
  is_usable: boolean
  created_at: string | null
}

export interface WebhookEndpoint {
  id: string
  name: string
  url: string
  events: string[]
  receives_all_events: boolean
  status: string
  is_healthy: boolean
  consecutive_failures: number
  last_success_at: string | null
  last_failure_at: string | null
  last_error: string | null
  deliveries_count?: number
  failure_threshold: number
  timeout_seconds: number
  max_attempts: number
  has_custom_headers: boolean
}

export interface WebhookDelivery {
  id: string
  webhook_endpoint_id: string
  domain_event_id: string | null
  event_name: string
  attempt: number
  status: string
  /** What was actually sent, byte for byte — kept, never regenerated. */
  payload: Record<string, unknown> | null
  response_status: number | null
  response_body: string | null
  duration_ms: number | null
  error_message: string | null
  dispatched_at: string | null
  completed_at: string | null
  next_attempt_at: string | null
}

// ---------------------------------------------------------------------------
// Platform console
//
// For whoever runs the platform, not for a tenant on it. These shapes come from
// /api/v1/platform/*, which is unreachable without the platform-admin flag.
// ---------------------------------------------------------------------------

export interface Plan {
  id: string
  name: string
  slug: string
  description: string | null
  price: Money
  billing_interval: string
  trial_days: number
  /** Null means unlimited, which is not the same as a large number. */
  limits: Record<string, number | null>
  features: string[]
  is_public: boolean
  is_active: boolean
  position: number
  organizations_count?: number
}

export interface PlatformTenant {
  id: string
  name: string
  legal_name: string | null
  slug: string
  status: string
  status_label: string
  is_operational: boolean
  base_currency: string
  timezone: string
  country_code: string | null
  contact_email: string | null
  contact_phone: string | null
  plan: Plan | null
  plan_id: string | null
  trial_ends_at: string | null
  trial_has_expired: boolean
  suspended_at: string | null
  suspension_reason: string | null
  effective_limits: Record<string, number | null>
  limit_overrides: Record<string, number | null> | null
  feature_overrides: Record<string, boolean> | null
  /** Never shown to the tenant itself. */
  platform_notes: string | null
  users_count?: number
  created_at: string | null
}

export interface UsageRow {
  used: number
  limit: number | null
  remaining: number | null
  at_limit: boolean
}

export interface FeatureRow {
  enabled: boolean
  /** Where the answer came from: 'plan', 'override' or 'unmetered'. */
  source: string
}

export interface TenantDetailMeta {
  usage: Record<string, UsageRow>
  features: Record<string, FeatureRow>
  counts: Record<string, number>
  last_activity_at: string | null
  last_reservation_at: string | null
}

export interface PlatformOverview {
  organizations: {
    total: number
    by_status: Record<string, number>
    expired_trials: number
    new_this_month: number
  }
  users: { total: number; platform_admins: number; active_last_30_days: number }
  portfolio: { properties: number; units: number; published_listings: number }
  trading: {
    reservations_this_month: number
    reservations_total: number
    nights_sold_this_month: number
  }
  customer_transaction_volume: {
    period: string
    /** Per currency, never summed across them. */
    by_currency: { currency: string; amount: number; payments: number }[]
  }
  generated_at: string
}

export interface ProviderHealth {
  provider: string | null
  name: string | null
  is_live: boolean
  simulation_reason: string | null
}

export interface ChannelHealth extends ProviderHealth {
  channel: string
  connected_accounts: number
}

export interface PlatformHealth {
  queues: {
    driver: string
    database_depth: number | null
    failed_total: number
    failed_last_24h: number
    oldest_failure_at: string | null
    /** False when the real queue is Redis, where a depth of nought means nothing. */
    depth_visible: boolean
  }
  webhooks: {
    window_hours: number
    by_status: Record<string, number>
    endpoints_unhealthy: number
    endpoints_disabled: number
    retries_waiting: number
  }
  channels: {
    window_hours: number
    jobs: Record<string, { total: number; simulated: number }>
    accounts_errored: number
    listings_behind: number
  }
  integrations: {
    payments: ProviderHealth
    messaging: ProviderHealth
    locks: ProviderHealth
    ai: ProviderHealth
    channels: ChannelHealth[]
  }
  database: {
    reachable: boolean
    size_bytes?: number
    pending_migrations?: number
    error?: string
  }
  generated_at: string
}

export interface PlatformVocabulary {
  features: { key: string; description: string }[]
  limits: { key: string; label: string }[]
  settings: {
    key: string
    type: string
    value: unknown
    default: unknown
    description: string
  }[]
}

export interface PlatformUser {
  id: string
  name: string
  email: string
  status: string
  is_platform_admin: boolean
  mfa_enabled: boolean
  email_verified: boolean
  organizations_count: number
  last_login_at: string | null
  created_at: string | null
}

export interface PlatformAnnouncement {
  id: string
  title: string
  body: string
  level: string
  audience: string
  organization_ids: string[]
  starts_at: string | null
  ends_at: string | null
  is_published: boolean
  is_dismissible: boolean
  /** Published *and* inside its window, which is not the same as published. */
  is_live: boolean
  created_at: string | null
}

export interface SupportSession {
  id: string
  organization_id: string
  organization?: { id: string; name: string } | null
  operator?: { id: string; name: string; email: string } | null
  viewed_as?: { id: string; name: string; email: string } | null
  reason: string
  started_at: string | null
  expires_at: string | null
  ended_at: string | null
  ended_reason: string | null
  is_open: boolean
  request_count: number
  ip_address: string | null
  /** Always true. The guarantee is the point. */
  was_read_only: boolean
}

export interface PlatformAuditRow {
  id: string
  action: string
  actor_email: string | null
  organization_id: string | null
  organization_name: string | null
  subject_type: string | null
  subject_id: string | null
  description: string | null
  context: Record<string, unknown> | null
  ip_address: string | null
  created_at: string | null
}

// ---------------------------------------------------------------------------
// The tenant's own view of its subscription
// ---------------------------------------------------------------------------

export interface TenantPlan {
  plan: Plan | null
  status: string
  status_label: string
  trial_ends_at: string | null
  trial_has_expired: boolean
  usage: Record<string, UsageRow>
  features: Record<string, FeatureRow>
  /** False means nothing is capped, not that a free tier might cut off. */
  is_metered: boolean
}

export interface TenantAnnouncement {
  id: string
  title: string
  body: string
  level: string
  is_dismissible: boolean
  starts_at: string | null
  ends_at: string | null
}
