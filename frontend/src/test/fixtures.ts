import type {
  Conversation,
  MeResponse,
  Message,
  Money,
  Payment,
  PlatformOverview,
} from '@/api/types'

/**
 * Response fixtures.
 *
 * Shaped exactly as the API resources shape them, because a fixture that has
 * drifted from the server is a test asserting against fiction. Where a field
 * is a flag this product treats as load-bearing — `is_simulated`,
 * `is_collected_by_us`, `delivery.simulated` — the default is the *honest* one
 * so a test has to opt in to the reassuring case.
 */

export function money(amount: number, currency = 'EUR'): Money {
  return {
    amount,
    currency,
    formatted: (amount / 100).toFixed(2),
  }
}

export function session(overrides: Partial<MeResponse> = {}): MeResponse {
  return {
    user: {
      id: 'usr_1',
      first_name: 'Ana',
      last_name: 'Ferreira',
      name: 'Ana Ferreira',
      email: 'ana@habitat.test',
      phone: null,
      timezone: 'Europe/Lisbon',
      locale: 'en',
      status: 'active',
      email_verified: true,
      mfa_enabled: false,
      last_login_at: null,
    },
    organization: {
      id: 'org_1',
      name: 'Demo Hospitality Group',
      slug: 'demo-hospitality-group',
      status: 'active',
      base_currency: 'EUR',
      timezone: 'Europe/Lisbon',
      locale: 'en',
      branding: null,
    },
    membership: {
      id: 'mem_1',
      job_title: 'Operations manager',
      default_portal: 'admin',
      restricted_to_properties: false,
      roles: [{ id: 'rol_1', slug: 'manager', name: 'Manager' }],
    },
    permissions: [],
    is_platform_admin: false,
    restricted_property_ids: null,
    ...overrides,
  }
}

export function payment(overrides: Partial<Payment> = {}): Payment {
  return {
    id: 'pay_1',
    reference: 'PAY-0001',
    kind: 'charge',
    kind_label: 'Charge',
    status: 'captured',
    status_label: 'Captured',
    status_colour: 'emerald',
    amount: money(45000),
    captured_amount: money(45000),
    refunded_amount: money(0),
    refundable_amount: money(45000),
    capturable_amount: money(0),
    currency: 'EUR',
    method: 'card',
    provider: 'mock',
    is_simulated: true,
    is_collected_by_us: true,
    instrument_brand: 'Visa',
    instrument_last4: '4242',
    reservation_id: 'res_1',
    created_at: '2025-06-01T10:00:00+00:00',
    ...overrides,
  }
}

export function message(overrides: Partial<Message> = {}): Message {
  return {
    id: 'msg_1',
    conversation_id: 'con_1',
    body: 'Your keys are in the lockbox by the door.',
    body_html: null,
    preview: 'Your keys are in the lockbox…',
    direction: 'outbound',
    author_type: 'user',
    author_name: 'Ana Ferreira',
    transport: 'email',
    channel: null,
    status: 'sent',
    is_internal_note: false,
    is_ai_generated: false,
    template_id: null,
    automation_rule_id: null,
    sent_at: '2025-06-01T10:05:00+00:00',
    delivered_at: null,
    failed_at: null,
    failure_reason: null,
    delivery: {
      transport: 'log',
      simulated: true,
      reason: 'No mail transport is configured; the message was written to the log.',
      fallback_from: null,
    },
    created_at: '2025-06-01T10:05:00+00:00',
    ...overrides,
  }
}

export function conversation(overrides: Partial<Conversation> = {}): Conversation {
  return {
    id: 'con_1',
    subject: 'Arrival on Friday',
    title: 'Marta Silva — Arrival on Friday',
    status: 'open',
    priority: null,
    channel: 'direct',
    participant_type: 'guest',
    guest_id: 'gst_1',
    reservation_id: 'res_1',
    assigned_to_id: null,
    assignee: null,
    last_message_at: '2025-06-01T10:05:00+00:00',
    last_message_preview: 'Your keys are in the lockbox…',
    last_message_direction: 'outbound',
    unread_count: 0,
    messages_count: 1,
    is_awaiting_reply: false,
    minutes_waiting: null,
    first_response_minutes: 12,
    ...overrides,
  }
}

export function platformOverview(overrides: Partial<PlatformOverview> = {}): PlatformOverview {
  return {
    organizations: {
      total: 42,
      by_status: { active: 38, trialing: 3, suspended: 1 },
      expired_trials: 0,
      new_this_month: 4,
    },
    users: { total: 310, platform_admins: 2, active_last_30_days: 188 },
    portfolio: { properties: 1_204, units: 1_680, published_listings: 1_150 },
    trading: {
      reservations_this_month: 820,
      reservations_total: 19_400,
      nights_sold_this_month: 3_110,
    },
    customer_transaction_volume: {
      period: 'June 2025',
      by_currency: [],
    },
    generated_at: '2025-06-15T09:00:00+00:00',
    ...overrides,
  }
}
