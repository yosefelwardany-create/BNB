import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, Bot, CheckCheck, CircleHelp, TriangleAlert, X } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { api } from '@/api/client'
import type { AppNotification, Paginated } from '@/api/types'
import { useAuth } from '@/lib/auth'

/**
 * What happened while you were not looking.
 *
 * Notifications have been written to a table and served by an API for as long
 * as this platform has existed, and nothing has ever displayed them — so an
 * agent could answer a guest, decline to, or fail outright, and the only record
 * was a row somebody would have to go looking for. An inbox that had been
 * handled and one that had been ignored looked identical.
 *
 * Two decisions worth stating:
 *
 *  - **An unread count is not a badge for its own sake.** It is only ever shown
 *    when something is actually unread, because a control that is always
 *    decorated stops being read.
 *  - **Clicking one takes you to the thing.** A notification you cannot act on
 *    is half a notification, and the ones that matter most here — the agent was
 *    not sure how to answer somebody — are useless without the conversation
 *    they came from.
 */
const ICONS: Record<string, typeof Bell> = {
  'agent.replied': Bot,
  'agent.held': CircleHelp,
  'agent.failed': TriangleAlert,
}

export function NotificationBell() {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const panel = useRef<HTMLDivElement | null>(null)

  const signedIn = session !== null

  const unread = useQuery({
    queryKey: ['notifications', 'unread'],
    queryFn: () => api.get<{ data: { unread: number } }>('notifications/unread-count'),
    enabled: signedIn,
    // Polled rather than pushed. A minute is slower than a websocket and
    // enormously simpler, and nothing here is urgent to the second.
    refetchInterval: 60_000,
  })

  const list = useQuery({
    queryKey: ['notifications', 'list'],
    queryFn: () => api.get<Paginated<AppNotification>>('notifications', { per_page: 15 }),
    // Only once it is opened: most sessions never look.
    enabled: signedIn && open,
  })

  const markRead = useMutation({
    mutationFn: (id: string) => api.post(`notifications/${id}/read`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  })

  const markAllRead = useMutation({
    mutationFn: () => api.post('notifications/read-all'),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  })

  useEffect(() => {
    if (!open) return

    function dismiss(event: MouseEvent) {
      if (panel.current !== null && !panel.current.contains(event.target as Node)) setOpen(false)
    }

    function escape(event: KeyboardEvent) {
      if (event.key === 'Escape') setOpen(false)
    }

    document.addEventListener('mousedown', dismiss)
    document.addEventListener('keydown', escape)

    return () => {
      document.removeEventListener('mousedown', dismiss)
      document.removeEventListener('keydown', escape)
    }
  }, [open])

  if (!signedIn) return null

  const count = unread.data?.data.unread ?? 0
  const items = list.data?.data ?? []

  function openNotification(notification: AppNotification) {
    if (!notification.is_read) markRead.mutate(notification.id)
    setOpen(false)

    if (notification.url !== null) void navigate(notification.url)
  }

  return (
    <div className="bell" ref={panel}>
      <button
        type="button"
        className="icon-btn"
        onClick={() => setOpen((current) => !current)}
        aria-label={count > 0 ? `Notifications — ${count} unread` : 'Notifications'}
        aria-expanded={open}
      >
        <Bell size={18} aria-hidden />
        {count > 0 && <span className="bell__count">{count > 99 ? '99+' : count}</span>}
      </button>

      {open && (
        <div className="bell__panel" role="dialog" aria-label="Notifications">
          <header className="bell__header row row--between">
            <strong>Notifications</strong>
            <div className="row">
              {count > 0 && (
                <button
                  type="button"
                  className="btn btn--ghost btn--sm"
                  onClick={() => markAllRead.mutate()}
                  disabled={markAllRead.isPending}
                >
                  <CheckCheck size={14} aria-hidden /> Mark all read
                </button>
              )}
              <button
                type="button"
                className="icon-btn"
                onClick={() => setOpen(false)}
                aria-label="Close notifications"
              >
                <X size={16} aria-hidden />
              </button>
            </div>
          </header>

          <div className="bell__list">
            {list.isLoading && <p className="small faint bell__empty">Loading…</p>}

            {!list.isLoading && items.length === 0 && (
              <div className="bell__empty">
                <p className="small faint">Nothing yet.</p>
                <p className="small faint">
                  Your agents will tell you here when they answer a guest on their own, and
                  when they are not sure what to say.
                </p>
              </div>
            )}

            {items.map((notification) => {
              const Icon = ICONS[notification.type] ?? Bell

              return (
                <button
                  key={notification.id}
                  type="button"
                  className={`bell__item${notification.is_read ? '' : ' bell__item--unread'}`}
                  onClick={() => openNotification(notification)}
                >
                  <Icon size={16} aria-hidden className="bell__icon" />
                  <span className="bell__text">
                    <span className="bell__title">{notification.title}</span>
                    {notification.body !== null && (
                      <span className="bell__body">{notification.body}</span>
                    )}
                  </span>
                </button>
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}
