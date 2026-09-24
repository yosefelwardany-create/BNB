import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { Plan, PlatformVocabulary } from '@/api/types'
import { Chip } from '@/components/Chip'
import { QueryState } from '@/components/QueryState'
import { formatMoney, formatNumber } from '@/lib/format'

interface Draft {
  name: string
  price_amount: string
  currency: string
  billing_interval: string
  trial_days: string
  limits: Record<string, string>
  features: string[]
  is_public: boolean
  is_active: boolean
}

/**
 * What the platform sells.
 *
 * The feature and limit lists are fetched from the server rather than hard-coded
 * here, so adding one is a single change in PHP and it appears in this form
 * without a rebuild. A copy in TypeScript is how a feature ends up grantable in
 * the API and invisible in the console.
 */
export function PlatformPlansPage() {
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<string | null>(null)
  const [draft, setDraft] = useState<Draft | null>(null)

  const plans = useQuery({
    queryKey: ['platform-plans'],
    queryFn: () => api.get<{ data: Plan[] }>('platform/plans'),
  })

  const vocabulary = useQuery({
    queryKey: ['platform-vocabulary'],
    queryFn: () => api.get<{ data: PlatformVocabulary }>('platform/vocabulary'),
  })

  const save = useMutation({
    mutationFn: ({ id, body }: { id: string | null; body: unknown }) =>
      id === null
        ? api.post<{ data: Plan }>('platform/plans', body)
        : api.patch<{ data: Plan }>(`platform/plans/${id}`, body),
    onSuccess: () => {
      setEditing(null)
      setDraft(null)
      void queryClient.invalidateQueries({ queryKey: ['platform-plans'] })
    },
  })

  const retire = useMutation({
    mutationFn: (plan: Plan) => api.delete(`platform/plans/${plan.id}`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['platform-plans'] }),
  })

  const limitKeys = vocabulary.data?.data.limits ?? []
  const featureKeys = vocabulary.data?.data.features ?? []

  function startEditing(plan: Plan | null): void {
    setEditing(plan?.id ?? 'new')

    setDraft({
      name: plan?.name ?? '',
      // Minor units in the API; shown as a decimal here and converted back on
      // save, so nobody types 4900 meaning forty-nine.
      price_amount: plan === null ? '' : (plan.price.amount / 100).toString(),
      currency: plan?.price.currency ?? 'EUR',
      billing_interval: plan?.billing_interval ?? 'monthly',
      trial_days: (plan?.trial_days ?? 30).toString(),
      limits: Object.fromEntries(
        limitKeys.map((l) => [l.key, plan?.limits[l.key] === null || plan === null ? '' : String(plan.limits[l.key])]),
      ),
      features: plan?.features ?? [],
      is_public: plan?.is_public ?? true,
      is_active: plan?.is_active ?? true,
    })
  }

  function submit(): void {
    if (draft === null) return

    const limits: Record<string, number | null> = {}

    for (const [key, value] of Object.entries(draft.limits)) {
      // An empty box means unlimited, which the API expects as null. Zero is a
      // real answer meaning "none allowed" and must survive.
      limits[key] = value.trim() === '' ? null : Number(value)
    }

    save.mutate({
      id: editing === 'new' ? null : editing,
      body: {
        name: draft.name,
        price_amount: Math.round(Number(draft.price_amount || '0') * 100),
        currency: draft.currency,
        billing_interval: draft.billing_interval,
        trial_days: Number(draft.trial_days || '0'),
        features: draft.features,
        is_public: draft.is_public,
        is_active: draft.is_active,
        ...limits,
      },
    })
  }

  return (
    <>
      <div className="page-header">
        <div>
          <h1>Plans</h1>
          <div className="page-header__subtitle">
            {plans.data ? `${plans.data.data.length} plan(s)` : 'Loading…'}
          </div>
        </div>

        <button type="button" className="btn btn--primary btn--sm" onClick={() => startEditing(null)}>
          New plan
        </button>
      </div>

      {save.error !== null && (
        <div className="notice notice--error" role="alert">
          {save.error instanceof ApiError ? save.error.message : 'That plan could not be saved.'}
        </div>
      )}

      {retire.error !== null && (
        <div className="notice notice--error" role="alert">
          {retire.error instanceof ApiError
            ? retire.error.message
            : 'That plan could not be retired.'}
        </div>
      )}

      {editing !== null && draft !== null && (
        <section className="card mb-3">
          <header className="card__header">
            <h2>{editing === 'new' ? 'New plan' : 'Edit plan'}</h2>
          </header>

          <form
            className="card__body"
            onSubmit={(event) => {
              event.preventDefault()
              submit()
            }}
          >
            <div className="grid grid--two">
              <div className="field">
                <label className="field__label" htmlFor="plan-name">
                  Name
                </label>
                <input
                  id="plan-name"
                  type="text"
                  value={draft.name}
                  onChange={(e) => setDraft({ ...draft, name: e.target.value })}
                />
              </div>

              <div className="field">
                <label className="field__label" htmlFor="plan-price">
                  Price per {draft.billing_interval === 'yearly' ? 'year' : 'month'}
                </label>
                <input
                  id="plan-price"
                  type="text"
                  inputMode="decimal"
                  value={draft.price_amount}
                  onChange={(e) => setDraft({ ...draft, price_amount: e.target.value })}
                />
                <span className="field__hint">In {draft.currency}, as a decimal.</span>
              </div>

              <div className="field">
                <label className="field__label" htmlFor="plan-interval">
                  Billing interval
                </label>
                <select
                  id="plan-interval"
                  value={draft.billing_interval}
                  onChange={(e) => setDraft({ ...draft, billing_interval: e.target.value })}
                >
                  <option value="monthly">Monthly</option>
                  <option value="yearly">Yearly</option>
                </select>
              </div>

              <div className="field">
                <label className="field__label" htmlFor="plan-trial">
                  Trial days
                </label>
                <input
                  id="plan-trial"
                  type="number"
                  value={draft.trial_days}
                  onChange={(e) => setDraft({ ...draft, trial_days: e.target.value })}
                />
              </div>
            </div>

            <div className="field">
              <span className="field__label">Limits</span>
              <div className="checks">
                {limitKeys.map((limit) => (
                  <label key={limit.key} className="row small">
                    <span style={{ minWidth: '11rem' }}>{limit.label}</span>
                    <input
                      type="number"
                      min={0}
                      placeholder="Unlimited"
                      value={draft.limits[limit.key] ?? ''}
                      onChange={(e) =>
                        setDraft({
                          ...draft,
                          limits: { ...draft.limits, [limit.key]: e.target.value },
                        })
                      }
                    />
                  </label>
                ))}
              </div>
              <span className="field__hint">
                Leave a box empty for unlimited. Nought means none allowed, which is
                different.
              </span>
            </div>

            <div className="field">
              <span className="field__label">Features</span>
              <div className="checks">
                {featureKeys.map((feature) => (
                  <label key={feature.key} className="row small">
                    <input
                      type="checkbox"
                      checked={draft.features.includes(feature.key)}
                      onChange={(e) =>
                        setDraft({
                          ...draft,
                          features: e.target.checked
                            ? [...draft.features, feature.key]
                            : draft.features.filter((f) => f !== feature.key),
                        })
                      }
                    />
                    <span title={feature.description}>{feature.key.replace(/_/g, ' ')}</span>
                  </label>
                ))}
              </div>
            </div>

            <div className="row">
              <label className="row small">
                <input
                  type="checkbox"
                  checked={draft.is_public}
                  onChange={(e) => setDraft({ ...draft, is_public: e.target.checked })}
                />
                Offered at sign-up
              </label>

              <label className="row small">
                <input
                  type="checkbox"
                  checked={draft.is_active}
                  onChange={(e) => setDraft({ ...draft, is_active: e.target.checked })}
                />
                Active
              </label>
            </div>

            <div className="row mt-3">
              <button
                type="button"
                className="btn btn--ghost btn--sm"
                onClick={() => {
                  setEditing(null)
                  setDraft(null)
                }}
              >
                Cancel
              </button>
              <button
                type="submit"
                className="btn btn--primary btn--sm"
                disabled={save.isPending || draft.name.trim() === ''}
              >
                {save.isPending ? 'Saving…' : 'Save plan'}
              </button>
            </div>
          </form>
        </section>
      )}

      <QueryState
        isLoading={plans.isLoading}
        error={plans.error}
        isEmpty={(plans.data?.data.length ?? 0) === 0}
        emptyTitle="No plans"
        emptyBody="Every organization is unmetered until a plan exists."
      >
        <section className="card">
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th>Plan</th>
                  <th className="numeric">Price</th>
                  <th>Limits</th>
                  <th>Features</th>
                  <th className="numeric">On it</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {(plans.data?.data ?? []).map((plan) => (
                  <tr key={plan.id}>
                    <td>
                      <div className="strong">{plan.name}</div>
                      <div className="mono small faint">{plan.slug}</div>
                      <div className="row mt-1">
                        {!plan.is_active && <Chip label="Inactive" colour="zinc" />}
                        {plan.is_active && !plan.is_public && (
                          <Chip label="Not offered" colour="slate" />
                        )}
                      </div>
                    </td>

                    <td className="numeric">
                      {formatMoney(plan.price)}
                      <div className="small faint">{plan.billing_interval}</div>
                    </td>

                    <td className="small faint wrap">
                      {Object.entries(plan.limits)
                        .map(([key, value]) =>
                          `${key.replace('max_', '').replace(/_/g, ' ')}: ${value === null ? '∞' : formatNumber(value)}`,
                        )
                        .join(' · ')}
                    </td>

                    <td className="small faint wrap">
                      {plan.features.length === 0 ? (
                        <span className="danger">none</span>
                      ) : (
                        plan.features.map((f) => f.replace(/_/g, ' ')).join(', ')
                      )}
                    </td>

                    <td className="numeric">{plan.organizations_count ?? 0}</td>

                    <td>
                      <div className="row">
                        <button
                          type="button"
                          className="btn btn--ghost btn--sm"
                          onClick={() => startEditing(plan)}
                        >
                          Edit
                        </button>

                        {(plan.organizations_count ?? 0) === 0 && (
                          <button
                            type="button"
                            className="btn btn--danger btn--sm"
                            onClick={() => retire.mutate(plan)}
                            disabled={retire.isPending}
                          >
                            Retire
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </QueryState>
    </>
  )
}
