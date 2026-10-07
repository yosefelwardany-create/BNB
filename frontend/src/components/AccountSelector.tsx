import { useNavigate } from 'react-router-dom'
import { Landmark } from 'lucide-react'
import { useAuth } from '@/lib/auth'
import { toast } from '@/lib/toast'

const STATUS_COLOURS: Record<string, string> = {
  active: 'emerald',
  trial: 'sky',
  suspended: 'rose',
  cancelled: 'slate',
}

/**
 * Which client account the owner is working in.
 *
 * Everything in the workspace is scoped to one account at a time, and this is
 * the only place that changes which one. Switching stores the new account id,
 * reloads the session (which unmounts the whole tree while it loads), gives
 * the query cache up for a fresh one, and returns to the dashboard so that no
 * URL carries an id from the previous account.
 *
 * Nothing here signs in as the client. The owner stays authenticated as
 * themselves; the server authorises every request against the account named
 * in the header.
 */
export function AccountSelector({ compact = false }: { compact?: boolean }) {
  const { session, organizations, switchOrganization } = useAuth()
  const navigate = useNavigate()

  if (session?.is_platform_admin !== true || organizations.length === 0) {
    return null
  }

  const current = session.organization

  return (
    <div className={compact ? 'account-switch account-switch--compact' : 'account-switch'}>
      {!compact && (
        <div className="account-switch__label">
          <Landmark size={13} aria-hidden /> Managing
        </div>
      )}

      <select
        className="account-switch__select"
        aria-label="Switch account"
        value={current?.id ?? ''}
        onChange={(event) => {
          const next = organizations.find((organization) => organization.id === event.target.value)

          if (next === undefined || next.id === current?.id) return

          void switchOrganization(next.id).then(() => {
            // A path from the previous account may carry its ids.
            void navigate('/')
            toast(`Now managing ${next.name}`)
          })
        }}
      >
        {current === null && <option value="">Choose an account…</option>}
        {organizations.map((organization) => (
          <option key={organization.id} value={organization.id}>
            {organization.name}
            {organization.status !== 'active' ? ` (${organization.status})` : ''}
          </option>
        ))}
      </select>

      {!compact && current !== null && (
        <span className={`chip chip--${STATUS_COLOURS[current.status] ?? 'slate'}`}>{current.status}</span>
      )}
    </div>
  )
}
