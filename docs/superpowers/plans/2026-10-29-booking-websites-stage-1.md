# Booking websites, stage 1 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A bookable guest website (brand site across client accounts, optional client sites) at `/sites/<slug>`, with search, property pages, exact quotes, holds, Stripe checkout for instant bookings and requests, and a Websites screen to publish them.

**Architecture:** Laravel renders the guest pages as HTML (Blade, one built-in template) with small vanilla scripts for the booking box and Stripe's Payment Element. A new `Websites` domain holds sites, their listings and checkouts; every quote, hold and booking runs inside `TenantContext::runAs()` for the listing's own organization. Stripe is reached through an HTTP client like `ChannexClient` and plugged into the existing `PaymentService` as a `stripe` provider.

**Tech Stack:** PHP 8.4, Laravel 13, PostgreSQL 16, Blade, vanilla JS, Stripe API (PaymentIntents, Customers, Refunds, webhooks), React 19 + TanStack Query for the staff screen, PHPUnit, Vitest, Playwright for a browser pass.

**Spec:** `docs/superpowers/specs/2026-10-29-booking-websites-stage-1-design.md`

## Global Constraints

- Public address: `/sites/{slug}`; property page `/sites/{slug}/stay/{listing-slug}`; checkout `/sites/{slug}/stay/{listing-slug}/book`; guest link `/sites/{slug}/booking/{access_token}`; `/sites/{slug}/sitemap.xml`, `/sites/{slug}/robots.txt`.
- A site answers 404 unless `status = live`. A listing appears only through `Listing::bookable()` (published, property active).
- Website management is platform-admin only, under `/api/v1/platform/websites` (`platform-admin` middleware).
- Hold: `hold_minutes` default 30; request window: `request_hours` default 24; at most 3 active holds per visitor fingerprint per site.
- Never show the exact address on a public page; location is approximate.
- Website payments name the `stripe` provider explicitly; `PAYMENTS_DEFAULT_PROVIDER` is not changed.
- Stripe webhook: `Stripe-Signature` HMAC SHA-256 over `{t}.{payload}`, five-minute tolerance; event ids recorded once in `processed_provider_events`.
- Env: `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET`, `MEDIA_DISK`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT`, `R2_PUBLIC_URL`.
- Code style: Pint; comments in the codebase's explanatory voice; tests use `Http::preventStrayRequests()` wherever Stripe or a channel could be called.
- Each PR merges only when CI and the full local suite pass.

## Review Focus

- A brand site showing a listing from a suspended or cancelled client account: such an organization's listings must not appear. (Task 1.2 test.)
- A listing removed from the site or unpublished while a guest has its page open: the page and its photos answer 404, not a stale page. (Task 1.2 test.)
- Search dates in the wrong order, in the past, or longer than the listing's maximum: no crash; the listing is simply not available, and the form says why. (Task 1.2 test.)
- A slug that collides with a reserved word (`api`, `stay`, `booking`) or has capitals/spaces: refused by validation. (Task 1.1 test.)
- Two checkouts racing for the same nights: exactly one hold succeeds. (Task 2.2 test.)

---

## PR 1 — Sites, the Websites screen, public pages (browsable, not bookable)

### File structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_10_29_000100_create_websites_tables.php` | `websites`, `website_listings` |
| `app/Domain/Websites/Models/Website.php` | A site; not tenant-scoped; `settings` cast to array; `isLive()`; `url()` |
| `app/Domain/Websites/Models/WebsiteListing.php` | Pivot row with `organization_id`, `position`, `is_featured` |
| `app/Domain/Websites/Support/WebsiteMedia.php` | Where site media and photos are stored and how a public URL is made |
| `app/Domain/Websites/Services/WebsiteCatalogue.php` | Read side: live site by slug, its listings, search, one listing's page |
| `app/Domain/Websites/DataObjects/SearchCriteria.php` | Parsed, validated search input |
| `app/Http/Controllers/Api/V1/Platform/WebsiteController.php` | Staff CRUD, publish, unpublish, listing options, media upload |
| `app/Http/Resources/WebsiteResource.php` | Staff representation |
| `app/Http/Controllers/Sites/SitePageController.php` | Home, search, property pages |
| `app/Http/Controllers/Sites/SiteAssetController.php` | Photos and site media, sitemap, robots |
| `routes/sites.php`, `bootstrap/app.php` | Public route group (no session, fail-closed tenancy, `throttle:sites`) |
| `resources/views/sites/default/*.blade.php` | Layout, home, search, property, partials |
| `public/sites/default/site.css` | The template's stylesheet |
| `frontend/src/pages/WebsitesPage.tsx` (+ test), `App.tsx`, `AppLayout.tsx`, `api/types.ts` | The staff screen |

### Task 1.1: Sites and their administration API

**Files:** migration, `Website`, `WebsiteListing`, `WebsiteMedia`, `WebsiteController`, `WebsiteResource`, `routes/api/platform-console.php`, `config/filesystems.php` (`media` disk); Test: `tests/Feature/Websites/WebsiteAdministrationTest.php`

**Interfaces — produces:**
- `Website`: `id` (ULID), `slug`, `name`, `kind` (`brand`|`client`), `organization_id` (?string), `status` (`draft`|`live`), `settings` (array), `hold_minutes` (int, 30), `request_hours` (int, 24), `published_at`; `listings(): HasMany<WebsiteListing>`; `isLive(): bool`; `url(string $path = ''): string`.
- `WebsiteListing`: `website_id`, `listing_id`, `organization_id`, `position` (int), `is_featured` (bool); `listing(): BelongsTo`.
- `WebsiteMedia::store(Website $site, UploadedFile $file, string $kind): string` (path in `settings`), `WebsiteMedia::url(Website $site, string $kind): ?string`.
- Routes (`platform.websites.*`): `GET platform/websites`, `POST platform/websites`, `GET|PATCH platform/websites/{website}`, `POST …/{website}/publish`, `POST …/{website}/unpublish`, `PUT …/{website}/listings` (body `listings: [{listing_id, is_featured}]`, order = array order), `POST …/{website}/media` (`kind`: `logo`|`hero`, `file`), `GET platform/website-listing-options?organization_id=` (bookable listings grouped by organization).
- `Website::RESERVED_SLUGS = ['api', 'stay', 'booking', 'photos', 'media', 'sitemap', 'robots']`.

- [ ] **Step 1: Write the failing tests**
  - `test_a_platform_admin_creates_a_brand_site_with_listings_from_two_clients` — POST then PUT listings with one listing from each of two organizations → 200; `website_listings` has both rows with their own `organization_id`, positions 0 and 1.
  - `test_a_client_site_takes_only_its_own_clients_listings` — PUT a listing from another organization → 422 on `listings.0.listing_id`.
  - `test_only_bookable_listings_can_be_added` — a draft listing → 422.
  - `test_slugs_are_lowercase_unique_and_not_reserved` — `Sea View`, `api`, a duplicate → 422 each; `sea-view-2` → 201.
  - `test_someone_who_is_not_a_platform_admin_cannot_manage_sites` — an organization admin → 403 on index and store.
  - `test_publishing_needs_at_least_one_listing` — publish an empty site → 422 with `"Add at least one property before publishing."`; with one → `status` `live`, `published_at` set; unpublish → `draft`.
  - `test_a_logo_upload_is_stored_and_reported` — `Storage::fake('media')`, upload PNG with `kind=logo` → `data.settings.logo_url` not null.
- [ ] **Step 2: Run** `php artisan test tests/Feature/Websites/WebsiteAdministrationTest.php` — expect failures (routes missing).
- [ ] **Step 3: Implement** the migration (unique `websites.slug`; unique `(website_id, listing_id)`; FKs cascade on website delete), models, `media` disk (`driver` from `MEDIA_DISK`, default `local` root `storage/app/media`; an `r2` S3 disk reading `R2_*`), controller validation (`slug`: `regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/`, max 60, unique, not in reserved; `kind=client` requires `organization_id` of an existing organization; colours `regex:/^#[0-9a-f]{6}$/i`), listing checks run with `TenantContext::withoutScope()` against `Listing::bookable()` plus organization status active.
- [ ] **Step 4: Run** the test file — all pass.
- [ ] **Step 5: Commit** "Add websites and their administration API".

### Task 1.2: The public pages

**Files:** `WebsiteCatalogue`, `SearchCriteria`, `SitePageController`, `SiteAssetController`, `routes/sites.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php` (`sites` rate limiter: 120/min per IP), views, `site.css`; Test: `tests/Feature/Websites/SitePagesTest.php`

**Interfaces:**
- Consumes: `Website`, `WebsiteListing`, `WebsiteMedia::url()` (Task 1.1).
- Produces: `WebsiteCatalogue::live(string $slug): Website` (404 otherwise); `listings(Website $site): Collection<int, Listing>` (bookable, active organization, ordered, `property`, `photos`, `amenityOverrides` loaded); `search(Website $site, SearchCriteria $c): list<array{listing: Listing, total: ?Money, nightly_from: Money}>`; `listing(Website $site, string $slug): Listing` (404 if not on the site); `photoUrl(Website $site, PropertyPhoto $photo): string`.
- `SearchCriteria::fromRequest(Request $r): SearchCriteria` with `checkIn`, `checkOut` (?CarbonImmutable), `guests` (int ≥ 1), `minPrice`, `maxPrice` (?int, major units), `bedrooms`, `bathrooms` (?int, minimum), `amenities` (list<string> amenity keys), and `problem(): ?string` ("Check-out must be after check-in.", "Those dates are in the past.").
- With dates, availability is `AvailabilityEngine::check(new AvailabilityRequest(property, checkIn, checkOut, listing, guests: …))` and the total is `PricingEngine::quote(new PricingContext(listing, checkIn, checkOut, adults: guests))->grandTotal()`, each inside `TenantContext::runAs($listing->organization)`.

- [ ] **Step 1: Write the failing tests**
  - `test_a_live_site_shows_its_featured_listings` — GET `/sites/brand` → 200, contains both titles, `<title>` contains the site name, `og:image` present.
  - `test_a_draft_site_is_not_found` and `test_an_unknown_site_is_not_found` → 404.
  - `test_unpublished_listings_and_suspended_clients_do_not_appear` — one paused listing, one listing of a suspended organization → neither title in the page; their property pages 404.
  - `test_the_brand_site_shows_listings_from_several_clients` — two organizations' titles on one page.
  - `test_a_property_page_shows_the_listing_but_never_its_address` — title, description, an amenity name, check-in time; `address_line_1` absent; OSM iframe present with rounded coordinates (2 decimals).
  - `test_search_filters_by_guests_bedrooms_and_amenities` — three listings, query `guests=5&bedrooms=2&amenities[]=wifi` → only the match.
  - `test_search_with_dates_leaves_out_booked_listings_and_shows_the_total` — a confirmed reservation over the dates on one listing → absent; the other shows its `grandTotal` formatted.
  - `test_impossible_dates_are_explained_not_crashed` — `checkout` before `checkin` → 200 with "Check-out must be after check-in."
  - `test_photos_are_served_only_for_the_sites_own_listings` — `/sites/brand/photos/{own}` → 200 (or redirect for an external URL); another property's photo → 404.
  - `test_the_sitemap_lists_every_property_page` — XML containing each `/stay/{slug}` URL; `robots.txt` names the sitemap.
- [ ] **Step 2: Run** `php artisan test tests/Feature/Websites/SitePagesTest.php` — expect failures.
- [ ] **Step 3: Implement** the route group in `bootstrap/app.php` (`Route::middleware([AssignRequestId::class, FailClosedTenancy::class, SubstituteBindings::class, 'throttle:sites'])->prefix('sites')->name('sites.')->group(base_path('routes/sites.php'))`), the catalogue, controllers and views. Listing slugs: `listings.slug`, falling back to the property slug; a slug unique within the site wins, otherwise the listing id.
- [ ] **Step 4: Run** the test file — all pass; run `php artisan test --filter=Websites`.
- [ ] **Step 5: Commit** "Render a live site's home, search and property pages".

### Task 1.3: The Websites screen

**Files:** `frontend/src/pages/WebsitesPage.tsx`, `WebsitesPage.test.tsx`, `App.tsx` (route `/websites`, platform admins only), `components/AppLayout.tsx` (nav item "Websites", icon `Globe`, `platformAdminOnly`), `api/types.ts` (`Website`, `WebsiteListingOption`)

**Interfaces — consumes** Task 1.1's routes and `WebsiteResource` fields: `id, slug, name, kind, organization_id, organization_name, status, settings{heading, tagline, primary_colour, accent_colour, contact_email, contact_phone, logo_url, hero_url}, listings[{listing_id, title, organization_name, is_featured}], public_url, published_at`.

- [ ] **Step 1: Write the failing tests** (`stubApi`)
  - "lists sites with their status and address" — two sites → names, "Live"/"Draft" chips, links to `public_url`.
  - "creates a client site" — dialog: kind Client, client select, name, slug → POST body `{kind:'client', organization_id, name, slug}`.
  - "chooses, orders and features properties" — tick two options, move the second up, feature it → PUT `listings` in that order with `is_featured`.
  - "publishes, and says why it cannot" — 422 message shown in an alert; success flips the chip.
- [ ] **Step 2: Run** `npx vitest run src/pages/WebsitesPage.test.tsx` — fail.
- [ ] **Step 3: Implement** the page with the app's existing components (`card`, `RecordDialog`, `Chip`, `QueryState`).
- [ ] **Step 4: Run** lint, typecheck, the page test, the full Vitest run and `npm run build` — all pass.
- [ ] **Step 5: Commit** "Add the Websites screen".

### Task 1.4: Ship PR 1

- [ ] Pint, the full PHP suite, frontend checks; a Playwright pass over home, search and a property page at 1280px and 390px wide (screenshots checked by eye).
- [ ] Push, open the PR into `claude/wizardly-clarke-ixyxs2`, merge on green.

---

## PR 2 — Quotes, holds and checkouts

**Tables:** `website_checkouts` (columns per spec), `reservations.website_id` (nullable FK).

### Task 2.1: Quote and calendar endpoints
- `GET /sites/{slug}/api/listings/{listing}/calendar?month=YYYY-MM` → two months of `{date, available, min_nights, closed_to_arrival}` from `AvailabilityEngine::calendar`.
- `POST /sites/{slug}/api/listings/{listing}/quote` `{check_in, check_out, adults, children}` → `QuoteService::create(new PricingContext(...))` inside `runAs`; response `{quote_id, nights[], fees[], taxes[], totals, currency, expires_at, due_now, mode}` where `due_now` is the first instalment of `PaymentScheduleService::standardPlan` computed without saving, and `mode` is `instant` when the property's `instant_book` is true, else `request`.
- Tests: `test_the_quote_matches_the_pricing_engine`, `test_a_quote_for_unavailable_dates_says_why`, `test_the_calendar_marks_booked_nights`.

### Task 2.2: Holds
- `WebsiteCheckoutService::hold(Website $site, Listing $listing, Quote $quote, array $guest, string $fingerprint): WebsiteCheckout` — refuses a fourth active hold for the fingerprint; inside `AvailabilityEngine::reserve()` creates a **Tentative** reservation (`source=direct`, `website_id`, `hold_expires_at = now + hold_minutes` or `request_hours`), builds the payment schedule, stores the checkout (`status=holding`, `access_token` = 48 random characters).
- `POST /sites/{slug}/api/checkouts` (throttle 10/min per IP) validates name, email, phone, guests, accepted policies.
- `ReleaseExpiredHolds` command `websites:release-expired-holds`, scheduled every minute: cancels the tentative reservation and marks the checkout `expired` (PR 3 adds cancelling the PaymentIntent).
- Tests: `test_a_hold_blocks_the_nights_and_marks_the_listing_dirty`, `test_two_checkouts_for_the_same_nights_get_one_hold`, `test_a_fourth_hold_from_one_visitor_is_refused`, `test_an_expired_hold_reopens_the_nights`.

### Task 2.3: Booking box and checkout page
- Property page: calendar + booking box script (`public/sites/default/booking.js`) using the two endpoints; checkout page form. While Stripe is not configured the button reads "Contact us to book" and shows the site's contact details.
- Tests: `test_without_stripe_the_property_page_offers_contact_details`, `test_the_checkout_page_shows_the_breakdown_and_policies`.

---

## PR 3 — Stripe

### Task 3.1: `StripeClient`
- `app/Domain/Integrations/Support/StripeClient.php`: `post(string $path, array $form, ?string $idempotencyKey = null): array`, `get(string $path, array $query = []): array`; form-encoded, `Authorization: Bearer STRIPE_SECRET_KEY`, `Idempotency-Key` header; errors → `StripeRequestException` (`errorCode`, `declineCode`, `retryable`). `StripeClient::verifySignature(string $payload, string $header, string $secret, int $tolerance = 300): bool`.
- Tests with `Http::fake()`: form encoding, idempotency header, error mapping, signature valid/invalid/stale.

### Task 3.2: `StripePaymentProvider`
- Implements `PaymentProviderInterface` (`key()` = `stripe`, `isLive()` when the secret key is set): `authorize` = PaymentIntent with `capture_method=manual`; `capture` with an authorization = `/v1/payment_intents/{id}/capture`, without = off-session PaymentIntent (`confirm=true`, `off_session=true`, the guest's `payment_method`); `void` = cancel; `refund` = `/v1/refunds`. Registered in `PaymentProviderRegistry`.
- `guests.stripe_customer_id`, `guests.stripe_payment_method_id` (migration).
- Tests: each method's request and the mapping of success, decline and requires-action.

### Task 3.3: Checkout payment and the webhook
- `WebsiteCheckoutService::startPayment(WebsiteCheckout $c): string` (client secret) creates the Customer and PaymentIntent (`setup_future_usage=off_session`, `metadata[checkout_id]`, manual capture for requests).
- `POST /webhooks/stripe` → `StripeWebhookController`: verify, record event once, dispatch: `payment_intent.succeeded` → `confirmPaid()`, `payment_intent.amount_capturable_updated` → `markRequested()`, `payment_intent.payment_failed` → keep holding, store message.
- `confirmPaid()`: payment recorded through `PaymentService` (provider `stripe`, captured, fee from the charge's balance transaction when present), first instalment settled, guest card saved, reservation confirmed, hold cleared; if the hold had expired, re-create when free, else refund in full.
- `ReleaseExpiredHolds` cancels the PaymentIntent.
- Tests: instant book end to end through a signed webhook; forged signature 400; repeated event no-op; late payment confirmed when free and refunded when not; balance charged off-session by `payments:process-due-schedules`; refund through `PaymentService::refund`.

### Task 3.4: The Payment Element
- Checkout page loads `https://js.stripe.com/v3/`, mounts the Payment Element with the client secret, `confirmPayment` with `return_url` = the guest link; the guest link shows paid, pending or failed from the checkout record.

---

## PR 4 — Requests, emails, readiness, pay balance

### Task 4.1: Requests
- `WebsiteCheckoutService::accept(WebsiteCheckout $c): void` (capture, settle, confirm) and `decline(WebsiteCheckout $c, ?string $reason): void` (cancel PaymentIntent, cancel reservation); staff routes `POST reservations/{reservation}/website-request/accept|decline`; `ReleaseExpiredHolds` declines requests past `request_hours`.
- Reservations screen: Accept and Decline with the deadline for a reservation whose checkout is `requested`.
- Tests: accept captures and confirms; decline voids and reopens; deadline declines.

### Task 4.2: Emails
- Mailables `BookingConfirmed`, `BookingRequested`, `RequestAccepted`, `RequestDeclined`, `PayBalance` (guest) and `NewWebsiteBooking` (site contact email), queued.
- Tests: `Mail::fake()` per event.

### Task 4.3: Readiness and pay balance
- `WebsiteReadiness::for(Website $site): list<array{key, ok, message}>` — Stripe keys and webhook secret, `media` disk not local, mailer not `log`, at least one live listing; publish refuses while any is red; shown on the Websites screen.
- Guest link page shows **Pay balance** for an outstanding instalment (on-session PaymentIntent); `PayBalance` email sent when a scheduled charge fails.
- Tests: readiness lines; publish refused with the first red line's message; pay-balance flow through a signed webhook.

### Task 4.4: Live check
- With the user's Stripe test keys: one instant booking and one request in Stripe test mode with card 4242, confirmed in Habitat and on Channex staging.
