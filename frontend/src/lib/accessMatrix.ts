import type { TeamOptions } from '@/api/types'

/**
 * The permission catalogue as modules of features, each split into what
 * shows a feature and what changes it.
 *
 * Every permission belongs to a feature — the part before its dot, so
 * `reservations.cancel` is Reservations and `calendar.update` is the Calendar
 * — and is either seeing it (`view`, `view_own`) or changing it (everything
 * else).
 */
export interface FeatureRow {
  key: string
  label: string
  see: string[]
  edit: string[]
  /** Each permission's own description, for the row's tooltip. */
  labels: Record<string, string>
}

export interface ModuleRows {
  name: string
  features: FeatureRow[]
}

const FEATURE_LABELS: Record<string, string> = {
  organization: 'Account settings',
  users: 'Team members',
  roles: 'Roles',
  audit: 'Audit log',
  portfolios: 'Portfolios',
  properties: 'Properties',
  units: 'Units',
  listings: 'Listings',
  guests: 'Guests',
  owners: 'Owners',
  reservations: 'Reservations',
  calendar: 'Calendar',
  pricing: 'Rates and pricing rules',
  rate_plans: 'Rate plans',
  promotions: 'Promotions and coupons',
  revenue: 'Revenue analytics',
  tasks: 'Tasks',
  checklists: 'Checklists',
  inspections: 'Inspections',
  vendors: 'Vendors',
  teams: 'Staff teams',
  recurrences: 'Recurring work',
  messages: 'Messages',
  templates: 'Message templates',
  automations: 'Automations',
  channels: 'Channels',
  financials: 'Financial records',
  payments: 'Payments',
  expenses: 'Expenses and bills',
  invoices: 'Invoices',
  ledger: 'Accounting ledger',
  taxes: 'Tax rules',
  owner_statements: 'Owner statements',
  owner_payouts: 'Owner payouts',
  reviews: 'Reviews',
  reports: 'Reports',
  analytics: 'Analytics dashboards',
  integrations: 'Integrations',
  api_keys: 'API keys',
  webhooks: 'Webhooks',
  locks: 'Smart locks and access codes',
  documents: 'Documents',
  imports: 'Data imports',
  website: 'Booking website',
}

const SEEING = new Set(['view', 'view_own'])

function featureLabel(key: string): string {
  return FEATURE_LABELS[key] ?? key.replace(/_/g, ' ').replace(/^./, (first) => first.toUpperCase())
}

/** The permission catalogue as modules of features, each split into seeing and changing. */
export function modulesFrom(areas: TeamOptions['areas']): ModuleRows[] {
  return areas.map((area) => {
    const features = new Map<string, FeatureRow>()

    for (const permission of area.permissions) {
      const [feature = permission.key, action = ''] = permission.key.split('.')
      const row = features.get(feature) ?? { key: feature, label: featureLabel(feature), see: [], edit: [], labels: {} }

      ;(SEEING.has(action) ? row.see : row.edit).push(permission.key)
      row.labels[permission.key] = permission.label
      features.set(feature, row)
    }

    return { name: area.name, features: [...features.values()] }
  })
}
