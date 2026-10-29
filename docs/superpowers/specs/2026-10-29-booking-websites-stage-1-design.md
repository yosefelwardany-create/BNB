# Booking websites — stage 1: a bookable guest site with Stripe

**Status:** design, awaiting review · **Date:** 2026-10-29

## Why

Habitat has a pricing engine that can quote any dates, an availability engine,
a reservation service and "direct" as a channel, but no way for a guest to
find a property and book it without going through an OTA. Lodgify's website
builder is the model: a host publishes a site, guests search, see a property,
get an exact price and book and pay on it, and the booking lands in the same
calendar that feeds every channel.

The full builder is seven stages. This document is the first:

| Stage | What |
|---|---|
| **1 (this)** | **A bookable guest site on one built-in template, with Stripe** |
| 2 | The editor: templates, styles, pages and sections, preview and publish |
| 3 | More templates; a page per policy; custom HTML sections |
| 4 | Languages: translated site text and property content |
| 5 | Full SEO controls: per-page titles, structured data, Google Vacation Rentals |
| 6 | Custom domains with automatic certificates |
| 7 | Embeddable search and booking widgets for other websites |

## Decisions already taken

| Question | Answer |
|---|---|
| What first | The guest booking site, ahead of the editor |
| Payments | Stripe, in this stage |
| Whose sites | Both: one **brand site** across every client account, and optional **client sites** with only that client's properties |
| Whose Stripe | One Stripe account, the platform owner's, for every site; owners are paid through Habitat's existing statements and payouts |
| Where sites live | On Habitat's own address, `…/sites/<name>`; domains come in stage 6 |
| How pages are built | Server-rendered HTML from Laravel, with small scripts for the calendar, booking box and Stripe card form (approach A) |

## What a guest can do

- **Home** — hero photo, heading and tagline, the search bar, featured
  properties.
- **Search** — check-in, check-out and guests; filters for price, bedrooms,
  bathrooms and amenities. With dates, each result shows the total for those
  dates and only properties free for them appear; without dates, results show
  a "from" nightly price.
- **Property** — photo gallery, title, summary and description, rooms and
  beds, amenities, house rules, check-in and check-out times, approximate
  location on an OpenStreetMap map (never the exact address before a booking
  is paid, the same rule as the guest portal), published reviews, a two-month
  availability calendar, and the booking box.
- **Booking box** — pick dates and guests, see the exact breakdown (nights,
  fees, taxes, total) from the pricing engine, then **Book now** for an
  instant-book property or **Request to book** otherwise.
- **Checkout** — name, email, phone, guests, optional message, the price
  breakdown, the cancellation policy and house rules to accept, then Stripe's
  card form.
- **Confirmation** — booking reference, dates, what was paid and what is due
  when; the same page is the guest's secret link afterwards and offers **Pay
  balance** when an instalment is outstanding.

## Architecture

```
Browser ── /sites/{site}/…  ──► Sites controllers (HTML, Blade)
        └─ /sites/{site}/api/… ► Sites JSON (quote, calendar, checkout)
Stripe ─── /webhooks/stripe ──► StripeWebhookController
Habitat app ─ /api/v1/websites… ► WebsiteController (staff, platform admin)

app/Domain/Websites/
  Models/      Website, WebsiteListing, WebsiteCheckout, ProcessedProviderEvent
  Services/    WebsiteCatalogue   — what a site shows; search and filters
               WebsiteCheckoutService — hold, pay, confirm, accept, decline, expire
               WebsiteReadiness   — Stripe, storage, email, properties
  Jobs/        ReleaseExpiredHolds (scheduled every minute)
  Mail/        Guest and host emails
app/Domain/Integrations/
  Support/StripeClient            — Laravel HTTP client, like ChannexClient
  Providers/Payments/StripePaymentProvider — implements PaymentProviderInterface
resources/views/sites/default/    — the built-in template
public/sites/                     — its CSS and the booking-box/checkout script
```

**Rendering.** Laravel renders every guest page as HTML, so search engines and
link previews see real content and phones get a fast first page. No new
service: Caddy already sends everything outside `/app/*` to PHP. The editor in
stage 2 previews by rendering the same views.

**Tenancy.** Websites are platform-level records, managed by platform
administrators, not scoped to one organization: a brand site spans client
accounts. A public request resolves the site by its slug, reads only the
listings attached to that site (each row carries its organization), and does
every quote, hold and booking inside `TenantContext::runAs()` for the
listing's own organization. Nothing on a public page is read through a
tenant-scoped query without a bound tenant, so fail-closed tenancy still
holds.

**One unit, one purpose.**

- `WebsiteCatalogue` answers "what does this site show": its live listings,
  search with filters, one listing's page data. It never writes.
- `WebsiteCheckoutService` owns a checkout from hold to confirmed, declined or
  expired. It is the only writer of `website_checkouts`.
- `StripeClient` speaks HTTP to Stripe and nothing else; `StripePaymentProvider`
  maps Habitat's payment contract onto it, so refunds and balance charges go
  through `PaymentService` like every other payment.
- `StripeWebhookController` verifies the signature, records the event id once,
  and hands the event to `WebsiteCheckoutService`.

## Data

New tables. No existing table changes shape except `reservations` (one
nullable column) and `guests` (saved-card reference).

**`websites`**

| Column | Notes |
|---|---|
| id, slug (unique), name | slug is the address: `/sites/{slug}` |
| kind | `brand` or `client` |
| organization_id | null for the brand site; the client's account for a client site |
| status | `draft` or `live`; anything not live answers 404 to the public |
| settings (jsonb) | logo and hero photo paths, primary and accent colour, heading, tagline, contact email, phone, address line, social links |
| hold_minutes | default 30 |
| request_hours | default 24 |
| published_at, timestamps | |

**`website_listings`** — website_id, listing_id, organization_id, position,
is_featured. A client site may only hold its own organization's listings; a
brand site any organization's. A listing appears publicly only while it is
`published` and its property is active.

**`website_checkouts`** — one per booking attempt.

| Column | Notes |
|---|---|
| id | ULID, internal only |
| access_token | 48 random characters; the guest's secret link is `/sites/{site}/booking/{access_token}` |
| website_id, organization_id, listing_id, reservation_id, quote_id | |
| mode | `instant` or `request` |
| guest (jsonb) | name, email, phone, guests, message, accepted policies at |
| currency, total_amount, due_now_amount | minor units |
| stripe_payment_intent_id, stripe_customer_id | |
| status | `holding`, `paid`, `requested`, `confirmed`, `declined`, `expired`, `refunded`, `failed` |
| hold_expires_at, client_fingerprint (hashed IP + user agent) | |

**`processed_provider_events`** — provider, event_id (unique), type,
processed_at. A redelivered Stripe event is a no-op.

**`reservations.website_id`** — nullable; which site a booking came from, for
reports. The source stays `direct`.

**`guests`** — Stripe customer and saved payment-method ids, never card data.

## The booking flow

**Instant book**

1. The booking box posts dates and guests to
   `/sites/{site}/api/listings/{listing}/quote`. `QuoteService` prices it on the
   `direct` channel and the response shows nights, fees, taxes and total.
2. Checkout posts the guest's details and the quote to
   `/sites/{site}/api/checkouts`. Inside one transaction
   `WebsiteCheckoutService` re-checks availability, creates a **Tentative**
   reservation with `hold_expires_at = now + 30 min` (which marks the
   listing dirty, so Channex closes the nights within seconds), builds the
   payment schedule (`PaymentScheduleService::standardPlan`) and stores the
   checkout.
3. It creates a Stripe Customer and a PaymentIntent for the **amount due now**
   (the first instalment), in the property's currency, with
   `setup_future_usage=off_session` so the card can pay the balance, and
   returns the client secret. The page mounts Stripe's Payment Element
   (`js.stripe.com/v3`), which handles 3-D Secure; card numbers never reach
   Habitat.
4. Stripe confirms in the browser and sends `payment_intent.succeeded` to
   `/webhooks/stripe`. The webhook is the source of truth; the browser's
   return only shows the result. On success the service, in one transaction:
   records the payment through `PaymentService` as a captured Stripe payment
   (with Stripe's fee when the charge reports it), settles the first
   instalment, saves the customer and payment method on the guest, confirms
   the reservation, clears the hold, and queues the emails.

**Request to book**

Steps 1–3 as above, except the PaymentIntent uses `capture_method=manual` and
the hold lasts `request_hours` (24). `payment_intent.amount_capturable_updated`
moves the checkout to `requested`, the payment is recorded as authorized, and
the host is emailed. In Habitat the booking shows **Accept** and **Decline**:

- **Accept** captures the PaymentIntent, settles the instalment and confirms.
- **Decline** cancels the PaymentIntent, cancels the reservation (the nights
  reopen and Channex is told), and emails the guest.
- **No answer by the deadline** is a decline, done by `ReleaseExpiredHolds`.

**The balance.** `payments:process-due-schedules` already charges instalments
on their due date. `StripePaymentProvider::capture` without an authorization
creates and confirms an off-session PaymentIntent with the guest's saved
payment method. When that fails (card expired, bank asks for authentication)
the instalment is marked failed as now, and the guest is emailed the secret
link, whose **Pay balance** button runs the same Payment Element on-session.

**Cancellations and refunds.** Unchanged entry point: cancelling a booking in
Habitat uses the snapshotted cancellation policy to work out the refund, and
`PaymentService::refund` sends it to Stripe (`POST /v1/refunds`).

## When things go wrong

| Situation | Behaviour |
|---|---|
| Card declined or 3-D Secure fails | Stripe's message is shown; the guest may retry on the same PaymentIntent until the hold ends |
| Guest abandons checkout | `ReleaseExpiredHolds` (every minute) cancels the PaymentIntent, cancels the tentative reservation and marks the checkout `expired` |
| Nights sold elsewhere before the hold | The availability re-check in step 2 refuses: "Those dates were just booked", no hold, no PaymentIntent |
| Payment succeeds after the hold expired | If the nights are still free the booking is re-created and confirmed; if not, the payment is refunded in full automatically and both guest and host are told |
| Stripe sends the same event twice | `processed_provider_events` makes the second a no-op |
| A forged webhook | Refused: the `Stripe-Signature` HMAC (SHA-256 over `timestamp.payload`, five-minute tolerance) must match `STRIPE_WEBHOOK_SECRET` |
| Stripe not configured | Properties still show; Book is replaced by the site's contact details; the site cannot be published (readiness check) |
| Stripe unreachable when creating the PaymentIntent | The hold is released at once and the guest is told to try again in a moment |
| One visitor holding many dates | At most 3 active holds per visitor fingerprint per site, plus rate limits on every public endpoint |

## The Websites screen (Habitat app)

Platform administrators only, like every operational screen in the managed
service.

- **List** — name, brand or client, Live or Draft, address, number of
  properties, bookings this month.
- **Edit** — name, address (slug), client (for a client site), logo, hero
  photo, two colours, heading and tagline, contact details; the properties to
  show, their order and which are featured. The brand site's picker groups
  published listings by client.
- **Publish / Unpublish / View site.**
- **Readiness** — must be all green to publish: Stripe keys present and the
  webhook secret set; a persistent photo disk configured; a real mailer
  (not `log`); at least one live property. Each line says what is missing and
  where to set it.
- **Reservations** — website requests show **Accept** and **Decline** with
  the deadline.

## Photos

Public pages cannot use the authenticated photo route. Photos of a listing
that is on a live site are served through `/sites/{site}/photos/{photo}`,
which streams from the photo's disk only for photos of that site's listings;
Hostex photos keep their external URLs. Uploads (photos, logos, hero images)
go to a `media` disk: Cloudflare R2 through Laravel's S3 driver when
`MEDIA_DISK=r2` and the `R2_*` variables are set, otherwise the local disk,
which Render wipes on each deploy, so readiness stays red until R2 is set.

## SEO in this stage

Server-rendered pages; a title and description per page from the site and
property; canonical URLs; `sitemap.xml` and `robots.txt` per site; Open Graph
tags with the cover photo. Full control comes in stage 5.

## Emails

Queued Laravel mailables: guest confirmation, request received, request
accepted, request declined, pay-balance link; host notification of a new
booking or request (to the site's contact email). Sent through whatever
`MAIL_MAILER` is configured; production currently logs mail, so readiness
stays red until a provider is set.

## Configuration

| Variable | Purpose |
|---|---|
| `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET` | The platform owner's Stripe account (test keys first) |
| `MEDIA_DISK=r2`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT`, `R2_PUBLIC_URL` | Persistent photo storage |
| `MAIL_MAILER` and provider settings | Guest and host emails |

`PAYMENTS_DEFAULT_PROVIDER` is not changed: website payments name the
`stripe` provider explicitly, so nothing else in Habitat starts charging cards.

## Testing

PHPUnit against PostgreSQL, with `Http::fake()` for Stripe and
`Http::preventStrayRequests()`:

- only published listings with active properties appear; a draft site is 404;
- the brand site lists and books listings from two organizations, each booking
  in its own account;
- quote matches `QuoteService`; search filters and date availability;
- a hold blocks the nights and marks the listing dirty; the per-visitor limit;
- expiry releases the hold and cancels the PaymentIntent;
- instant book end to end through a signed webhook; a forged signature is
  refused; a repeated event is a no-op;
- request accept, decline and deadline expiry;
- late payment after expiry: confirmed when free, refunded when not;
- the off-session balance charge and the pay-balance fallback;
- refunds through `PaymentService`;
- `sitemap.xml`; photo route refuses photos of other sites.

Vitest for the Websites screen. A Playwright pass over every guest page at
desktop and phone widths. Before going live, one real booking in Stripe's
test mode with the 4242 test card.

## Delivery

Four pull requests, each merged when CI and the full suite pass:

1. Websites, the screen, the public pages and photos — browsable, not bookable.
2. Quotes, holds, the checkout record and `ReleaseExpiredHolds`.
3. Stripe: client, provider, webhook, instant book, balance, refunds.
4. Requests (accept, decline, expiry), emails, readiness, pay-balance page.

## Out of scope for stage 1

The editor, more than one template, translations, custom domains, widgets,
guest self-cancellation, security-deposit pre-authorisation, rental agreements,
coupons, upsells at checkout, and a guest account area beyond the secret link.
