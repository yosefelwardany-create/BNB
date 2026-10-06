import type { ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { CalendarDays, Gauge, LogOut, Moon, Receipt, Users } from 'lucide-react'
import { useAuth } from '@/lib/auth'
import { toggleTheme } from '@/lib/theme'
import type { Command } from '@/components/CommandPalette'
import { Shell, type ShellNavItem } from '@/components/Shell'
import { initials } from '@/lib/format'

const NAVIGATION: (ShellNavItem & { keywords?: string })[] = [
  { to: '/', label: 'Overview', icon: Gauge, end: true, keywords: 'summary performance occupancy' },
  { to: '/calendar', label: 'Calendar', icon: CalendarDays, keywords: 'availability booked nights' },
  { to: '/stays', label: 'Stays', icon: Users, keywords: 'bookings arrivals guests upcoming' },
  { to: '/money', label: 'Money', icon: Receipt, keywords: 'statements payouts paid balance' },
]

/**
 * The shell a property owner sees.
 *
 * Four screens and no settings. An owner is a client of the management
 * company, not a member of it: there is nothing here to configure, nobody to
 * invite, no channel to connect. The absence is the design — a sidebar full of
 * controls that all refuse would be worse than one that offers only what works.
 *
 * Deliberately *not* marked like the platform console was. That shell shouted
 * because its actions touched somebody else's business; this one is simply
 * somebody's own portfolio, and dressing it in warnings would be theatre.
 */
export function OwnerLayout({ children }: { children: ReactNode }) {
  const { session, signOut } = useAuth()
  const navigate = useNavigate()

  const commands: Command[] = [
    ...NAVIGATION.map((item) => ({
      id: `nav:${item.to}`,
      label: item.label,
      group: 'Portfolio',
      icon: item.icon,
      keywords: item.keywords,
      run: () => void navigate(item.to),
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

  return (
    <Shell
      brandSub="Owner"
      groups={[{ section: 'My portfolio', items: NAVIGATION }]}
      commands={commands}
      header={
        <div className="topbar__org">
          {/* The managing company's name, not the owner's. They are looking at
              their own properties through their manager's system, and saying
              whose system it is answers "who do I call" without a support page. */}
          <strong>{session?.organization?.name ?? 'My portfolio'}</strong>
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
              <div className="small faint">Property owner</div>
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
