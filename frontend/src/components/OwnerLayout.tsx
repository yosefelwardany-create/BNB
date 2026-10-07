import type { ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { Building2, CalendarDays, Gauge, LogOut, Moon, Receipt, SlidersHorizontal } from 'lucide-react'
import { useAuth } from '@/lib/auth'
import { toggleTheme } from '@/lib/theme'
import type { Command } from '@/components/CommandPalette'
import { Shell, type ShellNavItem } from '@/components/Shell'
import { AccountSelector } from '@/components/AccountSelector'
import { ViewSwitch } from '@/components/ViewSwitch'
import { setClientView } from '@/lib/clientView'
import { initials } from '@/lib/format'

const NAVIGATION: (ShellNavItem & { keywords?: string })[] = [
  { to: '/', label: 'Overview', icon: Gauge, end: true, keywords: 'summary performance occupancy revenue' },
  { to: '/properties', label: 'Properties', icon: Building2, keywords: 'homes listings photos details' },
  { to: '/calendar', label: 'Calendar', icon: CalendarDays, keywords: 'availability booked nights' },
  { to: '/money', label: 'Money', icon: Receipt, keywords: 'revenue commission statements payouts' },
]

/**
 * The shell a client sees.
 *
 * Four read-only screens and no settings. A client is a customer of the
 * management company, not a member of it: there is nothing here to configure,
 * nobody to invite, no channel to connect. The absence is the design — a
 * sidebar full of controls that all refuse would be worse than one that offers
 * only what works.
 *
 * Deliberately no "Stays" screen. The client reads their properties, their
 * calendar and their money; who is staying is the management company's
 * business with the guest.
 *
 * With `preview`, the platform owner is looking at a client account through
 * these same screens: the account selector stays, the top bar says whose view
 * it is, and the switch takes them back to managing.
 */
export function OwnerLayout({ children, preview = false }: { children: ReactNode; preview?: boolean }) {
  const { session, signOut } = useAuth()
  const navigate = useNavigate()

  const commands: Command[] = [
    ...(preview
      ? [
          {
            id: 'managing-view',
            label: 'Back to managing this account',
            group: 'Client view',
            icon: SlidersHorizontal,
            keywords: 'manage workspace operations exit client view',
            run: () => {
              setClientView(false)
              void navigate('/')
            },
          },
        ]
      : []),
    ...NAVIGATION.map((item) => ({
      id: `nav:${item.to}`,
      label: item.label,
      group: 'My properties',
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
      brandSub={preview ? 'Client view' : 'Client'}
      account={preview ? <AccountSelector /> : undefined}
      groups={[{ section: 'My properties', items: NAVIGATION }]}
      commands={commands}
      header={
        <div className="topbar__org">
          {preview && <span className="small faint">Client view of</span>}
          {/* The client's own account name. */}
          <strong>{session?.organization?.name ?? 'My properties'}</strong>
          {preview && (
            <>
              <span className="chip chip--slate">read-only</span>
              <ViewSwitch />
            </>
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
              <div className="small faint">{preview ? 'Platform owner' : 'Client'}</div>
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
