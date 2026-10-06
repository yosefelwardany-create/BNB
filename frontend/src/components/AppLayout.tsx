import type { ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Bot,
  Building2,
  CalendarDays,
  ChartNoAxesCombined,
  ClipboardList,
  FileBarChart,
  Home,
  Inbox,
  KeyRound,
  Landmark,
  LogOut,
  Moon,
  Network,
  ShieldCheck,
  Sparkles,
  Star,
  UserRound,
  Users,
  Wallet,
  type LucideIcon,
} from 'lucide-react'
import { useAuth } from '@/lib/auth'
import { toggleTheme } from '@/lib/theme'
import { toast } from '@/lib/toast'
import { AccountSelector } from '@/components/AccountSelector'
import type { Command } from '@/components/CommandPalette'
import { Shell } from '@/components/Shell'
import { initials } from '@/lib/format'

interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  /** Hidden unless the signed-in user holds one of these. */
  permissions?: string[]
  /** Shown only to the platform owner, whatever permissions say. */
  platformAdminOnly?: boolean
  end?: boolean
  keywords?: string
}

/**
 * The owner workspace's navigation.
 *
 * Every operational screen is here and scoped to the selected client account.
 * The one section that is not about a single account is "Accounts", which is
 * where the platform owner creates clients, suspends and reinstates them and
 * manages who else administers the platform: the administration that used to
 * live in a separate console.
 */
const NAVIGATION: { section: string; items: NavItem[]; foldedByDefault?: boolean }[] = [
  {
    section: 'Operate',
    items: [
      { to: '/', label: 'Dashboard', icon: Home, end: true, keywords: 'today home overview' },
      { to: '/calendar', label: 'Calendar', icon: CalendarDays, permissions: ['calendar.view'], keywords: 'availability' },
      { to: '/reservations', label: 'Reservations', icon: ClipboardList, permissions: ['reservations.view'], keywords: 'bookings stays' },
      { to: '/inbox', label: 'Inbox', icon: Inbox, permissions: ['messages.view', 'messages.send'], keywords: 'messages guests chat' },
      { to: '/operations', label: 'Operations', icon: Sparkles, permissions: ['tasks.view'], keywords: 'cleaning tasks housekeeping board' },
    ],
  },
  {
    section: 'Manage',
    items: [
      { to: '/properties', label: 'Properties', icon: Building2, permissions: ['properties.view'], keywords: 'listings units' },
      {
        to: '/agent',
        // "Guest agent" was accurate when the screen only drafted replies to
        // guests. It is now also where an operator asks their own questions
        // about a property, and somebody looking for that was not going to find
        // it under a label about guests.
        label: 'Agents',
        icon: Bot,
        permissions: ['properties.view'],
        keywords: 'ai assistant bot grok automation replies bench evals webhook ask',
      },
      { to: '/guests', label: 'Guests', icon: Users, permissions: ['guests.view'], keywords: 'people contacts' },
      { to: '/owners', label: 'Owners', icon: UserRound, permissions: ['owners.view'], keywords: 'landlords statements agreement commission' },
      { to: '/reviews', label: 'Reviews', icon: Star, permissions: ['reviews.view', 'reservations.view'], keywords: 'ratings feedback' },
      { to: '/channels', label: 'Channels', icon: Network, permissions: ['channels.view', 'channels.manage'], keywords: 'distribution airbnb booking ota hostex' },
    ],
  },
  {
    section: 'Money',
    // Folded by default, per the Oct 1 review: the platform's point is the
    // properties and the agents running them. These are read on purpose, once a
    // week, and a sidebar giving them equal weight buries the thing people open
    // fifty times a day. Folded, never removed — and a section reopens itself
    // whenever somebody is on one of its pages.
    foldedByDefault: true,
    items: [
      {
        to: '/financials',
        label: 'Financials',
        icon: Wallet,
        permissions: ['payments.view', 'expenses.manage', 'owner_statements.view'],
        keywords: 'payments expenses ledger',
      },
      { to: '/revenue', label: 'Revenue', icon: ChartNoAxesCombined, permissions: ['revenue.view'], keywords: 'occupancy adr revpar pace' },
      { to: '/reports', label: 'Reports', icon: FileBarChart, permissions: ['reports.view'], keywords: 'exports schedules' },
    ],
  },
  {
    section: 'Configure',
    items: [
      {
        to: '/accounts',
        label: 'Accounts',
        icon: ShieldCheck,
        platformAdminOnly: true,
        keywords: 'clients organizations tenants create suspend invite administrators audit',
      },
      {
        to: '/settings',
        label: 'Developer',
        icon: KeyRound,
        permissions: ['api_keys.manage', 'webhooks.manage', 'integrations.view'],
        keywords: 'api keys webhooks integrations settings',
      },
    ],
  },
]

const STATUS_COLOURS: Record<string, string> = {
  active: 'emerald',
  trial: 'sky',
  suspended: 'rose',
  cancelled: 'slate',
}

export function AppLayout({ children }: { children: ReactNode }) {
  const { session, organizations, signOut, switchOrganization, canAny } = useAuth()
  const navigate = useNavigate()

  const isPlatformAdmin = session?.is_platform_admin === true

  const groups: { section: string; items: NavItem[]; foldedByDefault?: boolean }[] = NAVIGATION.map((group) => ({
    section: group.section,
    foldedByDefault: group.foldedByDefault,
    items: group.items.filter((item) => {
      if (item.platformAdminOnly === true) return isPlatformAdmin

      return item.permissions === undefined || canAny(item.permissions)
    }),
  })).filter((group) => group.items.length > 0)

  const organizationId = session?.organization?.id

  const commands: Command[] = [
    ...groups.flatMap((group) =>
      group.items.map((item) => ({
        id: `nav:${item.to}`,
        label: item.label,
        group: 'Go to',
        icon: item.icon,
        hint: group.section,
        keywords: item.keywords,
        run: () => void navigate(item.to),
      })),
    ),
    ...organizations
      .filter((organization) => organization.id !== organizationId)
      .map((organization) => ({
        id: `org:${organization.id}`,
        label: `Switch to ${organization.name}`,
        group: 'Accounts',
        icon: Landmark,
        keywords: 'account client organization company switch',
        run: () => {
          void switchOrganization(organization.id).then(() => {
            // A path from the previous account may carry its ids.
            void navigate('/')
            toast(`Now managing ${organization.name}`)
          })
        },
      })),
    {
      id: 'theme',
      label: 'Toggle light and dark theme',
      group: 'Interface',
      icon: Moon,
      keywords: 'dark mode light mode appearance',
      run: toggleTheme,
    },
    {
      id: 'sign-out',
      label: 'Sign out',
      group: 'Account',
      icon: LogOut,
      keywords: 'log out logout exit',
      run: () => void signOut(),
    },
  ]

  const organization = session?.organization ?? null

  return (
    <Shell
      brandSub={isPlatformAdmin ? 'Owner workspace' : undefined}
      account={isPlatformAdmin ? <AccountSelector /> : undefined}
      groups={groups}
      commands={commands}
      header={
        <div className="topbar__org">
          {/* The account every screen below is about. For the platform owner
              this is the selected client; saying so in the top bar means no
              screen can be read without knowing whose it is. */}
          {isPlatformAdmin && organization !== null && <span className="small faint">Managing</span>}
          <strong>{organization?.name ?? (isPlatformAdmin ? 'No account selected' : '')}</strong>
          {organization !== null && (
            <span className={`chip chip--${STATUS_COLOURS[organization.status] ?? 'slate'}`}>
              {organization.status}
            </span>
          )}

          {/* Staff of a client with more than one company. The platform owner
              switches from the sidebar instead. */}
          {!isPlatformAdmin && organizations.length > 1 && (
            <select
              value={organizationId ?? ''}
              onChange={(event) => {
                const next = organizations.find((candidate) => candidate.id === event.target.value)
                void switchOrganization(event.target.value).then(() => {
                  void navigate('/')
                  if (next !== undefined) toast(`Switched to ${next.name}`)
                })
              }}
              aria-label="Switch organization"
            >
              {organizations.map((candidate) => (
                <option key={candidate.id} value={candidate.id}>
                  {candidate.name}
                </option>
              ))}
            </select>
          )}
        </div>
      }
      footer={
        <>
          <div className="user-card">
            <span className="avatar" aria-hidden="true">
              {initials(session?.user.name)}
            </span>
            <div className="user-card__text">
              <div className="small strong truncate">{session?.user.name}</div>
              <div className="small faint truncate">
                {isPlatformAdmin ? 'Platform owner' : organization?.name}
              </div>
            </div>
          </div>

          <button type="button" className="btn btn--ghost btn--sm" onClick={() => void signOut()}>
            <LogOut size={16} className="nav-link__icon" aria-hidden />
            <span className="btn__label">Sign out</span>
          </button>
        </>
      }
    >
      {children}
    </Shell>
  )
}
