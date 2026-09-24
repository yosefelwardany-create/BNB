import { useMemo, useState } from 'react'
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

  // Only what this person has typed. Copying the server's values into state
  // and keeping the two in step with an effect is how a form ends up showing a
  // stale value after a refetch, or discarding an edit made while one was in
  // flight; the stored values are read straight from the query and the edits
  // are laid over them.
  const [edits, setEdits] = useState<Record<string, unknown>>({})

  const settings = useQuery({
    queryKey: ['platform-settings'],
    queryFn: () => api.get<{ data: Setting[] }>('platform/settings'),
  })

  const stored = useMemo(
    () => Object.fromEntries((settings.data?.data ?? []).map((row) => [row.key, row.value])),
    [settings.data],
  )

  const draft: Record<string, unknown> = { ...stored, ...edits }

  function edit(key: string, value: unknown): void {
    setEdits((previous) => ({ ...previous, [key]: value }))
  }

  const save = useMutation({
    mutationFn: () => api.put('platform/settings', { settings: draft }),
    onSuccess: () => {
      // Saved values are the server's now, so the overlay is dropped rather
      // than left to shadow whatever comes back.
      setEdits({})
      void queryClient.invalidateQueries({ queryKey: ['platform-settings'] })
    },
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
                        edit(setting.key, event.target.checked)
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
                      edit(
                        setting.key,
                        event.target.value === ''
                          ? null
                          : setting.type === 'integer'
                            ? Number(event.target.value)
                            : event.target.value,
                      )
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
