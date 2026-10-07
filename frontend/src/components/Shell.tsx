import { useCallback, useState, type ComponentType, type ReactNode } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { motion } from 'motion/react'
import { ChevronRight, Menu, PanelLeftClose, Search } from 'lucide-react'
import { BrandMark } from '@/components/BrandMark'
import { CommandPalette, type Command } from '@/components/CommandPalette'
import { useCommandPaletteShortcut } from '@/lib/shortcuts'
import { ThemeToggle } from '@/components/ThemeToggle'
import { TopProgress } from '@/components/TopProgress'

/**
 * The frame both interfaces share: a forest sidebar, a glass top bar, the
 * command palette and the page transition. The owner workspace and the client
 * portal each supply their own navigation, header and commands.
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
  /**
   * Shut until somebody opens it.
   *
   * For the sections that are real but secondary. The platform's point is the
   * properties and the agents running them; finances and reports are read on
   * purpose, once a week, and a sidebar that gives them the same weight as the
   * thing people open fifty times a day buries it.
   *
   * Folded, not removed — and it reopens on the section somebody is already
   * inside, so following a link into a report never leaves them looking at a
   * closed drawer.
   */
  foldedByDefault?: boolean
}

const COLLAPSE_KEY = 'habitat.sidebar'
const FOLDED_KEY = 'habitat.sidebar.folded'

/**
 * The sections this person has folded or unfolded by hand.
 *
 * Overrides only — never a snapshot of every section's state. The navigation is
 * built from the permissions on the session, so at first render it holds only
 * the sections that need none; a map captured then would be missing "Money"
 * entirely, and a section absent from the map reads as open. The section's own
 * default is applied at render instead, where the section is known.
 *
 * Per browser rather than per account: it is a preference about one screen on
 * one machine, and syncing it would let somebody's laptop decide what their
 * phone looks like.
 */
function readFolded(): Record<string, boolean> {
  try {
    const stored = localStorage.getItem(FOLDED_KEY)

    return stored === null ? {} : (JSON.parse(stored) as Record<string, boolean>)
  } catch {
    // Private windows, cleared site data, blocked storage. The defaults stand
    // on their own; nothing here is worth failing a render over.
    return {}
  }
}

function readCollapsed(): boolean {
  try {
    return localStorage.getItem(COLLAPSE_KEY) === 'collapsed'
  } catch {
    return false
  }
}

export function Shell({
  brandSub,
  account,
  groups,
  footer,
  header,
  commands,
  children,
}: {
  brandSub?: string
  /**
   * Which account the workspace is scoped to, shown under the brand so it is
   * the first thing read on every screen. The owner's layout supplies the
   * account selector here; the client's supplies nothing.
   */
  account?: ReactNode
  groups: ShellNavGroup[]
  footer: ReactNode
  header: ReactNode
  commands: Command[]
  children: ReactNode
}) {
  const location = useLocation()
  const [collapsed, setCollapsed] = useState(readCollapsed)
  const [folded, setFolded] = useState(readFolded)

  /**
   * Fold or unfold a section.
   *
   * Takes what the section is doing right now rather than deriving it, because
   * the stored map holds overrides and not every section's state: reading
   * `folded[section] ?? false` here would treat a section that is shut by
   * default as open, and the first click would appear to do nothing.
   */
  function toggleSection(section: string, isOpen: boolean) {
    setFolded((current) => {
      const next = { ...current, [section]: isOpen }

      try {
        localStorage.setItem(FOLDED_KEY, JSON.stringify(next))
      } catch {
        // See readFolded: a preference that cannot be stored still applies for
        // this visit.
      }

      return next
    })
  }
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

        {account}

        {groups.map((group) => {
          // Never folded over the page somebody is on: a section that hides the
          // link they just followed reads as the sidebar losing its place.
          const holdsCurrent = group.items.some((item) => item.to === current?.to)
          // A choice this person made wins; otherwise the section's own default.
          const open = holdsCurrent || !(folded[group.section] ?? group.foldedByDefault === true)

          return (
            <div key={group.section}>
              <button
                type="button"
                className="sidebar__section sidebar__section--toggle"
                onClick={() => toggleSection(group.section, open)}
                aria-expanded={open}
                aria-controls={`nav-${group.section}`}
              >
                <ChevronRight
                  size={12}
                  aria-hidden
                  className={open ? 'sidebar__chevron sidebar__chevron--open' : 'sidebar__chevron'}
                />
                {group.section}
              </button>

              {/* Not rendered rather than hidden with an attribute. A link
                  somebody cannot see but can still tab to is worse than one
                  that is folded away, and `hidden` is honoured inconsistently
                  enough that "is it reachable" stops being a question with one
                  answer. The command palette is unaffected — it is built from
                  the navigation data rather than from the DOM — so every page
                  stays one keystroke away however this is folded. */}
              {open && (
                <div id={`nav-${group.section}`}>
                  {group.items.map((item) => (
                    <SidebarLink key={item.to} item={item} collapsed={collapsed} />
                  ))}
                </div>
              )}
            </div>
          )
        })}

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
        <header className="topbar">
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
