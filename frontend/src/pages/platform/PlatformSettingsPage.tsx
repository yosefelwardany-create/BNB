import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import { QueryState } from '@/components/QueryState'

interface Setting {
  key: string
  type: string
  value: unknown
  default: unknown
  description: string
}

/**
 * Platform configuration.
 *
 * The form is built from the server's own definitions, so a new setting needs no
 * change here. That also means the console cannot invent a setting nothing reads
 * — the API refuses an unknown key rather than storing it, which matters because
 * a setting somebody believes they changed and which does nothing is the worst
 * outcome available on this screen.
 */
export function PlatformSettingsPage() {
  const queryClient = useQueryClient()
  const [draft, setDraft] = useState<Record<string, unknown>>({})

  const settings = useQuery({
    queryKey: ['platform-settings'],
    queryFn: () => api.get<{ data: Setting[] }>('platform/settings'),
  })

  useEffect(() => {
    if (settings.data !== undefined) {
      setDraft(
        Object.fromEntries(settings.data.data.map((setting) => [setting.key, setting.value])),
      )
    }
  }, [settings.data])

  const save = useMutation({
    mutationFn: () => api.put('platform/settings', { settings: draft }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['platform-settings'] }),
  })

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Settings</h1>
          <div className="page-header__subtitle">
            Platform configuration that changes without a deploy.
          </div>
        </div>
      </div>

      {save.error !== null && (
        <div className="notice notice--error" role="alert">
          {save.error instanceof ApiError
            ? save.error.message
            : 'Those settings could not be saved.'}
        </div>
      )}

      {save.isSuccess && !save.isPending && (
        <div className="notice notice--info">Settings saved.</div>
      )}

      <QueryState isLoading={settings.isLoading} error={settings.error}>
        <section className="card">
          <form
            className="card__body"
            onSubmit={(event) => {
              event.preventDefault()
              save.mutate()
            }}
          >
            <p className="small faint mt-0">
              Nothing here holds a secret. Credentials live in the environment, where an
              admin console cannot display them.
            </p>

            {(settings.data?.data ?? []).map((setting) => (
              <div key={setting.key} className="field">
                <label className="field__label" htmlFor={setting.key}>
                  {setting.key.replace(/_/g, ' ')}
                </label>

                {setting.type === 'boolean' ? (
                  <label className="row small">
                    <input
                      id={setting.key}
                      type="checkbox"
                      checked={draft[setting.key] === true}
                      onChange={(event) =>
                        setDraft({ ...draft, [setting.key]: event.target.checked })
                      }
                    />
                    {draft[setting.key] === true ? 'On' : 'Off'}
                  </label>
                ) : (
                  <input
                    id={setting.key}
                    type={setting.type === 'integer' ? 'number' : 'text'}
                    value={
                      draft[setting.key] === null || draft[setting.key] === undefined
                        ? ''
                        : String(draft[setting.key])
                    }
                    onChange={(event) =>
                      setDraft({
                        ...draft,
                        [setting.key]:
                          event.target.value === ''
                            ? null
                            : setting.type === 'integer'
                              ? Number(event.target.value)
                              : event.target.value,
                      })
                    }
                  />
                )}

                <span className="field__hint">{setting.description}</span>
              </div>
            ))}

            <button type="submit" className="btn btn--primary" disabled={save.isPending}>
              {save.isPending ? 'Saving…' : 'Save settings'}
            </button>
          </form>
        </section>
      </QueryState>
    </>
  )
}
