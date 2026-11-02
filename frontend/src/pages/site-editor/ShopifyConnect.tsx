import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { Website } from '@/api/types'

/**
 * The client's Shopify store, whose own checkout takes the site's bookings:
 * connecting it through Shopify's approval screen, and seeing whether it
 * works. The platform's to do; a client never sees it.
 */
export function ShopifyConnect({ website, sitePath, onSaved }: { website: Website; sitePath: string; onSaved: (website: Website) => void }) {
  const queryClient = useQueryClient()
  const shopify = website.shopify
  const [shop, setShop] = useState(() => shopify?.shop_domain ?? '')
  const [copied, setCopied] = useState(false)

  // Back from Shopify's approval screen, the editor's address says how it went.
  const [returned] = useState(() => new URLSearchParams(window.location.search).get('shopify'))

  const installLink = () =>
    api.post<{ url: string }>(`${sitePath}/shopify/install-link`, { shop: shop.trim().toLowerCase() }).then((answer) => answer.url)

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

  if (shopify === undefined) {
    return null
  }

  const failure = connect.error ?? copy.error
  const shopError = failure instanceof ApiError ? (failure.fieldError('shop') ?? failure.message) : failure !== null ? 'The install link could not be made.' : null
  const connected = shopify.status === 'connected'

  return (
    <section className="stack" aria-label="Shopify checkout">
      <h4>Shopify checkout</h4>
      <p className="small faint">
        Connect the client's Shopify store, and guests pay on the store's own checkout instead of here. The money goes to
        the client's Shopify account.
      </p>

      {returned === 'connected' && <p className="notice small" role="status">Shopify store connected.</p>}
      {returned === 'failed' && <p className="notice notice--error small" role="alert">Shopify did not connect the store. Try again.</p>}

      {!shopify.configured && (
        <p className="notice small">
          Add SHOPIFY_CLIENT_ID and SHOPIFY_CLIENT_SECRET to the server's settings to connect a store. The steps are in
          docs/shopify-app-setup.md.
        </p>
      )}

      {shopify.configured && connected && (
        <>
          <p className="strong">
            Connected to {shopify.shop_name ?? shopify.shop_domain} ({shopify.shop_domain}) · {shopify.currency}
          </p>
          {shopify.currency_matches ? (
            <p className="small faint">Guests pay on the store's own checkout.</p>
          ) : (
            <p className="notice small">
              This store sells in {shopify.currency}, and the homes are priced in another currency. Bookings stay on Stripe
              until they match.
            </p>
          )}
          <div className="row">
            <button type="button" className="btn btn--sm" disabled={disconnect.isPending} onClick={() => disconnect.mutate()}>
              Disconnect
            </button>
          </div>
        </>
      )}

      {shopify.configured && !connected && (
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
            {shopError !== null && <span className="field__error">{shopError}</span>}
          </div>
          <div className="row">
            <button type="button" className="btn btn--sm btn--primary" disabled={connect.isPending || shop.trim() === ''} onClick={startConnecting}>
              Connect Shopify
            </button>
            <button type="button" className="btn btn--ghost btn--sm" disabled={copy.isPending || shop.trim() === ''} onClick={() => copy.mutate()}>
              {copied ? 'Copied' : 'Copy install link'}
            </button>
          </div>
        </>
      )}
    </section>
  )
}
