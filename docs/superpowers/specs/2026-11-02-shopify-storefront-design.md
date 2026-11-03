# Booking websites — stage 3: homes on a client's Shopify store

**Status:** design approved in conversation (2026-11-02), awaiting review of
this document · **Date:** 2026-11-02 ·
**Follows:** `2026-10-29-booking-websites-stage-1-design.md`,
`2026-10-30-booking-websites-stage-2-editor-design.md`

## Why

Some clients already sell from their own Shopify store. They want their homes
shown and booked there, on their own pages, instead of on the booking website
at the platform's address. The guest should pay on the store's own Shopify
checkout, so the money goes to the client's Shopify account.

Shopify cannot price a stay by the night or know which nights are free, so
Habitat keeps doing that. The store shows Habitat's search, homes and booking
box, and hands the guest to Shopify's checkout for exactly the price Habitat
worked out.

While designing this, a second problem was found in owner statements: a fixed
monthly fee is charged once per *statement*, not once per *month*. Clients on
quarterly or weekly statements are charged the wrong amount. The fix ships
first, as its own PR (part 5).

## Decisions taken

| Question | Answer |
|---|---|
| Where guests book | On the client's Shopify pages. **They pay on the store's own Shopify checkout**, and the money goes to the client's Shopify account |
| What the store shows | **Pieces placed anywhere** with short tags: a search bar, a homes grid, a booking box for one home |
| A home's details | A **pop-up** over the store page by default. If a home has a page on the store, the grid links there instead |
| Look | **The site's theme from the page builder.** No header or footer, and a see-through background |
| Who sets it up | **Only the platform owner**, from the site's details. The store itself is connected through Shopify's approval screen, approved by someone with admin rights on that store |
| How it is built | **A loader script that places framed pieces** (approach 1 of 3), handing over to Shopify's checkout through a one-off Shopify order (a "draft order") |
| Payment timing | **Follows each site's setting:** the full amount at booking, or a deposit and then the balance |
| Request-to-book homes | The request is sent **without paying**. Once accepted, the guest is emailed a Shopify payment link |
| The platform's fee | Each client's **management agreement**, as today. For Shopify clients it is a fixed monthly fee per home. Statements take off the money the client already received through Shopify |
| Fixed fee per home | Charged **once per home per month**, whatever the statement period. Shown to the client in the portal, **never on Shopify** |
| The hosted site | **"Store only":** the site's pages at the platform's address send visitors to the store. Pieces, pop-up, guest booking pages and the editor keep working |

The two approaches not taken:

- **Drawing the pieces into the store's own page.** The booking box and
  checkout would have to be rebuilt a second time, and the store's styles
  would clash with ours.
- **A Shopify App Store app with theme blocks.** It needs Shopify's review and
  helps only Shopify stores. Under the hood it would be this design anyway, so
  it can be added later.

## Part 1 — The guest's path

### The pieces

Two things go on the store:

1. **One line, added once** to the Shopify theme, before `</body>` in
   `theme.liquid`:
   `<script src="https://<platform address>/sites/embed.js" defer></script>`
2. **A tag wherever a piece should appear.** Every tag names the site:
   - **Search bar:** `<div data-habitat-site="harbour" data-habitat-piece="search"></div>`.
     It has dates and guests.
   - **Homes grid:** `<div data-habitat-site="harbour" data-habitat-piece="homes"></div>`.
     It shows the site's homes in the Homes tab's order, with prices once
     dates are known.
   - **Booking box:** `<div data-habitat-site="harbour" data-habitat-piece="book" data-habitat-home="harbour-loft"></div>`.
     It has the calendar, the price and **Book**. `data-habitat-home` is the
     home's address on the site, the same one `stay/{listing}` uses.

How the pieces look and behave:

- Each piece is an `<iframe>` of a page drawn by Habitat. It wears the site's
  published theme and style (fonts, colours, button shape), with no header,
  no footer and a transparent background.
- A frame grows and shrinks to fit its content.
- Dates and guests in the store page's own address (`?checkin=&checkout=&guests=`)
  are passed to every piece on that page.

### Where clicks go

- **Search bar:** choosing dates reloads every homes grid on the same page
  with those dates. If the page has no grid, the results open in the pop-up.
- **A home in the grid:** opens the **pop-up** with that home's page (photos,
  details, map, reviews, booking box). If the home has a **page on the
  store**, the whole store page goes there instead, carrying the dates.
- **Book** (in the pop-up or in a booking box on the page) continues in the
  pop-up.
- **The pop-up** is a full-screen frame over the store page. It closes with
  its close button or Esc.

### Booking and paying on Shopify

1. **Your details**, in the pop-up: name, email, phone, a message, and
   agreement to the house rules and cancellation policy. This is the existing
   checkout step, without the site's header and footer.
2. **Continue to payment:**
   - Habitat holds the nights for the site's hold time (`hold_minutes`), as
     today.
   - It creates a Shopify draft order for the stay:
     - one custom line, such as "Harbour Loft · 3–6 June 2026 · 2 guests";
     - the price is what the site's setting says is due now (the full amount
       or the deposit);
     - quantity 1, not taxable, needs no shipping;
     - the guest's email;
     - a note with the booking reference;
     - the tag `Habitat booking`;
     - a custom attribute `habitat_checkout` holding the checkout's id.
   - The whole store page then moves to the draft order's checkout link
     (`invoiceUrl`).
3. **The guest pays on Shopify**, however the store takes payments. Shopify
   shows its thank-you page and sends its own receipt.
4. **Shopify reports the order paid.** Habitat confirms the booking exactly
   as a Stripe payment does today: reservation, calendar block, our
   confirmation email with the guest's booking page, and the new-booking
   email to the host.
5. **Deposit sites:** when the balance falls due, Habitat creates a second
   draft order for the rest. Its link goes in the PayBalance email, and the
   **Pay balance** button on the booking page opens it. The button creates
   the order if it does not exist yet.
6. **Request-to-book homes:**
   - Step 2 sends the request without payment. The checkout goes to
     `requested` and the nights stay held until the site's answer deadline, as
     today.
   - **Accept** creates the draft order and emails the guest a payment link
     ("Your request is accepted, pay here to confirm"). The nights stay held
     for `request_hours` from acceptance.
   - Unpaid by then, the nights are freed and the draft order is deleted.
   - **Decline** works as today. No money has moved.
7. **Not paid in time:**
   - When a hold lapses, Habitat frees the nights and deletes the draft order,
     so its checkout link stops working. A failed delete is retried.
   - If a payment still arrives for a lapsed checkout, Habitat holds the same
     nights again if they are free and confirms the booking.
   - If the nights were taken, it raises the **late payment alert**
     (part 3).

**Sites not connected to Shopify** keep today's Stripe checkout. In the pop-up
it runs exactly as on the hosted site: checkout page, then the booking page
with Stripe's card form. 3D Secure opens inside the frame.

## Part 2 — Connecting a store, and keeping it safe

### One Shopify app per client store (decided 2026-11-03)

- Shopify stopped letting merchants create custom apps in their own admin on
  1 January 2026. Apps are now made in Shopify's **Dev Dashboard**.
- An app with custom distribution installs on **one store** (or the stores of
  one Shopify Plus organisation). So the platform owner makes **one small app
  for each client's store**: custom distribution for that store, with the
  scopes `write_draft_orders` and `read_orders`, and the redirect URL
  `{APP_URL}/integrations/shopify/callback`. `docs/shopify-app-setup.md`
  gives the steps.
- The app's **Client ID and Client secret are kept on the store's connection**.
  The secret is encrypted, never returned by any API, and need not be typed
  again for the same app.
- The install link, the token exchange, token refreshes, the callback's
  signature and every webhook's signature all use that store's own keys.
  Webhooks pick the secret by the shop header, then verify the signature.
- The alternative, one public app passing Shopify's review, was declined:
  stores could not connect until Shopify approved it.
- Each app declares Shopify's three compliance webhooks (customer data
  request, customer redact, shop redact). Habitat answers each with 200 after
  checking its signature. It keeps nothing from Shopify beyond the booking
  itself.

### Connecting a client's store (platform only)

The platform's site details get a **Shopify** section. It is never in the
client portal or its API.

1. The platform owner types the store's `*.myshopify.com` address and the
   Client ID and secret of the app made for it, and presses **Connect Shopify**.
2. Shopify's approval screen opens at
   `https://{shop}/admin/oauth/authorize`, with the scopes, a `redirect_uri`
   on the platform's address and a signed `state`.
   - The `state` names the website, lasts one hour and works once.
   - **Copy install link** gives the same link to send to the client, if
     someone else must approve it.
3. **The callback** checks, in this order:
   - Shopify's `hmac` on the query;
   - the `state`;
   - that `shop` matches `^[a-z0-9][a-z0-9-]*\.myshopify\.com$`.

   It then exchanges the code for the access token. A failed check shows a
   plain error and saves nothing.
4. Habitat then:
   - stores the token **encrypted**. It is never returned by any API;
   - reads the shop's name, currency, `myshopify` address and domains;
   - subscribes to `ORDERS_PAID`, `REFUNDS_CREATE` and `APP_UNINSTALLED`.
5. **Currency check:** a store whose currency differs from the site's homes
   is connected, but its checkout stays off. The section says why.
6. The section then shows:
   - "Connected to Harbour Homes (harbourhomes.com) · EUR" and
     **Disconnect**;
   - the **addresses where pieces may appear**, filled from the store's
     domains. The owner can add or remove one: `https://` origins only, at
     most 10;
   - the **"Store only"** switch (part 4);
   - **ready-to-copy snippets:** the script line, the search bar, the grid,
     and a booking box for each home on the site.

The allowed addresses are a site setting of their own. A site with no store
connected can list addresses too, which is how the pieces work on a
WordPress or Wix site paying with Stripe.

**Each home's page on the store:** the Homes tab (platform only) gets an
optional "Page on the store" address per home. It must be `https://` and on
one of the allowed addresses.

**Once connected, every booking of that site pays on Shopify,** including one
started on the site at the platform's address. Stripe is not used for that
site.

### When a connection breaks

- The connection breaks when `APP_UNINSTALLED` arrives, when the platform
  owner presses **Disconnect**, or when Shopify refuses the token (401 or
  403). The connection is then marked broken and checkout switches off.
- **Book** then shows "Online booking is unavailable right now", with the
  site's phone and email, and no checkout is started.
- The Websites screen shows a warning on that site.
- Holds already open still lapse normally.

### Safety

- **Frames:**
  - Every embed page sends
    `Content-Security-Policy: frame-ancestors <the site's allowed addresses>`,
    which takes the place of the server's `X-Frame-Options: DENY`.
  - A site with no allowed addresses sends `frame-ancestors 'none'`.
  - Framed anywhere else, the pieces do not load.
- **Messages between frame and store page:**
  - The loader accepts a message only when it comes from the platform's
    origin *and* from one of its own frames.
  - A frame says only four things: its height, "open the pop-up", "close",
    and "go to this address".
  - The loader follows "go" only to an `https` address on the store's
    addresses or its `myshopify` address. The frame lists these when it
    loads.
- **Messages from Shopify:**
  - Webhooks are checked against `X-Shopify-Hmac-Sha256` (HMAC-SHA256 of the
    raw body with the client secret) before anything is read, and
    deduplicated by `X-Shopify-Webhook-Id`.
  - A paid order is matched to its checkout by the `habitat_checkout`
    attribute and must belong to the connected shop.
  - **The order's total and currency must equal what Habitat asked for.** A
    mismatch is flagged for the platform and the client, and not confirmed.
- **No cookies:** the site pages already use no session and no cookie, so
  nothing breaks in browsers that block cookies inside frames. Checkout
  requests from a frame are same-origin, so the existing refusal of
  `Sec-Fetch-Site: cross-site` holds requests stays.
- **Not indexed:** embed pages send `X-Robots-Tag: noindex`.

## Part 3 — Money, records and refunds

### Recording a Shopify payment

- A paid Shopify order is recorded with `recordExternalPayment`:
  - provider `shopify`;
  - reference the order's name (`#1042`), linked to the order in the store's
    admin;
  - `is_collected_by_us = false`;
  - and new: `is_collected_by_owner = true`.
- The books then treat it as money the client holds, not money in the
  platform's bank, as Airbnb-collected money is already treated.
- The booking shows "Received by the client through Shopify · order #1042".
- A deposit and its balance are two orders and two payments.
- **Shopify's card fees** are between the client and Shopify. Habitat records
  a fee of 0 and does not show them.

### Refunds and cancellations

- **Money moves in Shopify only.** The client, or the platform owner with
  staff access, refunds from the order page in Shopify.
- `REFUNDS_CREATE` records each refund against the booking, owner-collected
  like the payment.
- **A refund never cancels a booking by itself:** a partial refund may be a
  goodwill gesture.
- **Cancelling a booking in Habitat moves no money.** A booking paid on
  Shopify then shows "Refund on Shopify: €240 due under the cancellation
  policy", with a link to the order. The amount is
  `ReservationService::calculateCancellationRefund`, the same figure used for
  a Stripe booking.

### The late payment alert

A payment that cannot be confirmed raises an alert in Habitat's notifications
for the platform and the client, and emails the site's contact address. It
fires when:

- the nights were taken in the meantime;
- the amount or currency does not match;
- the checkout no longer exists.

The alert says "Paid on Shopify, but … Refund order #1043." and links to the
order.

### Owner statements

- A new statement line category, **"Already received through Shopify"**
  (`owner_collected`). It totals owner-collected payments less their refunds,
  and is taken off the amount due:

  `net due = gross − taxes − channel commission − payment fees − management fee − expenses + adjustments − owner collected`

- **Each payment is taken off once, and each refund is added back once.** It
  goes on the statement that carries its booking's revenue. If that statement
  has already been issued, it goes on the next one.
  - It is marked with the statement's id, as expenses already are, so it is
    never taken off twice.
- **The fee is unchanged:** it comes from the client's management agreement
  as today.
- **A negative balance** is what the client owes the platform. It is shown on
  the statement and carried forward, as a deficit already is.
- **Example:** June has €3,000 of Shopify bookings and a €150 fixed fee.
  - Revenue €3,000, fee −€150, already received through Shopify −€3,000.
  - Balance: **€150 owed to the platform**.

## Part 4 — "Store only"

A switch in the Shopify section, available once a store is connected.

**When it is on:**

- These pages at the platform's address answer with a **temporary redirect
  (302)** to the store:
  - the home page;
  - the site's own pages;
  - search;
  - `stay/{listing}`, which goes to the home's store page if set, otherwise
    to the store's primary domain.
- The sitemap lists nothing. `robots.txt` stays as it is, so search engines
  can follow the redirects.

**These keep working:**

- embed pages and the pop-up;
- `checkout/{quote}`, `booking/{token}` and `pay-balance`;
- the calendar, quote and checkout calls;
- photos, media and images;
- the editor's preview.

**On the guest's booking page,** the brand link and menu point to the store's
primary domain.

Turning the switch off brings the pages straight back. Breaking the
connection does not turn it off: the pages keep pointing to the store.

## Part 5 — The fixed monthly fee, per home, per month

**What happens today:** `OwnerStatementBuilder::forProperty` already charges
an agreement's fee once per home, and the PDF names the home. But
`FIXED_MONTHLY` charges `commission_amount` once per *statement*. So:

- quarterly statements charge one month in three;
- weekly statements charge every week.

**The change:**

- **A fixed monthly fee is charged once for each calendar month whose last
  day falls inside the statement period** and on which the client held the
  home.
  - Each month uses the agreement in force on that month's last day.
  - Monthly statements charge one fee per home and quarterly statements
    three. A weekly statement charges only when it includes a month's last
    day.
  - A home sold before the month's last day is not charged for that month.
- **The line explains itself.** For example: "Fixed fee €50 a month ×
  3 months", next to the home's name on the PDF and in the portal.
- **The client portal shows a statement's lines.** Each issued statement on
  the Money page opens to show:
  - each home's fee;
  - "Already received through Shopify";
  - the opening balance and the balance owed.

  Drafts stay hidden from clients, as today.
- **Never on Shopify:** the fee is between the platform and the client. It is
  never added to a guest's Shopify order and never shown to guests.

Statements already issued are not rebuilt. The change applies to drafts built
from now on.

## Data

| Where | What |
|---|---|
| `websites` | `embed_origins` jsonb: up to 10 `https` origins. `store_only` boolean, default false |
| `website_listings` | `store_url` string, nullable: the home's page on the store |
| `website_shopify_connections` (new) | One per website: `shop_domain`, `shop_name`, `currency`, `domains` jsonb, `access_token` (encrypted cast), `scopes`, `status` (`connected`, `broken`, `disconnected`), `connected_at`, `broken_at`, `last_error` |
| `website_shopify_orders` (new) | One per Shopify order Habitat asks for: `website_checkout_id`, `purpose` (`stay`, `balance`), `draft_order_id`, `invoice_url`, `order_id`, `order_name`, `amount`, `currency`, `status` (`open`, `paid`, `deleted`, `failed`) |
| `website_checkouts` | `payment_channel` (`stripe`, `shopify`): fixed when the checkout starts, so a site connected mid-checkout does not change a guest's checkout |
| `payments` | `is_collected_by_owner` boolean, default false. `owner_statement_id`, nullable, where the payment was taken off |
| `owner_statement_lines` | New category `owner_collected` |

**Routes (new):**

- `GET /sites/{site}/embed/{piece}`: `search`, `homes`, `book/{listing}`,
  `stay/{listing}`, `results`;
- `GET /sites/{site}/embed/checkout/{quote}`;
- `GET /sites/{site}/embed/booking/{token}`;
- `/sites/embed.js` (static);
- `GET /integrations/shopify/callback`;
- `POST /webhooks/shopify`;
- platform API: connect, install link, disconnect, embed settings, store-only
  switch, and each home's store page, under the existing platform websites
  routes;
- portal API: an issued statement's lines.

## Testing

- **Server tests,** with Shopify faked as Stripe is today:
  - connecting, and refusing a bad `hmac`, a reused or expired `state` and a
    bad shop address;
  - a paid order confirms the booking;
  - a wrong amount is flagged;
  - a late payment confirms if the nights are free, otherwise alerts;
  - refunds are recorded;
  - `APP_UNINSTALLED` and a 401 break the connection;
  - the draft order's price, line, attribute and tag;
  - deletion when a hold lapses, and the retry when deletion fails;
  - deposit balance orders;
  - the request flow;
  - the statement line, take-off-once, and the balance owed;
  - the fixed monthly fee across monthly, quarterly and weekly periods and a
    mid-month sale;
  - the `frame-ancestors` header for listed, unlisted and empty addresses;
  - "store only" redirects and every exception.
- **Loader tests** (Vitest, jsdom):
  - tags become frames, and frames resize;
  - the pop-up opens, closes and responds to Esc;
  - search reloads grids;
  - "go" is followed only to allowed addresses;
  - messages from other origins and other frames are ignored.
- **Browser run** (Playwright): a fake store page on a different origin with
  all three pieces, from search through the details step to the hand-over to
  a fake Shopify checkout.
- **Live check** on a free Shopify development store, once the platform's app
  exists. Like the Stripe live check, it waits on the platform owner. It
  confirms these Shopify behaviours, which the design relies on but which have
  not yet been tried:
  - an invoice checkout with `requiresShipping: false` skips shipping;
  - custom attributes reach the order's `note_attributes`;
  - `ORDERS_PAID` fires for an invoice checkout;
  - a deleted draft order's link stops working;
  - development stores are limited to five draft orders a minute.

## Delivery

Four PRs into the default branch, each merged when the suite and CI pass:

1. **Fixed monthly fee per home per month**, and statement lines in the
   client portal (part 5).
2. **Pieces on any website:**
   - `embed.js`, the three pieces and the pop-up;
   - the allowed addresses and `frame-ancestors`;
   - the snippets, and each home's store page;
   - Stripe checkout in the pop-up for sites without Shopify.
3. **Shopify checkout:**
   - the app settings and connecting;
   - draft orders for stays, deposits, balances and requests;
   - paid-order confirmation, lapses with deletion, and the safety checks.
4. **After the booking:**
   - refunds;
   - the late payment alert;
   - the "Already received through Shopify" statement line;
   - "store only";
   - broken-connection handling and warnings.

## Not included

- Listing the app in Shopify's App Store, or theme app blocks.
- Shopify products for homes, or syncing homes into Shopify.
- Shopify adding its own tax to a stay.
- Refunds started from Habitat.
- Shopify's card fees on statements.
- Pro-rating a fixed fee for part of a month.
- The client connecting their own store, or seeing the Shopify section.
