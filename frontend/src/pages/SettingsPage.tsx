import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { ApiKey, Paginated, WebhookDelivery, WebhookEndpoint } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { useAuth } from '@/lib/auth'

type Tab = 'keys' | 'webhooks'

/**
 * Developer settings.
 *
 * Two secrets are issued on this screen and neither can be recovered: an API
 * key is stored only as a hash, and a signing secret is encrypted and never
 * read back. Both are therefore shown once, loudly, with the consequence
 * spelled out — the alternative is somebody closing a dialogue and losing a
 * production credential.
 */
export function SettingsPage() {
  const { can, canAny } = useAuth()

  const tabs: { key: Tab; label: string; visible: boolean }[] = [
    { key: 'keys', label: 'API keys', visible: can('api_keys.manage') },
    {
      key: 'webhooks',
      label: 'Webhooks',
      visible: canAny(['webhooks.manage', 'integrations.view']),
    },
  ]

  const visible = tabs.filter((tab) => tab.visible)
  const [tab, setTab] = useState<Tab>(visible[0]?.key ?? 'keys')

  if (visible.length === 0) {
    return (
      <div className="empty">
        <div className="empty__title">Nothing here for you</div>
        <p>Your role does not include developer settings.</p>
      </div>
    )
  }

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Settings</h1>
          <div className="page-header__subtitle">
            How other systems talk to this one.
          </div>
        </div>

        <div className="row">
          {visible.map((option) => (
            <button
              key={option.key}
              type="button"
              className={tab === option.key ? 'btn btn--sm' : 'btn btn--ghost btn--sm'}
              onClick={() => setTab(option.key)}
            >
              {option.label}
            </button>
          ))}
        </div>
      </div>

      {tab === 'keys' && <ApiKeysTab />}
      {tab === 'webhooks' && <WebhooksTab />}
    </>
  )
}

interface IssuedKey {
  data: ApiKey
  meta: {
    token: string
    token_notice: string
    abilities_granted: string[]
    abilities_refused: string[]
  }
}

function ApiKeysTab() {
  const queryClient = useQueryClient()
  const { session } = useAuth()

  const [name, setName] = useState('')
  const [abilities, setAbilities] = useState<string[]>([])
  const [issued, setIssued] = useState<IssuedKey | null>(null)

  const keys = useQuery({
    queryKey: ['api-keys'],
    queryFn: () => api.get<Paginated<ApiKey>>('api-keys', { per_page: 50 }),
  })

  const create = useMutation({
    mutationFn: () => api.post<IssuedKey>('api-keys', { name, abilities }),
    onSuccess: (result) => {
      setIssued(result)
      setName('')
      setAbilities([])
      void queryClient.invalidateQueries({ queryKey: ['api-keys'] })
    },
  })

  const revoke = useMutation({
    mutationFn: (key: ApiKey) => api.delete(`api-keys/${key.id}`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['api-keys'] }),
  })

  // Only what the signed-in user themselves holds. The server enforces this
  // too — a key can never be more powerful than the person who created it —
  // and offering more here would only produce a refusal after the fact.
  const offerable = [...(session?.permissions ?? [])].filter((p) => p !== '*').sort()

  return (
    <>
      {issued !== null && (
        <div className="notice notice--warning">
          <div className="strong">{issued.meta.token_notice}</div>

          <pre className="secret">{issued.meta.token}</pre>

          {issued.meta.abilities_refused.length > 0 && (
            // Said when it happens rather than left to be discovered by a
            // 403 in production.
            <div className="small">
              Refused, because you do not hold them yourself:{' '}
              {issued.meta.abilities_refused.join(', ')}
            </div>
          )}

          <button
            type="button"
            className="btn btn--sm mt-2"
            onClick={() => setIssued(null)}
          >
            I have stored it
          </button>
        </div>
      )}

      <section className="card mb-3">
        <header className="card__header">
          <h2>Issue a key</h2>
        </header>

        <form
          className="card__body"
          onSubmit={(event) => {
            event.preventDefault()
            if (name.trim() !== '' && abilities.length > 0) create.mutate()
          }}
        >
          {create.error !== null && (
            <div className="notice notice--error" role="alert">
              {create.error instanceof ApiError
                ? create.error.message
                : 'That key could not be issued.'}
            </div>
          )}

          <div className="field">
            <label className="field__label" htmlFor="key-name">
              Name
            </label>
            <input
              id="key-name"
              type="text"
              value={name}
              placeholder="Staging server, accounting export…"
              onChange={(event) => setName(event.target.value)}
            />
            <span className="field__hint">
              How you will recognise this key in six months.
            </span>
          </div>

          <div className="field">
            <span className="field__label">Abilities</span>
            <div className="checks">
              {offerable.map((ability) => (
                <label key={ability} className="row small">
                  <input
                    type="checkbox"
                    checked={abilities.includes(ability)}
                    onChange={(event) =>
                      setAbilities((current) =>
                        event.target.checked
                          ? [...current, ability]
                          : current.filter((entry) => entry !== ability),
                      )
                    }
                  />
                  <span className="mono">{ability}</span>
                </label>
              ))}
            </div>
            <span className="field__hint">
              Only the permissions you hold yourself are offered.
            </span>
          </div>

          <button
            type="submit"
            className="btn btn--primary"
            disabled={create.isPending || name.trim() === '' || abilities.length === 0}
          >
            {create.isPending ? 'Issuing…' : 'Issue key'}
          </button>
        </form>
      </section>

      <section className="card">
        <header className="card__header">
          <h2>Keys</h2>
        </header>

        <QueryState
          isLoading={keys.isLoading}
          error={keys.error}
          isEmpty={(keys.data?.data.length ?? 0) === 0}
          emptyTitle="No keys"
          emptyBody="Nothing is calling this API with a key yet."
        >
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Prefix</th>
                  <th>Abilities</th>
                  <th className="numeric">Calls</th>
                  <th>Last used</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {(keys.data?.data ?? []).map((key) => (
                  <tr key={key.id}>
                    <td>
                      <div className="strong">{key.name}</div>
                      {!key.is_usable && (
                        <Chip
                          label={key.revoked_at !== null ? 'Revoked' : 'Expired'}
                          colour="zinc"
                        />
                      )}
                    </td>

                    {/* The prefix is not the key and cannot be used as one.
                        It is here so four keys can be told apart. */}
                    <td className="mono">{key.prefix}…</td>

                    <td className="small faint wrap">{key.abilities.join(', ')}</td>
                    <td className="numeric">{key.request_count}</td>

                    <td className="small faint">
                      {key.last_used_at !== null
                        ? new Date(key.last_used_at).toLocaleString()
                        : 'Never'}
                    </td>

                    <td>
                      {key.is_usable && (
                        <button
                          type="button"
                          className="btn btn--danger btn--sm"
                          onClick={() => revoke.mutate(key)}
                          disabled={revoke.isPending}
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
    </>
  )
}

interface SecretResult {
  meta: { signing_secret: string; signing_notice: string }
}

function WebhooksTab() {
  const queryClient = useQueryClient()
  const { can } = useAuth()

  const [name, setName] = useState('')
  const [url, setUrl] = useState('')
  const [secret, setSecret] = useState<SecretResult['meta'] | null>(null)
  const [expanded, setExpanded] = useState<string | null>(null)

  const endpoints = useQuery({
    queryKey: ['webhook-endpoints'],
    queryFn: () => api.get<Paginated<WebhookEndpoint>>('webhook-endpoints', { per_page: 50 }),
  })

  const deliveries = useQuery({
    queryKey: ['webhook-deliveries', expanded],
    queryFn: () =>
      api.get<Paginated<WebhookDelivery>>(`webhook-endpoints/${expanded}/deliveries`, {
        per_page: 15,
      }),
    enabled: expanded !== null,
  })

  const create = useMutation({
    mutationFn: () => api.post<SecretResult>('webhook-endpoints', { name, url }),
    onSuccess: (result) => {
      setSecret(result.meta)
      setName('')
      setUrl('')
      void queryClient.invalidateQueries({ queryKey: ['webhook-endpoints'] })
    },
  })

  const rotate = useMutation({
    mutationFn: (endpoint: WebhookEndpoint) =>
      api.post<SecretResult>(`webhook-endpoints/${endpoint.id}/rotate-secret`),
    onSuccess: (result) => {
      setSecret(result.meta)
      void queryClient.invalidateQueries({ queryKey: ['webhook-endpoints'] })
    },
  })

  const test = useMutation({
    mutationFn: (endpoint: WebhookEndpoint) =>
      api.post<{ data: WebhookDelivery; meta: { delivered: boolean } }>(
        `webhook-endpoints/${endpoint.id}/test`,
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['webhook-endpoints'] })
      void queryClient.invalidateQueries({ queryKey: ['webhook-deliveries'] })
    },
  })

  const redeliver = useMutation({
    mutationFn: (delivery: WebhookDelivery) =>
      api.post(`webhook-deliveries/${delivery.id}/redeliver`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['webhook-deliveries'] }),
  })

  const failed = create.error ?? rotate.error ?? test.error ?? redeliver.error

  return (
    <>
      {secret !== null && (
        <div className="notice notice--warning">
          <div className="strong">{secret.signing_notice}</div>
          <pre className="secret">{secret.signing_secret}</pre>
          <div className="small">
            Sign with <span className="mono">HMAC-SHA256</span> over{' '}
            <span className="mono">&lt;timestamp&gt;.&lt;body&gt;</span> and compare against
            the <span className="mono">v1</span> value in the signature header.
          </div>
          <button type="button" className="btn btn--sm mt-2" onClick={() => setSecret(null)}>
            I have stored it
          </button>
        </div>
      )}

      {failed !== null && failed !== undefined && (
        <div className="notice notice--error" role="alert">
          {failed instanceof ApiError ? failed.message : 'That action could not be completed.'}
        </div>
      )}

      {test.data !== undefined && (
        <div className={test.data.meta.delivered ? 'notice notice--info' : 'notice notice--error'}>
          {test.data.meta.delivered
            ? `The endpoint answered ${test.data.data.response_status ?? ''} in ${
                test.data.data.duration_ms ?? '?'
              }ms.`
            : (test.data.data.error_message ??
              `The endpoint answered ${test.data.data.response_status ?? 'nothing'}.`)}
        </div>
      )}

      {can('webhooks.manage') && (
        <section className="card mb-3">
          <header className="card__header">
            <h2>Add an endpoint</h2>
          </header>

          <form
            className="card__body"
            onSubmit={(event) => {
              event.preventDefault()
              if (name.trim() !== '' && url.trim() !== '') create.mutate()
            }}
          >
            <div className="field">
              <label className="field__label" htmlFor="endpoint-name">
                Name
              </label>
              <input
                id="endpoint-name"
                type="text"
                value={name}
                onChange={(event) => setName(event.target.value)}
              />
            </div>

            <div className="field">
              <label className="field__label" htmlFor="endpoint-url">
                URL
              </label>
              <input
                id="endpoint-url"
                type="text"
                value={url}
                placeholder="https://example.com/hooks/habitat"
                onChange={(event) => setUrl(event.target.value)}
              />
              {create.error instanceof ApiError &&
                create.error.fieldError('url') !== undefined && (
                  <span className="field__error">{create.error.fieldError('url')}</span>
                )}
              <span className="field__hint">
                {/* The constraint, and why, rather than a bare rejection when
                    somebody pastes an internal address. */}
                HTTPS only, and it must resolve outside this infrastructure — an
                endpoint on a private address would make this server a proxy into
                its own network.
              </span>
            </div>

            <button
              type="submit"
              className="btn btn--primary"
              disabled={create.isPending || name.trim() === '' || url.trim() === ''}
            >
              {create.isPending ? 'Adding…' : 'Add endpoint'}
            </button>
          </form>
        </section>
      )}

      <section className="card">
        <header className="card__header">
          <h2>Endpoints</h2>
        </header>

        <QueryState
          isLoading={endpoints.isLoading}
          error={endpoints.error}
          isEmpty={(endpoints.data?.data.length ?? 0) === 0}
          emptyTitle="No endpoints"
          emptyBody="Nothing is subscribed to this organization's events."
        >
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Endpoint</th>
                  <th>Events</th>
                  <th>Health</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {(endpoints.data?.data ?? []).map((endpoint) => (
                  <tr key={endpoint.id}>
                    <td>
                      <div className="strong">{endpoint.name}</div>
                      <div className="mono small faint truncate">{endpoint.url}</div>
                    </td>

                    <td className="small faint wrap">
                      {endpoint.receives_all_events
                        ? 'Every event'
                        : endpoint.events.join(', ')}
                    </td>

                    <td>
                      <div className="row">
                        <Chip
                          label={endpoint.status}
                          colour={endpoint.is_healthy ? 'emerald' : 'rose'}
                        />
                        {endpoint.consecutive_failures > 0 && (
                          <Chip
                            label={`${endpoint.consecutive_failures} failed in a row`}
                            colour="amber"
                          />
                        )}
                      </div>

                      {endpoint.last_error !== null && (
                        <div className="small danger mt-1">{endpoint.last_error}</div>
                      )}
                    </td>

                    <td>
                      <div className="row">
                        <button
                          type="button"
                          className="btn btn--ghost btn--sm"
                          onClick={() =>
                            setExpanded(expanded === endpoint.id ? null : endpoint.id)
                          }
                        >
                          {expanded === endpoint.id ? 'Hide log' : 'Log'}
                        </button>

                        {can('webhooks.manage') && (
                          <>
                            <button
                              type="button"
                              className="btn btn--ghost btn--sm"
                              onClick={() => test.mutate(endpoint)}
                              disabled={test.isPending}
                            >
                              Send test
                            </button>

                            <button
                              type="button"
                              className="btn btn--ghost btn--sm"
                              onClick={() => rotate.mutate(endpoint)}
                              disabled={rotate.isPending}
                            >
                              Rotate secret
                            </button>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </QueryState>
      </section>

      {expanded !== null && (
        <section className="card mt-3">
          <header className="card__header">
            <h2>Delivery log</h2>
          </header>

          <QueryState
            isLoading={deliveries.isLoading}
            error={deliveries.error}
            isEmpty={(deliveries.data?.data.length ?? 0) === 0}
            emptyTitle="Nothing delivered yet"
          >
            <div className="table-wrap">
              <table className="data">
                <thead>
                  <tr>
                    <th>Event</th>
                    <th className="numeric">Attempt</th>
                    <th>Result</th>
                    <th>When</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {(deliveries.data?.data ?? []).map((delivery) => (
                    <tr key={delivery.id}>
                      <td className="mono small">{delivery.event_name}</td>
                      <td className="numeric">{delivery.attempt}</td>

                      <td>
                        <Chip
                          label={
                            delivery.response_status !== null
                              ? `${delivery.status} · ${delivery.response_status}`
                              : delivery.status
                          }
                          colour={delivery.status === 'succeeded' ? 'emerald' : 'rose'}
                        />
                        {delivery.error_message !== null && (
                          <div className="small danger mt-1">{delivery.error_message}</div>
                        )}
                        {delivery.next_attempt_at !== null && (
                          <div className="small faint mt-1">
                            Next attempt{' '}
                            {new Date(delivery.next_attempt_at).toLocaleString()}
                          </div>
                        )}
                      </td>

                      <td className="small faint nowrap">
                        {delivery.dispatched_at !== null
                          ? new Date(delivery.dispatched_at).toLocaleString()
                          : '—'}
                        {delivery.duration_ms !== null && ` · ${delivery.duration_ms}ms`}
                      </td>

                      <td>
                        {can('webhooks.manage') && delivery.status !== 'succeeded' && (
                          <button
                            type="button"
                            className="btn btn--ghost btn--sm"
                            onClick={() => redeliver.mutate(delivery)}
                            disabled={redeliver.isPending}
                          >
                            Redeliver
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
      )}
    </>
  )
}
