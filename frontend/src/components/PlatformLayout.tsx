import type { ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Activity,
  ArrowLeft,
  Building,
  Gauge,
  Headset,
  Layers,
  LogOut,
  Megaphone,
  Moon,
  ScrollText,
  Settings,
  UsersRound,
} from 'lucide-react'
import { useAuth } from '@/lib/auth'
import { toggleTheme } from '@/lib/theme'
import type { Command } from '@/components/CommandPalette'
import { Shell, type ShellNavItem } from '@/components/Shell'
import { initials } from '@/lib/format'

const NAVIGATION: (ShellNavItem & { keywords?: string })[] = [
  { to: '/platform', label: 'Overview', icon: Gauge, end: true, keywords: 'dashboard growth' },
  { to: '/platform/tenants', label: 'Tenants', icon: Building, keywords: 'organizations customers' },
  { to: '/platform/plans', label: 'Plans', icon: Layers, keywords: 'pricing limits subscription' },
  { to: '/platform/people', label: 'People', icon: UsersRound, keywords: 'users accounts' },
  { to: '/platform/announcements', label: 'Announcements', icon: Megaphone, keywords: 'notices banner' },
  { to: '/platform/health', label: 'Health', icon: Activity, keywords: 'providers status uptime' },
  { to: '/platform/sessions', label: 'Support sessions', icon: Headset, keywords: 'impersonate support' },
  { to: '/platform/audit', label: 'Audit', icon: ScrollText, keywords: 'log history' },
  { to: '/platform/settings', label: 'Settings', icon: Settings, keywords: 'configuration' },
]

/**
 * The platform console's shell.
 *
 * Branded like the tenant interface, but marked in rose throughout — a warning
 * thread down the sidebar and across the top bar — and it says whose console
 * it is in the header. That is not decoration: an operator with both open in
 * adjacent tabs needs to know at a glance which one they are typing into,
 * because the actions here affect somebody else's business.
 *
 * "Back to my organizations" is always present, because an operator is usually
 * also a normal user of the product and should never have to guess how to
 * return to it.
 */
export function PlatformLayout({ children }: { children: ReactNode }) {
  const { session, signOut } = useAuth()
  const navigate = useNavigate()

  const commands: Command[] = [
    ...NAVIGATION.map((item) => ({
      id: `nav:${item.to}`,
      label: item.label,
      group: 'Platform',
      icon: item.icon,
      keywords: item.keywords,
      run: () => void navigate(item.to),
    })),
    {
      id: 'back',
      label: 'Back to my organizations',
      group: 'Account',
      icon: ArrowLeft,
      keywords: 'tenant exit leave',
      run: () => void navigate('/'),
    },
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
      variant="platform"
      brandSub="Platform"
      groups={[{ section: 'Operate', items: NAVIGATION }]}
      commands={commands}
      header={
        <div className="topbar__org">
          <strong>Platform console</strong>
          {/* Said out loud, on every page. Everything in here is somebody
              else's business. */}
          <span className="chip chip--rose">Affects every customer</span>
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
              <div className="small faint">Platform administrator</div>
            </div>
          </div>

          <button type="button" className="btn btn--ghost btn--sm" onClick={() => void navigate('/')}>
            <ArrowLeft size={16} className="nav-link__icon" aria-hidden />
            <span className="btn__label">Back to my organizations</span>
          </button>

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
