import type { ReactNode } from 'react'
import { NavLink } from 'react-router-dom'
import { useAuth } from '@/lib/auth'
import { AnnouncementBanner } from '@/components/AnnouncementBanner'

interface NavItem {
  to: string
  label: string
  /** Hidden unless the signed-in user holds one of these. */
  permissions?: string[]
  end?: boolean
}

const NAVIGATION: { section: string; items: NavItem[] }[] = [
  {
    section: 'Operate',
    items: [
      { to: '/', label: 'Dashboard', end: true },
      { to: '/calendar', label: 'Calendar', permissions: ['calendar.view'] },
      { to: '/reservations', label: 'Reservations', permissions: ['reservations.view'] },
      { to: '/inbox', label: 'Inbox', permissions: ['messages.view', 'messages.send'] },
      { to: '/operations', label: 'Operations', permissions: ['tasks.view'] },
    ],
  },
  {
    section: 'Manage',
    items: [
      { to: '/properties', label: 'Properties', permissions: ['properties.view'] },
      { to: '/guests', label: 'Guests', permissions: ['guests.view'] },
      { to: '/owners', label: 'Owners', permissions: ['owners.view'] },
      { to: '/reviews', label: 'Reviews', permissions: ['reviews.view', 'reservations.view'] },
      { to: '/channels', label: 'Channels', permissions: ['channels.view', 'channels.manage'] },
    ],
  },
  {
    section: 'Money',
    items: [
      {
        to: '/financials',
        label: 'Financials',
        permissions: ['payments.view', 'expenses.manage', 'owner_statements.view'],
      },
      { to: '/revenue', label: 'Revenue', permissions: ['revenue.view'] },
      { to: '/reports', label: 'Reports', permissions: ['reports.view'] },
    ],
  },
  {
    section: 'Configure',
    items: [
      // No permission: every member may see the plan they work inside, because
      // somebody who cannot add a property is entitled to know the reason is a
      // cap rather than a fault.
      { to: '/subscription', label: 'Subscription' },
      {
        to: '/settings',
        label: 'Developer',
        permissions: ['api_keys.manage', 'webhooks.manage', 'integrations.view'],
      },
    ],
  },
]

export function AppLayout({ children }: { children: ReactNode }) {
  const { session, organizations, signOut, switchOrganization, canAny } = useAuth()

  return (
    <div className="shell">
      <nav className="sidebar">
        <div className="sidebar__brand">Habitat</div>

        {NAVIGATION.map((group) => {
          const visible = group.items.filter(
            (item) => item.permissions === undefined || canAny(item.permissions),
          )

          if (visible.length === 0) return null

          return (
            <div key={group.section}>
              <div className="sidebar__section">{group.section}</div>
              {visible.map((item) => (
                <NavLink
                  key={item.to}
                  to={item.to}
                  end={item.end}
                  className={({ isActive }) =>
                    isActive ? 'nav-link nav-link--active' : 'nav-link'
                  }
                >
                  {item.label}
                </NavLink>
              ))}
            </div>
          )
        })}

        <div className="sidebar__footer">
          <div className="small strong truncate">{session?.user.name}</div>
          <div className="small faint truncate">{session?.organization.name}</div>

          {/* Shown only to a platform administrator, and it is the only bridge
              between the two interfaces. Everything behind it affects other
              companies, so it is not folded into the navigation above. */}
          {session?.is_platform_admin === true && (
            <NavLink to="/platform" className="btn btn--ghost btn--sm mt-2">
              Platform console →
            </NavLink>
          )}

          <button type="button" className="btn btn--ghost btn--sm mt-1" onClick={() => void signOut()}>
            Sign out
          </button>
        </div>
      </nav>

      <div className="main">
        <header className="topbar">
          <div className="row">
            <strong>{session?.organization.name}</strong>
            <span className="chip chip--slate">{session?.organization.status}</span>
          </div>

          {organizations.length > 1 && (
            <select
              value={session?.organization.id ?? ''}
              onChange={(event) => void switchOrganization(event.target.value)}
              style={{ width: 'auto' }}
              aria-label="Switch organization"
            >
              {organizations.map((organization) => (
                <option key={organization.id} value={organization.id}>
                  {organization.name}
                </option>
              ))}
            </select>
          )}
        </header>

        <main className="content">
          <AnnouncementBanner />
          {children}
        </main>
      </div>
    </div>
  )
}
