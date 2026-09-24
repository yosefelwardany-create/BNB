import type { ReactNode } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '@/lib/auth'

const NAVIGATION = [
  { to: '/platform', label: 'Overview', end: true },
  { to: '/platform/tenants', label: 'Tenants' },
  { to: '/platform/plans', label: 'Plans' },
  { to: '/platform/people', label: 'People' },
  { to: '/platform/announcements', label: 'Announcements' },
  { to: '/platform/health', label: 'Health' },
  { to: '/platform/sessions', label: 'Support sessions' },
  { to: '/platform/audit', label: 'Audit' },
  { to: '/platform/settings', label: 'Settings' },
]

/**
 * The platform console's shell.
 *
 * Deliberately a different colour from the tenant interface, and it says whose
 * console it is in the header. That is not decoration: an operator with both
 * open in adjacent tabs needs to know at a glance which one they are typing
 * into, because the actions here affect somebody else's business.
 *
 * "Back to my organizations" is always present, because an operator is usually
 * also a normal user of the product and should never have to guess how to
 * return to it.
 */
export function PlatformLayout({ children }: { children: ReactNode }) {
  const { session, signOut } = useAuth()
  const navigate = useNavigate()

  return (
    <div className="shell shell--platform">
      <nav className="sidebar">
        <div className="sidebar__brand">
          Habitat
          <div className="sidebar__brand-sub">Platform</div>
        </div>

        <div>
          <div className="sidebar__section">Operate</div>
          {NAVIGATION.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) => (isActive ? 'nav-link nav-link--active' : 'nav-link')}
            >
              {item.label}
            </NavLink>
          ))}
        </div>

        <div className="sidebar__footer">
          <div className="small strong truncate">{session?.user.name}</div>
          <div className="small faint">Platform administrator</div>

          <button
            type="button"
            className="btn btn--ghost btn--sm mt-2"
            onClick={() => navigate('/')}
          >
            ← Back to my organizations
          </button>

          <button
            type="button"
            className="btn btn--ghost btn--sm mt-1"
            onClick={() => void signOut()}
          >
            Sign out
          </button>
        </div>
      </nav>

      <div className="main">
        <header className="topbar topbar--platform">
          <div className="row">
            <strong>Platform console</strong>
            {/* Said out loud, on every page. Everything in here is somebody
                else's business. */}
            <span className="chip chip--rose">Affects every customer</span>
          </div>
        </header>

        <main className="content">{children}</main>
      </div>
    </div>
  )
}
