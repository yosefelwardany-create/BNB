import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { Website } from '@/api/types'

/**
 * The client's Shopify store, whose own checkout takes the site's bookings.
 *
 * Each store connects through an app made for it alone in Shopify's Dev
 * Dashboard: its Client ID and secret are saved here, then someone with
 * admin rights on the store approves it on Shopify. The platform's to do; a
 * client never sees it.
 */
export function ShopifyConnect({ website, sitePath, onSaved }: { website: Website; sitePath: string; onSaved: (website: Website) => void }) {
  const queryClient = useQueryClient()
  const shopify = website.shopify
  const [shop, setShop] = useState(() => shopify?.shop_domain ?? '')
  const [clientId, setClientId] = useState(() => shopify?.client_id ?? '')
  // Never shown again once saved: typed only to set or change it.
  const [clientSecret, setClientSecret] = useState('')
  const [copied, setCopied] = useState(false)

  // Back from Shopify's approval screen, the editor's address says how it went.
  const [returned] = useState(() => new URLSearchParams(window.location.search).get('shopify'))

  const installLink = () =>
    api
      .post<{ url: string }>(`${sitePath}/shopify/install-link`, {
        shop: shop.trim().toLowerCase(),
        client_id: clientId.trim(),
        ...(clientSecret.trim() === '' ? {} : { client_secret: clientSecret.trim() }),
      })
      .then((answer) => answer.url)

  // The tab is opened at the click itself, as browsers that block a tab
  // opened later require, and sent to Shopify once the link is made.
  const connect = useMutation({
    mutationFn: (_tab: Window | null) => installLink(),
    onSuccess: (url, tab) => {
      if (tab !== null) {
        tab.location.href = url
      } else {
        window.location.assign(url)
      }
    },
    onError: (_error, tab) => tab?.close(),
  })

  const startConnecting = () => {
    const tab = window.open('', '_blank')
    // Shopify's page has no business with this one.
    if (tab !== null) tab.opener = null
    connect.mutate(tab)
  }

  const copy = useMutation({
    mutationFn: installLink,
    onSuccess: (url) => navigator.clipboard.writeText(url).then(() => setCopied(true)),
  })

  const disconnect = useMutation({
    mutationFn: () => api.delete<{ data: Website }>(`${sitePath}/shopify`),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['platform-websites'] })
      onSaved(result.data)
    },
  })

  // "Store only": the site's pages here send visitors to the store.
  const storeOnly = useMutation({
    mutationFn: (on: boolean) => api.patch<{ data: Website }>(sitePath, { store_only: on }),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['platform-websites'] })
      onSaved(result.data)
    },
  })

  if (shopify === undefined) {
    return null
  }

  const failure = connect.error ?? copy.error
  const apiFailure = failure instanceof ApiError ? failure : null
  const fieldError = (field: string) => apiFailure?.fieldError(field)
  const otherError = failure !== null && fieldError('shop') === undefined && fieldError('client_id') === undefined && fieldError('client_secret') === undefined
    ? (apiFailure?.message ?? 'The install link could not be made.')
    : null
  const connected = shopify.status === 'connected'
  const ready = shop.trim() !== '' && clientId.trim() !== '' && (shopify.has_keys || clientSecret.trim() !== '')

  return (
    <section className="stack" aria-label="Shopify checkout">
      <h4>Shopify checkout</h4>
      <p className="small faint">
        Connect the client's Shopify store, and guests pay on the store's own checkout instead of here. The money goes to
        the client's Shopify account.
      </p>

      {returned === 'connected' && <p className="notice small" role="status">Shopify store connected.</p>}
      {returned === 'failed' && <p className="notice notice--error small" role="alert">Shopify did not connect the store. Try again.</p>}

      {connected && (
        <>
          <p className="strong">
            Connected to {shopify.shop_name ?? shopify.shop_domain} ({shopify.shop_domain}) · {shopify.currency}
          </p>
          {shopify.currency_matches ? (
            <p className="small faint">Guests pay on the store's own checkout.</p>
          ) : (
            <p className="notice small">
              This store sells in {shopify.currency}, and the homes are priced in another currency. Guests are asked to get in
              touch until they match.
            </p>
          )}
          <div className="row">
            <button type="button" className="btn btn--sm" disabled={disconnect.isPending} onClick={() => disconnect.mutate()}>
              Disconnect
            </button>
          </div>
        </>
      )}

      {(connected || website.store_only === true) && (
        <div className="field">
          <label className="row small">
            <input
              type="checkbox"
              checked={website.store_only === true}
              disabled={storeOnly.isPending}
              onChange={(event) => storeOnly.mutate(event.target.checked)}
            />
            <span>
              <span className="strong">Store only</span>: the site's pages here send visitors to the store. The pieces on the
              store, the pop-up and guests' booking pages keep working.
            </span>
          </label>
          {storeOnly.error !== null && (
            <span className="field__error" role="alert">
              {storeOnly.error instanceof ApiError ? storeOnly.error.message : 'Store only could not be changed.'}
            </span>
          )}
        </div>
      )}

      {!connected && (
        <>
          {shopify.status === 'broken' && (
            <p className="notice notice--error small" role="alert">
              The store's connection stopped working{shopify.last_error ? `: ${shopify.last_error}` : '.'} Connect it again.
            </p>
          )}
          <div className="field">
            <label className="field__label" htmlFor="site-shopify-shop">Store's Shopify address</label>
            <input
              id="site-shopify-shop"
              className="input"
              placeholder="your-store.myshopify.com"
              value={shop}
              onChange={(event) => {
                setShop(event.target.value)
                setCopied(false)
              }}
            />
            <span className="field__hint">
              Someone with admin rights on the store approves the connection on Shopify: you, with staff access, or the client
              from the copied link.
            </span>
            {fieldError('shop') !== undefined && <span className="field__error">{fieldError('shop')}</span>}
          </div>
          <p className="small faint">
            Each store connects through an app made for it alone in Shopify's Dev Dashboard. The steps are in
            docs/shopify-app-setup.md.
          </p>
          <div className="field">
            <label className="field__label" htmlFor="site-shopify-client-id">App's Client ID</label>
            <input
              id="site-shopify-client-id"
              className="input mono"
              autoComplete="off"
              value={clientId}
              onChange={(event) => {
                setClientId(event.target.value)
                setCopied(false)
              }}
            />
            {fieldError('client_id') !== undefined && <span className="field__error">{fieldError('client_id')}</span>}
          </div>
          <div className="field">
            <label className="field__label" htmlFor="site-shopify-client-secret">App's Client secret</label>
            <input
              id="site-shopify-client-secret"
              className="input mono"
              type="password"
              autoComplete="new-password"
              placeholder={shopify.has_keys ? 'Saved: type only to change it' : ''}
              value={clientSecret}
              onChange={(event) => {
                setClientSecret(event.target.value)
                setCopied(false)
              }}
            />
            {fieldError('client_secret') !== undefined && <span className="field__error">{fieldError('client_secret')}</span>}
          </div>
          {otherError !== null && <p className="field__error small" role="alert">{otherError}</p>}
          <div className="row">
            <button type="button" className="btn btn--sm btn--primary" disabled={connect.isPending || !ready} onClick={startConnecting}>
              Connect Shopify
            </button>
            <button type="button" className="btn btn--ghost btn--sm" disabled={copy.isPending || !ready} onClick={() => copy.mutate()}>
              {copied ? 'Copied' : 'Copy install link'}
            </button>
          </div>
        </>
      )}
    </section>
  )
}
