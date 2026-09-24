import type { ReactNode } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import {
  Building2,
  CalendarDays,
  ChartNoAxesCombined,
  ClipboardList,
  CreditCard,
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
import { AnnouncementBanner } from '@/components/AnnouncementBanner'
import type { Command } from '@/components/CommandPalette'
import { Shell } from '@/components/Shell'
import { initials } from '@/lib/format'

interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  /** Hidden unless the signed-in user holds one of these. */
  permissions?: string[]
  end?: boolean
  keywords?: string
}

const NAVIGATION: { section: string; items: NavItem[] }[] = [
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
      { to: '/guests', label: 'Guests', icon: Users, permissions: ['guests.view'], keywords: 'people contacts' },
      { to: '/owners', label: 'Owners', icon: UserRound, permissions: ['owners.view'], keywords: 'landlords statements' },
      { to: '/reviews', label: 'Reviews', icon: Star, permissions: ['reviews.view', 'reservations.view'], keywords: 'ratings feedback' },
      { to: '/channels', label: 'Channels', icon: Network, permissions: ['channels.view', 'channels.manage'], keywords: 'distribution airbnb booking ota' },
    ],
  },
  {
    section: 'Money',
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
      // No permission: every member may see the plan they work inside, because
      // somebody who cannot add a property is entitled to know the reason is a
      // cap rather than a fault.
      { to: '/subscription', label: 'Subscription', icon: CreditCard, keywords: 'plan billing limits' },
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
  trialing: 'sky',
  suspended: 'rose',
}

export function AppLayout({ children }: { children: ReactNode }) {
  const { session, organizations, signOut, switchOrganization, canAny } = useAuth()
  const navigate = useNavigate()

  const groups: { section: string; items: NavItem[] }[] = NAVIGATION.map((group) => ({
    section: group.section,
    items: group.items.filter(
      (item) => item.permissions === undefined || canAny(item.permissions),
    ),
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
        group: 'Organizations',
        icon: Landmark,
        keywords: 'organization company tenant',
        run: () => {
          void switchOrganization(organization.id).then(() => toast(`Switched to ${organization.name}`))
        },
      })),
    ...(session?.is_platform_admin === true
      ? [
          {
            id: 'platform',
            label: 'Platform console',
            group: 'Organizations',
            icon: ShieldCheck,
            keywords: 'admin operator tenants',
            run: () => void navigate('/platform'),
          },
        ]
      : []),
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

  return (
    <Shell
      groups={groups}
      commands={commands}
      header={
        <div className="topbar__org">
          <strong>{session?.organization?.name}</strong>
          <span className={`chip chip--${STATUS_COLOURS[session?.organization?.status ?? ''] ?? 'slate'}`}>
            {session?.organization?.status}
          </span>

          {organizations.length > 1 && (
            <select
              value={organizationId ?? ''}
              onChange={(event) => {
                const next = organizations.find((organization) => organization.id === event.target.value)
                void switchOrganization(event.target.value).then(() => {
                  if (next !== undefined) toast(`Switched to ${next.name}`)
                })
              }}
              aria-label="Switch organization"
            >
              {organizations.map((organization) => (
                <option key={organization.id} value={organization.id}>
                  {organization.name}
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
              <div className="small faint truncate">{session?.organization?.name}</div>
            </div>
          </div>

          {/* Shown only to a platform administrator, and it is the only bridge
              between the two interfaces. Everything behind it affects other
              companies, so it is not folded into the navigation above. */}
          {session?.is_platform_admin === true && (
            <NavLink to="/platform" className="btn btn--ghost btn--sm">
              <ShieldCheck size={16} className="nav-link__icon" aria-hidden />
              <span className="btn__label">Platform console →</span>
            </NavLink>
          )}

          <button type="button" className="btn btn--ghost btn--sm" onClick={() => void signOut()}>
            <LogOut size={16} className="nav-link__icon" aria-hidden />
            <span className="btn__label">Sign out</span>
          </button>
        </>
      }
    >
      <AnnouncementBanner />
      {children}
    </Shell>
  )
}
