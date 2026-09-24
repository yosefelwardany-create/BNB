import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { TenantAnnouncement } from '@/api/types'

/**
 * Notices the platform has published to this organization.
 *
 * Dismissals are kept in this browser only, and deliberately: a notice that one
 * person dismissed for the whole company is a notice nobody else ever saw. A
 * critical one cannot be dismissed at all, because the point of marking it
 * critical is that everybody reads it.
 *
 * Failures are silent. A banner is not worth an error message on every screen,
 * and a platform whose announcement endpoint is down should not make the product
 * look broken.
 */
export function AnnouncementBanner() {
  const [dismissed, setDismissed] = useState<string[]>(() => {
    try {
      return JSON.parse(localStorage.getItem('habitat.dismissed') ?? '[]') as string[]
    } catch {
      return []
    }
  })

  const announcements = useQuery({
    queryKey: ['tenant-announcements'],
    queryFn: () =>
      api.get<{ data: TenantAnnouncement[]; meta: { maintenance_notice: string | null } }>(
        'organization/announcements',
      ),
    // Checked once a session rather than on every navigation: a notice is not
    // live data and polling it on every screen change is noise.
    staleTime: 10 * 60 * 1000,
    retry: false,
  })

  function dismiss(id: string): void {
    const next = [...dismissed, id]

    setDismissed(next)

    try {
      localStorage.setItem('habitat.dismissed', JSON.stringify(next))
    } catch {
      // A browser refusing storage is not a reason to leave the notice up.
    }
  }

  const notice = announcements.data?.meta.maintenance_notice
  const visible = (announcements.data?.data ?? []).filter(
    (announcement) => !dismissed.includes(announcement.id),
  )

  if (notice === null && visible.length === 0) return null

  return (
    <>
      {notice !== null && notice !== undefined && notice !== '' && (
        <div className="notice notice--warning">{notice}</div>
      )}

      {visible.map((announcement) => (
        <div
          key={announcement.id}
          className={
            announcement.level === 'critical'
              ? 'notice notice--error'
              : announcement.level === 'warning'
                ? 'notice notice--warning'
                : 'notice notice--info'
          }
          role={announcement.level === 'critical' ? 'alert' : undefined}
        >
          <div className="row row--between">
            <strong>{announcement.title}</strong>

            {announcement.is_dismissible && (
              <button
                type="button"
                className="btn btn--ghost btn--sm"
                onClick={() => dismiss(announcement.id)}
                aria-label="Dismiss"
              >
                Dismiss
              </button>
            )}
          </div>

          <div className="small wrap">{announcement.body}</div>
        </div>
      ))}
    </>
  )
}
