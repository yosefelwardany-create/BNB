import { useEffect, useId, useMemo, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { X } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type { TeamInvitation, TeamMember, TeamOptions } from '@/api/types'
import { AccessMatrix } from '@/components/AccessMatrix'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { useAuth } from '@/lib/auth'
import { toast } from '@/lib/toast'

interface TeamResponse {
  data: { members: TeamMember[]; invitations: TeamInvitation[] }
}

interface Invited {
  message: string
  data: TeamInvitation
  meta: { accept_url: string | null }
}

/**
 * The account's team, and what each person in it can see.
 *
 * A person's access is a role, single permissions given or taken away on top
 * of it, and optionally a limit to some properties. The server holds the
 * rules — nobody gives more than they hold, nobody edits themselves, the
 * account keeps somebody who can manage its team — and this screen says them
 * where they apply rather than letting a save be refused afterwards.
 *
 * The same panel serves an account's own team, in Settings, and a client's
 * logins in the platform's Accounts page: `base` is where its API is, so the
 * platform's copy works inside the client chosen there rather than the
 * account the session is in.
 */
export function TeamPanel({
  base = 'team',
  title = 'Your team',
  intro = 'Who can sign in to this account, and what each of them can see. Menus and pages they cannot use are hidden from them.',
  inviteLabel = 'Invite someone',
  rowActions,
  onChanged,
}: {
  base?: string
  title?: string
  intro?: string
  inviteLabel?: string
  /** More buttons on a person's row, from the page the panel sits in. */
  rowActions?: (member: TeamMember) => ReactNode
  /** Told after anything here changed, for a page that shows the same people elsewhere. */
  onChanged?: () => void
} = {}) {
  const { can } = useAuth()
  const queryClient = useQueryClient()

  const team = useQuery({ queryKey: ['team', base], queryFn: () => api.get<TeamResponse>(base) })
  const options = useQuery({
    queryKey: ['team-options', base],
    queryFn: () => api.get<{ data: TeamOptions }>(`${base}/options`),
  })

  const [editing, setEditing] = useState<TeamMember | null>(null)
  const [inviting, setInviting] = useState(false)
  // The link an invitation was just sent with. Shown once: only its hash is
  // kept, and email may never reach the person.
  const [link, setLink] = useState<{ email: string; url: string } | null>(null)

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['team', base] })
    onChanged?.()
  }
  const failed = (error: Error) =>
    toast(error instanceof ApiError ? error.message : 'That could not be changed.', { tone: 'error' })

  const switchMember = useMutation({
    mutationFn: ({ member, on }: { member: TeamMember; on: boolean }) =>
      api.post<{ message: string }>(`${base}/${member.id}/${on ? 'reactivate' : 'deactivate'}`),
    onSuccess: (result) => {
      toast(result.message)
      refresh()
    },
    onError: failed,
  })

  const withdraw = useMutation({
    mutationFn: (invitation: TeamInvitation) => api.delete<{ message: string }>(`${base}/invitations/${invitation.id}`),
    onSuccess: (result) => {
      toast(result.message)
      refresh()
    },
    onError: failed,
  })

  const properties = options.data?.data.properties ?? []
  const members = team.data?.data.members ?? []
  const invitations = team.data?.data.invitations ?? []

  return (
    <>
      {link !== null && (
        <div className="notice notice--warning mb-3">
          <div className="strong">Send {link.email} this link to set up their login</div>
          <div className="small">
            It is shown once and works for 14 days. Email may not reach them, so pass it on yourself — by message
            or however you usually reach them. Click the link to copy it.
          </div>
          <pre className="secret">{link.url}</pre>
          <button type="button" className="btn btn--sm mt-2" onClick={() => setLink(null)}>
            I have sent it
          </button>
        </div>
      )}

      <section className="card mb-3">
        <header className="card__header row row--between">
          <div>
            <h2>{title}</h2>
            <p className="small muted">{intro}</p>
          </div>
          {can('users.invite') && (
            <button
              type="button"
              className="btn btn--primary btn--sm"
              disabled={options.data === undefined}
              onClick={() => setInviting(true)}
            >
              {inviteLabel}
            </button>
          )}
        </header>

        <QueryState
          isLoading={team.isLoading}
          error={team.error}
          isEmpty={members.length === 0}
          emptyTitle="Nobody yet"
          emptyBody="Invite the people who work with you."
        >
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Person</th>
                  <th>Role</th>
                  <th>Properties</th>
                  <th>Status</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {members.map((member) => (
                  <tr key={member.id}>
                    <td>
                      <div className="strong">{member.user.name ?? member.user.email}</div>
                      <div className="small faint">
                        {member.user.email}
                        {member.job_title !== null && member.job_title !== '' && ` · ${member.job_title}`}
                      </div>
                    </td>
                    <td>
                      <div>{member.roles.map((role) => role.name).join(', ') || 'No role'}</div>
                      {(member.allow.length > 0 || member.deny.length > 0) && (
                        <div className="small faint">{adjustments(member.allow.length, member.deny.length)}</div>
                      )}
                    </td>
                    <td className="small">{scope(member.restricted_to_properties, member.property_ids, properties)}</td>
                    <td>
                      {member.is_you ? (
                        <Chip label="You" colour="sky" />
                      ) : member.status === 'active' ? (
                        <Chip label="Active" colour="emerald" />
                      ) : (
                        <Chip label="Switched off" colour="zinc" />
                      )}
                    </td>
                    <td className="row">
                      {!member.is_you && can('users.update') && (
                        <button
                          type="button"
                          className="btn btn--sm"
                          disabled={options.data === undefined}
                          onClick={() => setEditing(member)}
                        >
                          Edit access
                        </button>
                      )}
                      {!member.is_you && can('users.deactivate') && (
                        <button
                          type="button"
                          className="btn btn--ghost btn--sm"
                          disabled={switchMember.isPending}
                          onClick={() => switchMember.mutate({ member, on: member.status !== 'active' })}
                        >
                          {member.status === 'active' ? 'Switch off' : 'Switch on'}
                        </button>
                      )}
                      {rowActions?.(member)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </QueryState>
      </section>

      {invitations.length > 0 && (
        <section className="card">
          <header className="card__header">
            <h2>Waiting to join</h2>
          </header>
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Invited</th>
                  <th>Role</th>
                  <th>Properties</th>
                  <th>Invitation ends</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {invitations.map((invitation) => (
                  <tr key={invitation.id}>
                    <td>
                      <div className="strong">
                        {[invitation.first_name, invitation.last_name].filter(Boolean).join(' ') || invitation.email}
                      </div>
                      <div className="small faint">{invitation.email}</div>
                    </td>
                    <td>{invitation.roles.map((role) => role.name).join(', ')}</td>
                    <td className="small">
                      {scope(
                        invitation.access.restricted_to_properties === true,
                        invitation.access.property_ids ?? [],
                        properties,
                      )}
                    </td>
                    <td className="small faint">
                      {invitation.expires_at !== null ? new Date(invitation.expires_at).toLocaleDateString() : '—'}
                    </td>
                    <td>
                      {can('users.invite') && (
                        <button
                          type="button"
                          className="btn btn--ghost btn--sm"
                          disabled={withdraw.isPending}
                          onClick={() => withdraw.mutate(invitation)}
                        >
                          Withdraw
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {options.data !== undefined && (editing !== null || inviting) && (
        <AccessDialog
          base={base}
          member={editing}
          options={options.data.data}
          onClose={() => {
            setEditing(null)
            setInviting(false)
          }}
          onSaved={(result) => {
            toast(result.message)
            if (result.meta?.accept_url != null) setLink({ email: result.data.email, url: result.meta.accept_url })
            setEditing(null)
            setInviting(false)
            refresh()
          }}
        />
      )}
    </>
  )
}

function adjustments(given: number, taken: number): string {
  return [given > 0 ? `${given} extra` : null, taken > 0 ? `${taken} removed` : null].filter(Boolean).join(', ')
}

function scope(restricted: boolean, ids: string[], properties: { id: string; name: string }[]): string {
  if (!restricted) return 'All properties'

  const names = ids.map((id) => properties.find((property) => property.id === id)?.name ?? 'a property')

  return names.length <= 3 ? `Only ${names.join(', ')}` : `Only ${names.length} properties`
}

/**
 * Inviting somebody, or changing what somebody already here can see.
 *
 * The ticks show what they will hold in the end. Ticking something their role
 * does not include gives it to them; unticking something it does include
 * takes it away; both are said beside the permission, so the role stays the
 * starting point rather than being silently rewritten.
 */
function AccessDialog({
  base,
  member,
  options,
  onClose,
  onSaved,
}: {
  base: string
  member: TeamMember | null
  options: TeamOptions
  onClose: () => void
  onSaved: (result: { message: string; data: TeamInvitation; meta?: Invited['meta'] }) => void
}) {
  const { can } = useAuth()
  const uid = useId()
  const inviting = member === null

  const [email, setEmail] = useState('')
  const [firstName, setFirstName] = useState('')
  const [lastName, setLastName] = useState('')
  const [jobTitle, setJobTitle] = useState(member?.job_title ?? '')
  const [roleId, setRoleId] = useState(member?.roles[0]?.id ?? '')
  const [allow, setAllow] = useState<string[]>(member?.allow ?? [])
  const [deny, setDeny] = useState<string[]>(member?.deny ?? [])
  const [restricted, setRestricted] = useState(member?.restricted_to_properties ?? false)
  const [propertyIds, setPropertyIds] = useState<string[]>(member?.property_ids ?? [])

  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose()
    }

    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const role = options.roles.find((candidate) => candidate.id === roleId)
  const fromRole = useMemo(() => new Set(role?.permissions ?? []), [role])

  const holds = (permission: string) => (fromRole.has(permission) || allow.includes(permission)) && !deny.includes(permission)

  function chooseRole(id: string) {
    const next = new Set(options.roles.find((candidate) => candidate.id === id)?.permissions ?? [])
    setRoleId(id)
    // Kept only where they still mean something against the new role.
    setAllow((current) => current.filter((permission) => !next.has(permission)))
    setDeny((current) => current.filter((permission) => next.has(permission)))
  }

  // Given or taken away against the role, so the role stays the starting
  // point: what it already includes is not given again, and what it lacks is
  // not taken away.
  function toggle(permissions: string[], on: boolean) {
    const fromIt = permissions.filter((permission) => fromRole.has(permission))
    const beyondIt = permissions.filter((permission) => !fromRole.has(permission))

    if (on) {
      setDeny((current) => current.filter((entry) => !permissions.includes(entry)))
      setAllow((current) => [...new Set([...current, ...beyondIt])])
    } else {
      setAllow((current) => current.filter((entry) => !permissions.includes(entry)))
      setDeny((current) => [...new Set([...current, ...fromIt])])
    }
  }

  const save = useMutation({
    mutationFn: () => {
      const access = {
        role_ids: [roleId],
        allow,
        deny,
        restricted_to_properties: restricted,
        property_ids: restricted ? propertyIds : [],
        job_title: jobTitle.trim() === '' ? null : jobTitle.trim(),
      }

      return inviting
        ? api.post<Invited>(`${base}/invitations`, {
            ...access,
            email: email.trim(),
            first_name: firstName.trim() === '' ? null : firstName.trim(),
            last_name: lastName.trim() === '' ? null : lastName.trim(),
          })
        : api.patch<{ message: string; data: TeamInvitation }>(`${base}/${member.id}`, access)
    },
    onSuccess: (result) => onSaved(result),
  })

  const error = save.error instanceof ApiError ? save.error : null
  const fieldErrors = ['email', 'role_ids', 'allow', 'deny', 'property_ids', 'access', 'job_title']
    .map((field) => error?.fieldError(field))
    .filter((message): message is string => message !== undefined)

  const canSave = roleId !== '' && (!inviting || email.trim() !== '') && (!restricted || propertyIds.length > 0)

  return createPortal(
    <div className="dialog-backdrop" role="presentation" onClick={onClose}>
      <div
        className="dialog dialog--wide"
        role="dialog"
        aria-modal="true"
        aria-label={inviting ? 'Invite someone' : 'Edit access'}
        onClick={(event) => event.stopPropagation()}
      >
        <header className="dialog__header row row--between">
          <div>
            <h2>{inviting ? 'Invite someone' : `What ${member.user.name ?? member.user.email} can see`}</h2>
            <p className="small muted">
              {inviting
                ? 'You get a link to send them to set up their login. They see only what you choose here.'
                : 'Changes apply the next time they open a page.'}
            </p>
          </div>
          <button type="button" className="btn btn--ghost btn--sm" onClick={onClose} aria-label="Close">
            <X size={16} aria-hidden />
          </button>
        </header>

        <form
          className="dialog__body stack"
          onSubmit={(event) => {
            event.preventDefault()
            if (canSave) save.mutate()
          }}
        >
          {error !== null && (
            <div className="notice notice--error" role="alert">
              {fieldErrors.length > 0 ? fieldErrors.join(' ') : error.message}
            </div>
          )}

          {inviting && (
            <div className="grid grid--two">
              <div className="field">
                <label className="field__label" htmlFor={`${uid}-email`}>
                  Email
                </label>
                <input id={`${uid}-email`} type="email" value={email} onChange={(event) => setEmail(event.target.value)} />
              </div>
              <div className="field">
                <label className="field__label" htmlFor={`${uid}-first`}>
                  First name
                </label>
                <input id={`${uid}-first`} type="text" value={firstName} onChange={(event) => setFirstName(event.target.value)} />
              </div>
              <div className="field">
                <label className="field__label" htmlFor={`${uid}-last`}>
                  Last name
                </label>
                <input id={`${uid}-last`} type="text" value={lastName} onChange={(event) => setLastName(event.target.value)} />
              </div>
            </div>
          )}

          <div className="grid grid--two">
            <div className="field">
              <label className="field__label" htmlFor={`${uid}-role`}>
                Role
              </label>
              <select id={`${uid}-role`} value={roleId} onChange={(event) => chooseRole(event.target.value)}>
                <option value="">Choose a role…</option>
                {options.roles.map((candidate) => (
                  <option key={candidate.id} value={candidate.id}>
                    {candidate.name}
                  </option>
                ))}
              </select>
              {role?.description != null && <span className="field__hint">{role.description}</span>}
              {member !== null && member.roles.length > 0 && member.roles.every((held) => options.roles.every((candidate) => candidate.id !== held.id)) && (
                <span className="field__hint">
                  They are {member.roles.map((held) => held.name).join(', ')} now, which is set up from the client&rsquo;s
                  account. Choosing a role here moves them into the workspace, seeing what you tick below.
                </span>
              )}
              {member !== null && member.roles.length > 1 && (
                <span className="field__hint">
                  They have {member.roles.length} roles now ({member.roles.map((entry) => entry.name).join(', ')}). Saving
                  keeps only the one chosen here.
                </span>
              )}
            </div>
            <div className="field">
              <label className="field__label" htmlFor={`${uid}-title`}>
                Job title
              </label>
              <input id={`${uid}-title`} type="text" value={jobTitle} onChange={(event) => setJobTitle(event.target.value)} />
            </div>
          </div>

          <fieldset className="field">
            <legend className="field__label">Properties they see</legend>
            <label className="row small">
              <input type="radio" name={`${uid}-scope`} checked={!restricted} onChange={() => setRestricted(false)} />
              All properties
            </label>
            <label className="row small">
              <input type="radio" name={`${uid}-scope`} checked={restricted} onChange={() => setRestricted(true)} />
              Only these
            </label>
            {restricted && (
              <div className="checks">
                {options.properties.map((property) => (
                  <label key={property.id} className="small">
                    <input
                      type="checkbox"
                      checked={propertyIds.includes(property.id)}
                      onChange={(event) =>
                        setPropertyIds((current) =>
                          event.target.checked ? [...current, property.id] : current.filter((id) => id !== property.id),
                        )
                      }
                    />
                    {property.name}
                  </label>
                ))}
              </div>
            )}
          </fieldset>

          {role !== undefined && (
            <div className="field">
              <div className="row row--between">
                <span className="field__label">What they can see and do</span>
                {(allow.length > 0 || deny.length > 0) && (
                  <button
                    type="button"
                    className="btn btn--ghost btn--sm"
                    onClick={() => {
                      setAllow([])
                      setDeny([])
                    }}
                  >
                    Back to the role as it is
                  </button>
                )}
              </div>
              <span className="field__hint">
                The role sets the starting ticks. See shows a feature; Edit lets them change it too. Part-ticked means
                some of it. You can only give what you have yourself.
              </span>

              <AccessMatrix
                areas={options.areas}
                holds={holds}
                canGive={(permission) => can(permission)}
                onChange={toggle}
              />
              {(allow.length > 0 || deny.length > 0) && (
                <span className="field__hint">
                  {adjustments(allow.length, deny.length)} compared with the role.
                </span>
              )}
            </div>
          )}

          <div className="row">
            <button type="submit" className="btn btn--primary" disabled={!canSave || save.isPending}>
              {save.isPending ? 'Saving…' : inviting ? 'Send invitation' : 'Save access'}
            </button>
            <button type="button" className="btn btn--ghost" onClick={onClose}>
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>,
    document.body,
  )
}
