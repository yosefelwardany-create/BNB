import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { ArrowRight, Copy, KeyRound, Mail, Plus, ShieldCheck, Trash2, TriangleAlert } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type {
  Paginated,
  PlatformAuditRow,
  PlatformTenant,
  PlatformUser,
  TenantDetailMeta,
} from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { RecordDialog } from '@/components/RecordDialog'
import type { FieldSpec, RecordValues } from '@/components/RecordDialog'
import { useAuth } from '@/lib/auth'
import { formatDate, formatDateTime } from '@/lib/format'
import { toast } from '@/lib/toast'

const CURRENCIES = [
  'AED', 'AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EGP', 'EUR',
  'GBP', 'HKD', 'HUF', 'IDR', 'ILS', 'INR', 'JPY', 'KRW', 'MAD', 'MXN',
  'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'QAR', 'RON', 'SAR', 'SEK', 'SGD',
  'THB', 'TRY', 'USD', 'VND', 'ZAR',
]

const CLIENT_FIELDS: FieldSpec[] = [
  { name: 'organization_name', label: 'Client name', type: 'text', required: true, hint: 'How the account appears in the sidebar and to the client.' },
  { name: 'legal_name', label: 'Legal name', type: 'text' },
  {
    name: 'base_currency',
    label: 'Base currency',
    type: 'select',
    required: true,
    options: CURRENCIES.map((code) => ({ value: code, label: code })),
    hint: 'Statements and the management commission are calculated per currency; this is the account’s default.',
  },
  { name: 'timezone', label: 'Timezone', type: 'text', required: true, placeholder: 'Europe/Lisbon' },
  { name: 'country_code', label: 'Country', type: 'text', placeholder: 'PT', hint: 'Two-letter code.' },
  { name: 'first_name', label: 'Contact first name', type: 'text', required: true },
  { name: 'last_name', label: 'Contact last name', type: 'text' },
  { name: 'email', label: 'Contact email', type: 'email', required: true, hint: 'The client signs in with this address. An invitation to set a password is sent to it.' },
  { name: 'phone', label: 'Phone', type: 'tel' },
]

const REASON_FIELDS: FieldSpec[] = [
  { name: 'reason', label: 'Reason', type: 'textarea', required: true, rows: 3, hint: 'Recorded in the account’s audit trail.' },
]

const RESET_FIELDS: FieldSpec[] = [
  {
    name: 'method',
    label: 'How',
    type: 'select',
    required: true,
    options: [
      { value: 'generate', label: 'Set a new password and show it to me once' },
      { value: 'link', label: 'Email a reset link to the login' },
    ],
    hint: 'Either way, every signed-in session of this login ends.',
  },
  ...REASON_FIELDS,
]

function deleteFields(name: string): FieldSpec[] {
  return [
    {
      name: 'confirm_name',
      label: 'Account name',
      type: 'text',
      required: true,
      placeholder: name,
      hint: `Type ${name} exactly to confirm.`,
    },
    ...REASON_FIELDS,
  ]
}

const STATUS_COLOURS: Record<string, string> = {
  active: 'emerald',
  trial: 'sky',
  past_due: 'amber',
  suspended: 'rose',
  cancelled: 'slate',
}

type Tab = 'clients' | 'administrators' | 'audit'

type Lifecycle = 'suspend' | 'reinstate' | 'cancel'

interface ClientUser {
  membership_id: string
  user_id: string
  name: string | null
  email: string | null
  status: string
  job_title: string | null
  roles: string[]
  /** The account's read-only client login, as opposed to a leftover staff login. */
  is_client: boolean
  is_platform_admin: boolean
  last_login_at: string | null
}

/**
 * Accounts: the administration a managed service still needs.
 *
 * Creating a client account (with its account holder, its 10% management
 * agreement and the client's sign-in), suspending and reinstating one, the
 * platform's private notes, who may sign in to it, who else owns the
 * platform, and the record of what the platform did. This is what remains of
 * the old console now that plans, trials and support sessions are gone.
 *
 * "Open" switches the workspace to the account. It does not sign in as the
 * client: the owner's own session is used and the server scopes every request
 * to the account named in the header.
 */
export function AccountsPage() {
  const [tab, setTab] = useState<Tab>('clients')

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Accounts</h1>
          <div className="page-header__subtitle">
            Client accounts, who may sign in to them, and the platform&rsquo;s own administrators
          </div>
        </div>
      </div>

      <div className="segmented mb-3" role="tablist">
        {(['clients', 'administrators', 'audit'] as Tab[]).map((candidate) => (
          <button
            key={candidate}
            type="button"
            role="tab"
            aria-selected={tab === candidate}
            className={tab === candidate ? 'segmented__option segmented__option--active' : 'segmented__option'}
            onClick={() => setTab(candidate)}
          >
            {tab === candidate && <span className="segmented__thumb" aria-hidden />}
            {candidate === 'clients' ? 'Clients' : candidate === 'administrators' ? 'Platform administrators' : 'Audit'}
          </button>
        ))}
      </div>

      {tab === 'clients' && <Clients />}
      {tab === 'administrators' && <Administrators />}
      {tab === 'audit' && <Audit />}
    </>
  )
}

function Clients() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { session, switchOrganization } = useAuth()

  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const [lifecycle, setLifecycle] = useState<Lifecycle | null>(null)

  const list = useQuery({
    queryKey: ['platform-organizations', { search, status, page }],
    queryFn: () =>
      api.get<Paginated<PlatformTenant>>('platform/organizations', {
        search: search || undefined,
        status: status || undefined,
        page,
        per_page: 25,
      }),
    placeholderData: keepPreviousData,
  })

  const detail = useQuery({
    queryKey: ['platform-organization', selectedId],
    queryFn: () =>
      api.get<{ data: PlatformTenant; meta: TenantDetailMeta }>(`platform/organizations/${selectedId}`),
    enabled: selectedId !== null,
  })

  const users = useQuery({
    queryKey: ['platform-organization-users', selectedId],
    queryFn: () => api.get<{ data: ClientUser[] }>(`platform/organizations/${selectedId}/users`),
    enabled: selectedId !== null,
  })

  function refreshSelected() {
    void queryClient.invalidateQueries({ queryKey: ['platform-organizations'] })
    void queryClient.invalidateQueries({ queryKey: ['platform-organization', selectedId] })
    void queryClient.invalidateQueries({ queryKey: ['platform-organization-users', selectedId] })
  }

  const create = useMutation({
    mutationFn: (values: RecordValues) =>
      api.post<{ message: string; data: PlatformTenant }>('platform/organizations', {
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        ...values,
        send_invitation: true,
      }),
    onSuccess: (response) => {
      setCreating(false)
      setSelectedId(response.data.id)
      void queryClient.invalidateQueries({ queryKey: ['platform-organizations'] })
      toast(response.message)
    },
  })

  const change = useMutation({
    mutationFn: ({ action, reason }: { action: Lifecycle; reason: string }) =>
      api.post<{ message: string }>(`platform/organizations/${selectedId}/${action}`, { reason }),
    onSuccess: (response) => {
      setLifecycle(null)
      refreshSelected()
      toast(response.message)
    },
  })

  const invite = useMutation({
    mutationFn: () => api.post<{ message: string }>(`platform/organizations/${selectedId}/invite`),
    onSuccess: (response) => toast(response.message),
  })

  /**
   * One login per client account, and it reads.
   *
   * The chosen login becomes the client login and every other login in the
   * account is suspended (never deleted). Asked for with a reason, because it
   * takes access away from people.
   */
  const [soleTarget, setSoleTarget] = useState<ClientUser | null>(null)

  const sole = useMutation({
    mutationFn: ({ login, reason }: { login: ClientUser; reason: string }) =>
      api.post<{ message: string }>(
        `platform/organizations/${selectedId}/logins/${login.membership_id}/sole-client`,
        { reason },
      ),
    onSuccess: (response) => {
      setSoleTarget(null)
      refreshSelected()
      toast(response.message)
    },
  })

  /**
   * A login's password: a reset link, or a new password shown here once.
   * Never offered for a platform owner, who changes their own.
   */
  const [resetTarget, setResetTarget] = useState<ClientUser | null>(null)
  const [newPassword, setNewPassword] = useState<{ email: string; password: string } | null>(null)

  const reset = useMutation({
    mutationFn: ({ login, values }: { login: ClientUser; values: RecordValues }) =>
      api.post<{ message: string; meta: { email: string; password?: string } }>(
        `platform/organizations/${selectedId}/logins/${login.membership_id}/password`,
        // The dialog sends changed fields only; the pre-selected method is the default.
        { method: values.method ?? 'generate', reason: values.reason },
      ),
    onSuccess: (response) => {
      setResetTarget(null)
      if (response.meta.password !== undefined) {
        setNewPassword({ email: response.meta.email, password: response.meta.password })
      }
      toast(response.message)
    },
  })

  /**
   * Deleting an account, with everything in it. Offered only once it is
   * suspended or cancelled, and the server asks for the name typed back.
   */
  const [deleting, setDeleting] = useState(false)

  const remove = useMutation({
    mutationFn: (values: RecordValues) =>
      api.delete<{ message: string }>(`platform/organizations/${selectedId}`, {
        confirm_name: values.confirm_name,
        reason: values.reason,
      }),
    onSuccess: (response) => {
      const deletedId = selectedId
      setDeleting(false)
      setSelectedId(null)
      setNewPassword(null)
      void queryClient.invalidateQueries({ queryKey: ['platform-organizations'] })
      toast(response.message)

      // The account being managed is gone: move to another one.
      if (session?.organization?.id === deletedId) {
        const next = rows.find((row) => row.id !== deletedId)
        if (next !== undefined) {
          void switchOrganization(next.id).then(() => void navigate('/accounts'))
        }
      }
    },
  })

  const notes = useMutation({
    mutationFn: (value: string) =>
      api.patch(`platform/organizations/${selectedId}`, { platform_notes: value === '' ? null : value }),
    onSuccess: () => {
      refreshSelected()
      toast('Notes saved')
    },
  })

  const rows = list.data?.data ?? []
  const meta = list.data?.meta
  const client = detail.data?.data
  const state = detail.data?.meta
  const failure = change.error ?? invite.error ?? notes.error

  return (
    <>
      <div className="row row--between mb-3">
        <div className="filters">
          <div className="field">
            <label className="field__label" htmlFor="account-search">
              Search
            </label>
            <input
              id="account-search"
              type="search"
              value={search}
              placeholder="Name or contact email"
              onChange={(event) => {
                setSearch(event.target.value)
                setPage(1)
              }}
            />
          </div>
          <div className="field">
            <label className="field__label" htmlFor="account-status">
              Status
            </label>
            <select
              id="account-status"
              value={status}
              onChange={(event) => {
                setStatus(event.target.value)
                setPage(1)
              }}
            >
              <option value="">Any</option>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
        </div>

        <button type="button" className="btn btn--primary" onClick={() => setCreating(true)}>
          <Plus size={16} aria-hidden /> New client
        </button>
      </div>

      {creating && (
        <RecordDialog
          title="New client account"
          description="Creates the account, its account holder, a 10% management agreement and the client's sign-in, and emails them an invitation to set a password."
          fields={CLIENT_FIELDS}
          submitLabel="Create client"
          pending={create.isPending}
          error={create.error}
          onSubmit={(values) => create.mutate(values)}
          onClose={() => setCreating(false)}
        />
      )}

      {soleTarget !== null && client !== undefined && (
        <RecordDialog
          title={`Make ${soleTarget.email ?? 'this login'} the only login`}
          description={`It becomes ${client.name}'s client login and can only read: properties, calendar and revenue after commission. Every other login in this account is suspended. Nothing is deleted.`}
          fields={REASON_FIELDS}
          submitLabel="Make it the only login"
          pending={sole.isPending}
          error={sole.error}
          onSubmit={(values) => sole.mutate({ login: soleTarget, reason: String(values.reason ?? '') })}
          onClose={() => setSoleTarget(null)}
        />
      )}

      {resetTarget !== null && (
        <RecordDialog
          title={`Reset the password of ${resetTarget.email ?? 'this login'}`}
          description="A new password is shown to you once, to pass on yourself; a reset link goes to the login's email. Either way, every signed-in session of this login ends."
          fields={RESET_FIELDS}
          initial={{ method: 'generate' }}
          submitLabel="Reset password"
          pending={reset.isPending}
          error={reset.error}
          onSubmit={(values) => reset.mutate({ login: resetTarget, values })}
          onClose={() => setResetTarget(null)}
        />
      )}

      {deleting && client !== undefined && (
        <RecordDialog
          title={`Delete ${client.name}`}
          description="Deletes the account and everything in it: properties, reservations, guests, messages, money records and its logins that belong to no other account. This cannot be undone."
          fields={deleteFields(client.name)}
          submitLabel="Delete for good"
          pending={remove.isPending}
          error={remove.error}
          onSubmit={(values) => remove.mutate(values)}
          onClose={() => setDeleting(false)}
        />
      )}

      {lifecycle !== null && client !== undefined && (
        <RecordDialog
          title={`${LIFECYCLE_LABELS[lifecycle]} ${client.name}`}
          description={LIFECYCLE_DESCRIPTIONS[lifecycle]}
          fields={REASON_FIELDS}
          submitLabel={LIFECYCLE_LABELS[lifecycle]}
          pending={change.isPending}
          error={change.error}
          onSubmit={(values) => change.mutate({ action: lifecycle, reason: String(values.reason ?? '') })}
          onClose={() => setLifecycle(null)}
        />
      )}

      {failure !== null && failure !== undefined && (
        <div className="notice notice--error" role="alert">
          {failure instanceof ApiError ? failure.message : 'That could not be done.'}
        </div>
      )}

      <div className="split">
        <section className="card">
          <QueryState
            isLoading={list.isLoading}
            error={list.error}
            isEmpty={rows.length === 0}
            emptyTitle="No client accounts"
            emptyBody="Create the first client account to start managing properties for them."
          >
            <div className="table-wrap">
              <table className="data">
                <thead>
                  <tr>
                    <th>Client</th>
                    <th>Contact</th>
                    <th>Status</th>
                    <th className="numeric">Logins</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr
                      key={row.id}
                      onClick={() => {
                        setSelectedId(row.id)
                        setNewPassword(null)
                      }}
                      className={row.id === selectedId ? 'is-selected' : undefined}
                      style={{ cursor: 'pointer' }}
                    >
                      <td>
                        <div className="strong">{row.name}</div>
                        <div className="small faint">
                          {row.base_currency} · {row.timezone}
                        </div>
                      </td>
                      <td className="small truncate">{row.contact_email ?? '—'}</td>
                      <td>
                        <Chip label={row.status_label} colour={STATUS_COLOURS[row.status] ?? 'slate'} />
                      </td>
                      <td className="numeric">{row.users_count ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {meta !== undefined && meta.last_page > 1 && (
              <div className="card__footer row row--between">
                <span className="small faint">
                  Page {meta.current_page} of {meta.last_page}
                </span>
                <div className="row">
                  <button
                    type="button"
                    className="btn btn--ghost btn--sm"
                    disabled={meta.current_page <= 1}
                    onClick={() => setPage((current) => current - 1)}
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    className="btn btn--ghost btn--sm"
                    disabled={meta.current_page >= meta.last_page}
                    onClick={() => setPage((current) => current + 1)}
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
          </QueryState>
        </section>

        <section className="card">
          {selectedId === null ? (
            <div className="empty">
              <div className="empty__title">No account selected</div>
              <p>Choose a client to see its state, its logins and its notes.</p>
            </div>
          ) : (
            <QueryState isLoading={detail.isLoading} error={detail.error}>
              {client !== undefined && state !== undefined && (
                <>
                  <header className="card__header">
                    <div>
                      <h2>{client.name}</h2>
                      <div className="small faint">
                        {client.legal_name ?? client.name} · created {formatDate(client.created_at)}
                      </div>
                    </div>

                    {session?.organization?.id === client.id ? (
                      <Chip label="Currently managing" colour="emerald" />
                    ) : (
                      <button
                        type="button"
                        className="btn btn--sm"
                        onClick={() => {
                          void switchOrganization(client.id).then(() => {
                            void navigate('/')
                            toast(`Now managing ${client.name}`)
                          })
                        }}
                      >
                        Open <ArrowRight size={14} aria-hidden />
                      </button>
                    )}
                  </header>

                  <div className="card__body stack">
                    <div className="row row--wrap gap-2">
                      <Chip label={client.status_label} colour={STATUS_COLOURS[client.status] ?? 'slate'} />
                      {client.status === 'suspended' || client.status === 'cancelled' ? (
                        <>
                          <button type="button" className="btn btn--sm" onClick={() => setLifecycle('reinstate')}>
                            Reinstate
                          </button>
                          <button type="button" className="btn btn--danger btn--sm" onClick={() => setDeleting(true)}>
                            <Trash2 size={14} aria-hidden /> Delete account
                          </button>
                        </>
                      ) : (
                        <>
                          <button type="button" className="btn btn--sm" onClick={() => setLifecycle('suspend')}>
                            Suspend
                          </button>
                          <button type="button" className="btn btn--danger btn--sm" onClick={() => setLifecycle('cancel')}>
                            Cancel account
                          </button>
                        </>
                      )}
                    </div>

                    {client.suspension_reason !== null && client.status === 'suspended' && (
                      <div className="notice notice--warning">
                        Suspended {formatDate(client.suspended_at)}: {client.suspension_reason}
                      </div>
                    )}

                    {newPassword !== null && (
                      <div className="notice notice--warning" role="status">
                        <div className="strong">New password for {newPassword.email}</div>
                        <div className="row row--wrap gap-2 mt-1">
                          <code className="strong">{newPassword.password}</code>
                          <button
                            type="button"
                            className="btn btn--sm"
                            onClick={() => {
                              void navigator.clipboard?.writeText(newPassword.password).then(() => toast('Password copied'))
                            }}
                          >
                            <Copy size={14} aria-hidden /> Copy
                          </button>
                          <button type="button" className="btn btn--ghost btn--sm" onClick={() => setNewPassword(null)}>
                            Done
                          </button>
                        </div>
                        <div className="small mt-1">
                          Shown once. Pass it on privately; the client can change it from their profile.
                        </div>
                      </div>
                    )}

                    <h3 className="mt-0">Onboarding</h3>
                    <dl className="definition">
                      <dt>Account holder</dt>
                      <dd>
                        {state.client.account_holder === null ? (
                          <span className="danger">
                            <TriangleAlert size={13} aria-hidden /> None yet — run the provisioning command.
                          </span>
                        ) : (
                          <>
                            {state.client.account_holder.display_name ?? '—'}
                            {state.client.account_holder.email !== null && (
                              <span className="faint"> · {state.client.account_holder.email}</span>
                            )}
                            {!state.client.account_holder.has_login && (
                              <span className="ml-2">
                                <Chip label="No login linked" colour="amber" />
                              </span>
                            )}
                          </>
                        )}
                      </dd>
                      <dt>Management agreement</dt>
                      <dd>
                        {state.client.agreement === null ? (
                          <span className="danger">
                            <TriangleAlert size={13} aria-hidden /> None — the client&rsquo;s financials cannot show a commission.
                          </span>
                        ) : (
                          <>
                            {state.client.agreement.commission_rate}% of revenue
                            {state.client.agreement.starts_on !== null && ` from ${formatDate(state.client.agreement.starts_on)}`}
                            {state.client.agreement.deduct_channel_commission_first && (
                              <span className="ml-2">
                                <Chip label="After channel commission" colour="amber" />
                              </span>
                            )}
                            <div className="small faint">Edit the terms on the Owners screen while managing this account.</div>
                          </>
                        )}
                      </dd>
                      <dt>Properties</dt>
                      <dd>
                        {state.counts.properties}
                        {state.client.properties_without_ownership > 0 && (
                          <span className="ml-2">
                            <Chip label={`${state.client.properties_without_ownership} not attributed`} colour="amber" />
                          </span>
                        )}
                        {state.client.properties_with_other_owners > 0 && (
                          <span className="ml-2">
                            <Chip label={`${state.client.properties_with_other_owners} with other owners`} colour="slate" />
                          </span>
                        )}
                      </dd>
                      <dt>Channel accounts</dt>
                      <dd>{state.counts.channel_accounts}</dd>
                      <dt>Reservations</dt>
                      <dd>
                        {state.counts.reservations}
                        {state.last_reservation_at !== null && (
                          <span className="faint"> · last {formatDate(state.last_reservation_at)}</span>
                        )}
                      </dd>
                    </dl>

                    {state.client.staff_logins.length > 0 && (
                      <div className="notice notice--warning">
                        <TriangleAlert size={14} aria-hidden /> {state.client.staff_logins.length} login
                        {state.client.staff_logins.length === 1 ? ' still holds' : 's still hold'} staff roles in
                        this account and can change things. A client account should have one login, and it
                        should only read. Choose the client&rsquo;s login below and click{' '}
                        <strong>Make this the only login</strong>. Nothing converts automatically.
                      </div>
                    )}

                    <div className="row row--between">
                      <h3>Logins</h3>
                      <button
                        type="button"
                        className="btn btn--ghost btn--sm"
                        disabled={invite.isPending || state.client.account_holder === null}
                        onClick={() => invite.mutate()}
                      >
                        <Mail size={14} aria-hidden /> Resend invitation
                      </button>
                    </div>

                    <QueryState isLoading={users.isLoading} error={users.error}>
                      {(users.data?.data.length ?? 0) === 0 ? (
                        <p className="small faint">Nobody can sign in to this account yet.</p>
                      ) : (
                        <div className="table-wrap">
                          <table className="data">
                            <thead>
                              <tr>
                                <th>Person</th>
                                <th>Access</th>
                                <th>Last sign-in</th>
                                <th />
                              </tr>
                            </thead>
                            <tbody>
                              {(users.data?.data ?? []).map((user) => {
                                const active = user.status === 'active'
                                const activeLogins = (users.data?.data ?? []).filter((row) => row.status === 'active')
                                const alreadySole = active && user.is_client && activeLogins.length === 1

                                return (
                                  <tr key={user.membership_id}>
                                    <td>
                                      <div className="strong">{user.name ?? '—'}</div>
                                      <div className="small faint">{user.email}</div>
                                    </td>
                                    <td>
                                      {!active ? (
                                        <Chip label="Suspended" colour="zinc" />
                                      ) : user.is_client ? (
                                        <Chip label="Client · read-only" colour="emerald" />
                                      ) : (
                                        <Chip label={`Staff · ${user.roles.join(', ') || 'no role'}`} colour="amber" />
                                      )}
                                    </td>
                                    <td className="small">{formatDateTime(user.last_login_at)}</td>
                                    <td>
                                      <div className="row row--wrap gap-2">
                                        {active && !alreadySole && !user.is_platform_admin && (
                                          <button
                                            type="button"
                                            className="btn btn--sm"
                                            disabled={sole.isPending}
                                            onClick={() => setSoleTarget(user)}
                                          >
                                            Make this the only login
                                          </button>
                                        )}
                                        {!user.is_platform_admin && (
                                          <button
                                            type="button"
                                            className="btn btn--ghost btn--sm"
                                            disabled={reset.isPending}
                                            onClick={() => setResetTarget(user)}
                                          >
                                            <KeyRound size={14} aria-hidden /> Reset password
                                          </button>
                                        )}
                                      </div>
                                    </td>
                                  </tr>
                                )
                              })}
                            </tbody>
                          </table>
                        </div>
                      )}
                    </QueryState>

                    <h3>Private notes</h3>
                    <NotesEditor
                      key={client.id}
                      value={client.platform_notes ?? ''}
                      pending={notes.isPending}
                      onSave={(value) => notes.mutate(value)}
                    />
                  </div>
                </>
              )}
            </QueryState>
          )}
        </section>
      </div>
    </>
  )
}

const LIFECYCLE_LABELS: Record<Lifecycle, string> = {
  suspend: 'Suspend',
  reinstate: 'Reinstate',
  cancel: 'Cancel',
}

const LIFECYCLE_DESCRIPTIONS: Record<Lifecycle, string> = {
  suspend: 'Ends the client’s sessions and refuses sign-in until reinstated. Nothing is deleted and the account keeps syncing.',
  reinstate: 'Lets the client sign in again.',
  cancel: 'Marks the account cancelled. Every record it holds is kept.',
}

function NotesEditor({
  value,
  pending,
  onSave,
}: {
  value: string
  pending: boolean
  onSave: (value: string) => void
}) {
  const [draft, setDraft] = useState(value)

  return (
    <div className="stack stack--tight">
      <textarea
        rows={4}
        value={draft}
        aria-label="Private notes"
        placeholder="Never shown to the client."
        onChange={(event) => setDraft(event.target.value)}
      />
      <div className="row row--between">
        <span className="small faint">Never shown to the client.</span>
        <button
          type="button"
          className="btn btn--sm"
          disabled={pending || draft === value}
          onClick={() => onSave(draft)}
        >
          Save notes
        </button>
      </div>
    </div>
  )
}

function Administrators() {
  const queryClient = useQueryClient()
  const { session } = useAuth()
  const [search, setSearch] = useState('')
  const [target, setTarget] = useState<{ user: PlatformUser; grant: boolean } | null>(null)

  const admins = useQuery({
    queryKey: ['platform-users', { platform_admins_only: true }],
    queryFn: () =>
      api.get<{ data: PlatformUser[] }>('platform/users', { platform_admins_only: true, per_page: 100 }),
  })

  const candidates = useQuery({
    queryKey: ['platform-users', { search }],
    queryFn: () => api.get<{ data: PlatformUser[] }>('platform/users', { search, per_page: 10 }),
    enabled: search.trim().length >= 3,
  })

  const update = useMutation({
    mutationFn: ({ user, grant, reason }: { user: PlatformUser; grant: boolean; reason: string }) =>
      api.patch<{ message: string }>(`platform/users/${user.id}`, { is_platform_admin: grant, reason }),
    onSuccess: (response) => {
      setTarget(null)
      void queryClient.invalidateQueries({ queryKey: ['platform-users'] })
      toast(response.message)
    },
  })

  return (
    <>
      <p className="small faint mb-3">
        A platform administrator can open every client account. There is no permission that grants
        this; only another administrator can, and the last one cannot be removed.
      </p>

      {target !== null && (
        <RecordDialog
          title={`${target.grant ? 'Grant' : 'Revoke'} platform administration for ${target.user.name}`}
          fields={REASON_FIELDS}
          submitLabel={target.grant ? 'Grant' : 'Revoke'}
          pending={update.isPending}
          error={update.error}
          onSubmit={(values) =>
            update.mutate({ user: target.user, grant: target.grant, reason: String(values.reason ?? '') })
          }
          onClose={() => setTarget(null)}
        />
      )}

      <div className="split">
        <section className="card">
          <header className="card__header">
            <h2>Administrators</h2>
          </header>
          <QueryState isLoading={admins.isLoading} error={admins.error}>
            <div className="table-wrap">
              <table className="data">
                <thead>
                  <tr>
                    <th>Person</th>
                    <th>Second factor</th>
                    <th>Last sign-in</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {(admins.data?.data ?? []).map((user) => (
                    <tr key={user.id}>
                      <td>
                        <div className="strong">
                          <ShieldCheck size={13} aria-hidden /> {user.name}
                        </div>
                        <div className="small faint">{user.email}</div>
                      </td>
                      <td>
                        <Chip label={user.mfa_enabled ? 'Enabled' : 'Not enrolled'} colour={user.mfa_enabled ? 'emerald' : 'amber'} />
                      </td>
                      <td className="small">{formatDateTime(user.last_login_at)}</td>
                      <td>
                        {user.id !== session?.user.id && (
                          <button
                            type="button"
                            className="btn btn--danger btn--sm"
                            onClick={() => setTarget({ user, grant: false })}
                          >
                            Revoke
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </QueryState>
        </section>

        <section className="card">
          <header className="card__header">
            <h2>Grant to somebody</h2>
          </header>
          <div className="card__body stack">
            <div className="field">
              <label className="field__label" htmlFor="admin-search">
                Find a person by email
              </label>
              <input
                id="admin-search"
                type="search"
                value={search}
                placeholder="At least three characters"
                onChange={(event) => setSearch(event.target.value)}
              />
            </div>

            {candidates.data !== undefined && (
              <ul className="plain">
                {candidates.data.data
                  .filter((user) => !user.is_platform_admin)
                  .map((user) => (
                    <li key={user.id} className="row row--between">
                      <span>
                        <span className="strong">{user.name}</span>{' '}
                        <span className="small faint">{user.email}</span>
                      </span>
                      <button type="button" className="btn btn--sm" onClick={() => setTarget({ user, grant: true })}>
                        Grant
                      </button>
                    </li>
                  ))}
              </ul>
            )}
          </div>
        </section>
      </div>
    </>
  )
}

function Audit() {
  const [page, setPage] = useState(1)

  const log = useQuery({
    queryKey: ['platform-audit', page],
    queryFn: () =>
      api.get<{ data: PlatformAuditRow[]; meta: { current_page: number; last_page: number } }>(
        'platform/audit/platform',
        { page, per_page: 50 },
      ),
    placeholderData: keepPreviousData,
  })

  const rows = log.data?.data ?? []
  const meta = log.data?.meta

  return (
    <section className="card">
      <header className="card__header">
        <h2>What the platform did</h2>
        <span className="small faint">Creating, suspending and reinstating accounts; granting administration.</span>
      </header>
      <QueryState
        isLoading={log.isLoading}
        error={log.error}
        isEmpty={rows.length === 0}
        emptyTitle="Nothing recorded yet"
      >
        <div className="table-wrap">
          <table className="data">
            <thead>
              <tr>
                <th>When</th>
                <th>Action</th>
                <th>By</th>
                <th>Account</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.id}>
                  <td className="small">{formatDateTime(row.created_at)}</td>
                  <td className="mono small">{row.action}</td>
                  <td className="small">{row.actor_email ?? '—'}</td>
                  <td className="small">{row.organization_name ?? '—'}</td>
                  <td className="small">{row.description ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {meta !== undefined && meta.last_page > 1 && (
          <div className="card__footer row row--between">
            <span className="small faint">
              Page {meta.current_page} of {meta.last_page}
            </span>
            <div className="row">
              <button type="button" className="btn btn--ghost btn--sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                Previous
              </button>
              <button type="button" className="btn btn--ghost btn--sm" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>
                Next
              </button>
            </div>
          </div>
        )}
      </QueryState>
    </section>
  )
}
