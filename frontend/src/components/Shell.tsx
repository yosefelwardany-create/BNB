import { useCallback, useState, type ComponentType, type ReactNode } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { motion } from 'motion/react'
import { Menu, PanelLeftClose, Search } from 'lucide-react'
import { BrandMark } from '@/components/BrandMark'
import { CommandPalette, type Command } from '@/components/CommandPalette'
import { useCommandPaletteShortcut } from '@/lib/shortcuts'
import { ThemeToggle } from '@/components/ThemeToggle'
import { TopProgress } from '@/components/TopProgress'

/**
 * The frame both interfaces share: a forest sidebar, a glass top bar, the
 * command palette and the page transition. The tenant interface and the
 * platform console each supply their own navigation, header and commands.
 */

export interface ShellNavItem {
  to: string
  label: string
  icon: ComponentType<{ size?: number; className?: string; 'aria-hidden'?: boolean }>
  end?: boolean
}

export interface ShellNavGroup {
  section: string
  items: ShellNavItem[]
}

const COLLAPSE_KEY = 'habitat.sidebar'

function readCollapsed(): boolean {
  try {
    return localStorage.getItem(COLLAPSE_KEY) === 'collapsed'
  } catch {
    return false
  }
}

export function Shell({
  variant = 'tenant',
  brandSub,
  groups,
  footer,
  header,
  commands,
  children,
}: {
  variant?: 'tenant' | 'platform'
  brandSub?: string
  groups: ShellNavGroup[]
  footer: ReactNode
  header: ReactNode
  commands: Command[]
  children: ReactNode
}) {
  const location = useLocation()
  const [collapsed, setCollapsed] = useState(readCollapsed)
  const [navOpen, setNavOpen] = useState(false)
  const [paletteOpen, setPaletteOpen] = useState(false)

  const openPalette = useCallback(() => setPaletteOpen(true), [])
  useCommandPaletteShortcut(openPalette)

  // A drawer left open over the page after following one of its links is a
  // drawer the user then has to close by hand.
  const [drawerPath, setDrawerPath] = useState(location.pathname)
  if (drawerPath !== location.pathname) {
    setDrawerPath(location.pathname)
    setNavOpen(false)
  }

  function toggleCollapsed() {
    setCollapsed((value) => {
      try {
        localStorage.setItem(COLLAPSE_KEY, value ? 'expanded' : 'collapsed')
      } catch {
        // Remembered for this visit only.
      }
      return !value
    })
  }

  const current = groups
    .flatMap((group) => group.items)
    .filter((item) =>
      item.end === true
        ? location.pathname === item.to
        : location.pathname === item.to || location.pathname.startsWith(`${item.to}/`),
    )
    .sort((a, b) => b.to.length - a.to.length)[0]

  const classes = ['shell']
  if (variant === 'platform') classes.push('shell--platform')
  if (collapsed) classes.push('shell--collapsed')
  if (navOpen) classes.push('shell--nav-open')

  const allCommands: Command[] = [
    ...commands,
    {
      id: 'shell:collapse',
      label: collapsed ? 'Expand the sidebar' : 'Collapse the sidebar',
      group: 'Interface',
      icon: PanelLeftClose,
      keywords: 'sidebar menu navigation',
      run: toggleCollapsed,
    },
  ]

  return (
    <div className={classes.join(' ')}>
      <TopProgress />

      <nav className="sidebar" aria-label="Main">
        <div className="sidebar__brand">
          <BrandMark />
          <div className="sidebar__brand-text">
            Habitat
            {brandSub !== undefined && <div className="sidebar__brand-sub">{brandSub}</div>}
          </div>
        </div>

        {groups.map((group) => (
          <div key={group.section}>
            <div className="sidebar__section">{group.section}</div>
            {group.items.map((item) => (
              <SidebarLink key={item.to} item={item} collapsed={collapsed} />
            ))}
          </div>
        ))}

        <div className="sidebar__footer">
          {footer}

          <button
            type="button"
            className="btn btn--ghost btn--sm sidebar__collapse"
            onClick={toggleCollapsed}
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            aria-expanded={!collapsed}
          >
            <PanelLeftClose size={16} className="nav-link__icon" aria-hidden />
            <span className="btn__label">Collapse</span>
          </button>
        </div>
      </nav>

      <div className="sidebar-scrim" onClick={() => setNavOpen(false)} aria-hidden="true" />

      <div className="main">
        <header className={variant === 'platform' ? 'topbar topbar--platform' : 'topbar'}>
          <div className="topbar__left">
            <button
              type="button"
              className="icon-btn topbar__menu"
              onClick={() => setNavOpen(true)}
              aria-label="Open navigation"
            >
              <Menu size={18} />
            </button>
            {header}
            {current !== undefined && (
              <span className="topbar__page" aria-hidden="true">
                / {current.label}
              </span>
            )}
          </div>

          <div className="topbar__right" id="topbar-actions">
            <button type="button" className="search-trigger" onClick={openPalette}>
              <Search size={16} aria-hidden="true" />
              <span className="search-trigger__label">Search or jump to…</span>
              <span className="search-trigger__keys" aria-hidden="true">
                <kbd>⌘</kbd>
                <kbd>K</kbd>
              </span>
              <span className="sr-only">Open the command palette</span>
            </button>
            <ThemeToggle />
          </div>
        </header>

        <main className="content">
          <div className="page" key={location.pathname}>
            {children}
          </div>
        </main>
      </div>

      <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} commands={allCommands} />
    </div>
  )
}

function SidebarLink({ item, collapsed }: { item: ShellNavItem; collapsed: boolean }) {
  const Icon = item.icon

  return (
    <NavLink
      to={item.to}
      end={item.end}
      title={collapsed ? item.label : undefined}
      className={({ isActive }) => (isActive ? 'nav-link nav-link--active' : 'nav-link')}
    >
      {({ isActive }) => (
        <>
          {isActive && (
            <motion.span
              layoutId="nav-pill"
              className="nav-link__pill"
              transition={{ type: 'spring', stiffness: 520, damping: 40 }}
            />
          )}
          <Icon size={18} className="nav-link__icon" aria-hidden />
          <span className="nav-link__label">{item.label}</span>
        </>
      )}
    </NavLink>
  )
}
