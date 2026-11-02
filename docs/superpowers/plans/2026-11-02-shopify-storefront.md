# Homes and checkout on a client's Shopify store: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show a site's search, homes and booking box on any website (and on a
client's Shopify store with checkout on Shopify), and charge a fixed monthly
fee once per home per month.

**Architecture:**
- A static loader (`public/sites/embed.js`) turns tags on the store page into
  iframes of new `/sites/{site}/embed/...` pages.
- Those pages are the existing Blade views drawn without header and footer,
  and framed only by the site's store addresses.
- Shopify checkout (PR 3) creates a draft order per stay and hands the store
  page to its `invoiceUrl`. Webhooks confirm the booking.
- Statements (PR 1, PR 4) change inside `OwnerStatementBuilder`.

**Tech Stack:** Laravel 13 / PHP 8.4 / PostgreSQL, Blade, plain ES5 scripts
in `public/sites`, React 19 + TanStack Query, Vitest (jsdom), PHPUnit,
Playwright (preinstalled Chromium).

**Spec:** `docs/superpowers/specs/2026-11-02-shopify-storefront-design.md`

## Global Constraints

- `declare(strict_types=1);` in every PHP file. Follow the surrounding code's
  docblock style: say *why*.
- Site routes stay sessionless and cookieless (`bootstrap/app.php` sites
  group). Nothing here adds a cookie.
- The client portal and its API never expose:
  - `embed_origins` or `store_url`;
  - snippets;
  - anything about Shopify.
- Store addresses are `https://` origins only (scheme + host + optional port,
  nothing after), stored lowercase without a trailing slash, at most 10.
- **Copy strings fixed by this plan** (use exactly):
  - "Use an address like https://www.example.com, with nothing after it."
  - "This page must be on one of the site's store addresses."
  - "Fixed fee {amount} {CUR} a month × {n} month(s)."
  - "Fixed fees for {n} months: {a}, {b}, {c} {CUR}."
- **Tests:**
  - The full PHP suite runs only on `bnb_testing`, one run at a time.
  - Side runs use `DB_DATABASE=bnb_side php artisan test --filter ...`.
  - The frontend runs `npm test` and `npm run build`; `tsc -b` inside the
    build is the real type check, and `npm run typecheck` checks nothing.
- If the container restarted: `service postgresql start; service redis-server start`.
- **Shipping:**
  - Each PR is squash-merged into `claude/wizardly-clarke-ixyxs2` when the
    suite and CI pass (the user's standing instruction).
  - Develop on `claude/cool-cannon-7leraj`.
  - After a merge, merge the base branch back into the development branch;
    never force-push.

## Review Focus

1. **Two tags for the same piece on one store page, or pieces of two sites on
   one page.** Each frame must get only its own height and messages. The
   loader matches `event.source` to the frame. Pinned in Task 2.3
   (`keeps each frame's messages to that frame`).
2. **A store address typed with capitals or a trailing slash, and a home's
   store page on `www.` when only the bare domain is listed.** Addresses are
   normalised. The page is refused because origins must match exactly.
   Pinned in Task 2.1 (`test_store_addresses_are_normalised` and
   `test_a_home_page_on_the_store_must_be_on_a_store_address`).
3. **A home bought mid-month.** It is held on the month's last day, so it is
   charged for that month. Pinned in Task 1.1
   (`test_a_home_bought_mid_month_is_charged_for_that_month`).
4. **A booking box tag naming a home that is not on the site, or a site that
   is not live.** The frame shows the site's 404, not an error page. Pinned in
   Task 2.2 (`test_a_piece_for_a_home_not_on_the_site_is_not_found`).
5. **A checkout started on the hosted site after this change.** It must still
   continue on the hosted booking page. Pinned in Task 2.2
   (`test_a_checkout_started_on_the_site_continues_on_the_site`).

---

## PR 1: The fixed monthly fee per home per month, and statement lines in the portal

### Task 1.1: Charge a fixed monthly fee once per home per month

**Files:**
- Modify: `app/Domain/OwnerAccounting/Services/OwnerStatementBuilder.php`.
  Touches `forProperty` (fee block near line 386) and `managementFee` (around
  line 468; remove its `FIXED_MONTHLY` branch).
- Test: `tests/Feature/OwnerAccounting/OwnerStatementBuilderTest.php`

**Interfaces:**
- Produces: `private function fixedMonthlyFees(Owner $owner, string $propertyId, Collection $shares, CarbonImmutable $from, CarbonImmutable $to, string $currency): array{amount: Money, explanation: string}`.
  - It walks each calendar month's last day `d` with `$from <= d <= $to`.
  - A month counts when `$owner->agreementFor($propertyId, $d)` has
    `commission_model === FIXED_MONTHLY` and `$this->shareOn($shares, $d) !== null`.
  - Each counted month adds `commission_amount`.
- `forProperty` always adds the fixed part.
  - It adds the existing revenue-based fee only when the agreement at `$to`
    is not `FIXED_MONTHLY`.
  - Each positive part is its own `MANAGEMENT_FEE` line, with description
    "Management fee" and its explanation.

**Test setup:**
- Add a helper
  `fixedAgreement(Owner $owner, int $amount, ?CarbonImmutable $startsOn = null, ?CarbonImmutable $endsOn = null)`
  creating a `FIXED_MONTHLY` agreement with `commission_amount = $amount`.
- Use periods in **next year** (`CarbonImmutable::today()->addYear()->startOfYear()` = `$jan1`).
  Ownership assigned in `setUp` time is then in force. Check
  `OwnershipLedger::assign` for its default `starts_on`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_a_quarterly_statement_charges_the_fixed_fee_for_each_month(): void
{
    $owner = $this->owner(); $this->fixedAgreement($owner, 5000);
    $statement = $this->buildFor($owner, $this->jan1(), $this->jan1()->addMonths(2)->endOfMonth()->startOfDay());
    $this->assertSame(15000, (int) $statement->management_fee);
    $line = $statement->lines()->where('category', OwnerStatementLine::MANAGEMENT_FEE)->sole();
    $this->assertSame('Fixed fee 50.00 EUR a month × 3 months.', $line->explanation);
}

public function test_a_monthly_statement_charges_the_fixed_fee_once(): void
// Jan 1 – Jan 31 → 5000, explanation 'Fixed fee 50.00 EUR a month × 1 month.'

public function test_a_weekly_statement_charges_the_fixed_fee_only_when_it_holds_a_month_end(): void
// Jan 5 – Jan 11 → management_fee 0 and no MANAGEMENT_FEE line;
// Jan 26 – Feb 1 → 5000

public function test_a_home_sold_before_the_month_end_is_not_charged_that_month(): void
// end the ownership on Feb 20 (OwnershipLedger::end); Jan 1 – Mar 31 → 5000

public function test_a_home_bought_mid_month_is_charged_for_that_month(): void
// ownership starts_on Jan 10 (assign with ['starts_on' => …]); Jan 1 – Jan 31 → 5000

public function test_each_month_uses_the_fixed_fee_in_force_on_its_last_day(): void
// agreement 5000 ending Feb 15, agreement 6000 from Feb 16; Jan 1 – Mar 31 → 17000,
// explanation 'Fixed fees for 3 months: 50.00, 60.00, 60.00 EUR.'
```

The `owner()` helper already exists in the test file; check its name and
signature. Add `jan1(): CarbonImmutable`.

- [ ] **Step 2: Run them, and see them fail**

Run: `DB_DATABASE=bnb_side php artisan test --filter 'fixed_fee|fixed fee|month_end|mid_month' tests/Feature/OwnerAccounting/OwnerStatementBuilderTest.php`

Expected: FAIL. The quarterly test gives 5000, not 15000. The weekly test
charges 5000 on Jan 5–11.

- [ ] **Step 3: Implement `fixedMonthlyFees`, and use it in `forProperty`**

Remove the `FIXED_MONTHLY` early return in `managementFee`; that branch is now
unreachable.

Month walk: `$d = $from->endOfMonth()->startOfDay()`. While `$d <= $to`,
advance with `$d = $d->addDay()->endOfMonth()->startOfDay()`.

- [ ] **Step 4: Run the whole statement test file**

Run: `DB_DATABASE=bnb_side php artisan test tests/Feature/OwnerAccounting`

Expected: PASS, all of it. The existing percentage-fee tests are unchanged.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/OwnerAccounting/Services/OwnerStatementBuilder.php tests/Feature/OwnerAccounting/OwnerStatementBuilderTest.php
git commit -m "Statements: charge a fixed monthly fee once per home per month"
```

### Task 1.2: A sent statement's lines in the portal API

**Files:**
- Modify: `app/Domain/Owners/Services/OwnerPortalService.php`
- Modify: `app/Http/Controllers/Api/V1/People/OwnerPortalController.php`
- Modify: `routes/api/people.php` (beside `statements/{statement}/document`)
- Test: `tests/Feature/Owners/OwnerPortalTest.php`

**Interfaces:**
- Produces the route `GET api/v1/portal/owner/statements/{statement}`
  (name `api.v1.portal.owner.statements.show`, `whereUlid`). It returns
  `{data: StatementRow & {lines: Line[]}}`.
- `StatementRow` is the existing row shape from `OwnerPortalService::statements`.
  Extract its mapper to `private function statementRow(OwnerStatement $s): array`.
- `Line` is `{category: string, property: string|null, description: string, explanation: string|null, amount: MoneyJson, line_date: string|null}`,
  ordered by `sort_order` then `line_date`.
- `OwnerPortalService::statement(Owner $owner, string $statementId): ?array`
  returns null unless the statement is the owner's and is sent or paid.

- [ ] **Step 1: Write the failing tests**

```php
public function test_an_owner_reads_the_lines_of_a_statement_they_were_sent(): void
// a sent statement with a MANAGEMENT_FEE line for property "Harbour Loft",
// explanation 'Fixed fee 50.00 EUR a month × 1 month.', amount -5000 →
// assertJsonPath('data.lines.N.property', 'Harbour Loft'), …explanation, …amount.amount = -5000

public function test_a_draft_or_another_owners_statement_is_not_found(): void
// draft → 404; another owner's sent statement → 404
```

Build the statements with the fixtures the file already uses for the
statements list.

- [ ] **Step 2: Run them, and see them fail**

Run: `DB_DATABASE=bnb_side php artisan test --filter 'lines_of_a_statement|another_owners_statement' tests/Feature/Owners/OwnerPortalTest.php`

Expected: FAIL with 404 or 405, because the route does not exist.

- [ ] **Step 3: Implement the service method, the controller action `statement(string $statement)` and the route**

- [ ] **Step 4: Run the portal tests**

Run: `DB_DATABASE=bnb_side php artisan test tests/Feature/Owners`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git commit -am "Client portal: a sent statement's lines"
```

### Task 1.3: Statement details on the Money page

**Files:**
- Modify: `frontend/src/api/types.ts`. Add
  `OwnerStatementLine { category; property: string | null; description; explanation: string | null; amount: MoneyAmount; line_date: string | null }`
  and `OwnerStatementDetail = OwnerStatementRow & { lines: OwnerStatementLine[] }`.
- Modify: `frontend/src/pages/owner/OwnerMoneyPage.tsx`
- Test: `frontend/src/pages/owner/OwnerPortal.test.tsx`

**Behaviour:**
- Each statement row gets a **Details** button, beside **PDF**, with
  `aria-expanded`.
- Opening it fetches `portal/owner/statements/{id}`, with query key
  `['owner-statement', id]`.
- It then shows a row spanning the table, with a small table: Home · Line ·
  Explanation · Amount.
- Opening it again closes it.

- [ ] **Step 1: Write the failing test**

```ts
it("shows a statement's lines when Details is opened", async () => {
  // GET portal/owner/statements → one row st_1; GET portal/owner/statements/st_1 →
  // lines: [{ category: 'management_fee', property: 'Harbour Loft', description: 'Management fee',
  //           explanation: 'Fixed fee 50.00 EUR a month × 1 month.', amount: { amount: -5000, currency: 'EUR', formatted: '-50.00' }, line_date: null }]
  await user.click(await screen.findByRole('button', { name: 'Details' }))
  expect(await screen.findByText('Fixed fee 50.00 EUR a month × 1 month.')).toBeInTheDocument()
  expect(screen.getByText('Harbour Loft')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Details' })).toHaveAttribute('aria-expanded', 'true')
})
```

Use the mock-route helper already used in the file (see its
`'GET portal/owner/statements'` entries).

- [ ] **Step 2: Run it, and see it fail**

Run: `cd frontend && npx vitest run src/pages/owner/OwnerPortal.test.tsx`

Expected: FAIL. There is no Details button.

- [ ] **Step 3: Implement**

- [ ] **Step 4: Run it, then the whole frontend**

Run: `cd frontend && npx vitest run src/pages/owner && npm run build`

Expected: PASS, and the build succeeds.

- [ ] **Step 5: Commit**

```bash
git commit -am "Client portal: open a statement's lines on the Money page"
```

### Task 1.4: Ship PR 1

- [ ] Run the full PHP suite on `bnb_testing` (`php artisan test`, in the
  background) and `cd frontend && npm test && npm run build`. Expected: all
  green.
- [ ] Push. Open the PR "Statements: a fixed monthly fee per home per month,
  shown in the client portal" against `claude/wizardly-clarke-ixyxs2`. Watch
  CI, squash-merge when green, then merge the base branch back into the
  development branch.

---

## PR 2: Pieces on any website

### Task 2.1: Store addresses, each home's store page, and the snippets

**Files:**
- Create: `database/migrations/2026_11_02_000100_add_website_embedding.php`
  - `websites.embed_origins` jsonb, nullable.
  - `website_listings.store_url` string(500), nullable.
- Create: `app/Domain/Websites/Support/SiteOrigin.php`
- Create: `app/Domain/Websites/Support/EmbedSnippets.php`
- Modify:
  - `app/Domain/Websites/Models/Website.php`: cast `embed_origins` to array,
    and `embedOrigins(): list<string>`.
  - `app/Domain/Websites/Models/WebsiteListing.php`: fillable `store_url`.
  - `app/Http/Controllers/Api/V1/PlatformConsole/WebsiteController.php`:
    `update`, `listings`.
  - `app/Domain/Websites/Services/WebsiteAdministration.php`: `replaceListings`.
  - `app/Http/Resources/WebsiteResource.php`
  - `app/Http/Resources/OwnerWebsiteResource.php`
- Test: `tests/Feature/Websites/WebsiteEmbedSettingsTest.php`

**Interfaces:**
- `SiteOrigin::normalize(string $value): ?string` returns `"https://host[:port]"`,
  lowercased, or null. It is null unless:
  - the scheme is https;
  - there is a host;
  - there is no path other than `/`, no query, no fragment and no user info.
- `SiteOrigin::of(string $url): ?string` gives the origin of any https URL,
  or null.
- `Website::embedOrigins(): list<string>`
- `WebsiteAdministration::replaceListings(Website $website, array $rows, bool $withStoreUrls = false): void`.
  - Platform calls it with `true`. A row's `store_url` (nullable) must have
    `SiteOrigin::of($url)` in `embedOrigins()`. Otherwise the error is
    `listings.{i}.store_url` => "This page must be on one of the site's store addresses."
  - With `false`, it keeps each kept listing's existing `store_url`.
- `EmbedSnippets::for(Website $site): array{script: string, search: string, homes: string, book: list<array{listing_id: string, title: string, tag: string}>}`, with exactly:
  - `<script src="{url('/sites/embed.js')}" defer></script>`
  - `<div data-habitat-site="{slug}" data-habitat-piece="search"></div>`
  - `<div data-habitat-site="{slug}" data-habitat-piece="homes"></div>`
  - `<div data-habitat-site="{slug}" data-habitat-piece="book" data-habitat-home="{WebsiteCatalogue::slugFor}"></div>`
- **`WebsiteResource`** adds:
  - `embed_origins`;
  - `listings[].store_url`;
  - `embed` (snippets, only when `listings` is loaded).
- **`OwnerWebsiteResource`** removes all three.
- **Platform `PATCH`** accepts
  `embed_origins: ['sometimes', 'array', 'max:10']`, with each item a string
  and checked with `SiteOrigin::normalize`. The error is "Use an address like
  https://www.example.com, with nothing after it." The values are stored
  normalised and unique.

- [ ] **Step 1: Write the failing tests**

```php
test_store_addresses_are_normalised            // ['https://Shop.Example.com/', 'https://www.example.com'] → ['https://shop.example.com', 'https://www.example.com']
test_a_store_address_is_https_with_nothing_after_it // each of 'http://x.com', 'https://x.com/shop', 'x.com', 'https://x.com?a=1' → 422, exact message
test_at_most_ten_store_addresses               // 11 → 422
test_a_home_page_on_the_store_must_be_on_a_store_address // origins ['https://shop.example.com']; store_url 'https://www.shop.example.com/products/loft' → 422 exact message; 'https://shop.example.com/products/loft' → 200 and data.listings.0.store_url
test_a_client_neither_sees_nor_changes_store_settings   // client GET: no embed_origins/embed keys, no listings.*.store_url; client PATCH embed_origins ignored; client PUT listings keeps the platform's store_url
test_the_snippets_name_the_site_and_each_home  // data.embed.script, .search, .homes, .book.0.tag exact strings
```

Build the fixtures the way `tests/Feature/Websites/ClientWebsiteEditorTest.php`
does (client organisation, home, site).

- [ ] **Step 2: Run them, and see them fail**

Run: `DB_DATABASE=bnb_side php artisan test tests/Feature/Websites/WebsiteEmbedSettingsTest.php`

Expected: FAIL, because the columns don't exist yet.

- [ ] **Step 3: Implement.** Run the migration on both test databases:
  `php artisan migrate --database=… --env=testing`, or let `RefreshDatabase`
  do it.

- [ ] **Step 4: Run it, and the website tests**

Run: `DB_DATABASE=bnb_side php artisan test tests/Feature/Websites`

Expected: PASS.

- [ ] **Step 5: Commit**: "Websites: store addresses, each home's store page,
  and the snippets"

### Task 2.2: The embed pages, framed only by the store

**Files:**
- Create: `app/Domain/Websites/Support/SiteLinks.php`
- Create: `app/Domain/Websites/Support/SiteFrames.php`
- Create: `app/Http/Controllers/Sites/SiteEmbedController.php`
- Create: `resources/views/sites/embed/search.blade.php`
- Create: `resources/views/sites/embed/homes.blade.php`. It also serves
  `results`, with the search bar on top.
- Create: `resources/views/sites/embed/book.blade.php`
- Create: `resources/views/sites/default/partials/booking-box.blade.php`.
  Move the `<aside class="booking-box">` there from `property.blade.php`,
  which then includes it.
- Modify: `routes/sites.php`. Add the `embed/...` group before the `{page}`
  route, with `throttle:sites`.
- Modify: `app/Http/Controllers/Sites/SitePageController.php`.
  `property`, `checkout` and `booking` get `bool $embed = false`, fed by the
  routes' `->defaults('embed', true)`.
- Modify: `app/Http/Controllers/Sites/SiteCheckoutController.php`.
  - `store` returns `booking_url => $checkout->url($request->boolean('embed'))`.
  - `payBalance` returns `return_url` the same way.
- Modify: `app/Domain/Websites/Models/WebsiteCheckout.php` → `url(bool $embed = false)`.
- Modify: `app/Domain/Websites/Services/WebsiteCatalogue.php` → `storeUrls(Website $site): array<string, string>`,
  mapping listing id to store_url for rows that have one.
- Modify: `resources/views/sites/default/layout.blade.php`.
  - When `$embed` is set (`'piece'` or `'popup'`): no header or footer.
  - Add `<html data-embed="{piece|popup}" data-allowed='{json embedOrigins}'>`.
  - Use body class `is-embed is-embed--{piece|popup}`.
  - Load `sites/embed/frame.css` and `sites/embed/frame.js`.
- Modify the views that build page links, so they use `SiteLinks::to`:
  - `partials/card.blade.php`
  - `partials/search-form.blade.php`
  - `property.blade.php`
  - `checkout.blade.php`
  - `booking.blade.php`
- Modify: `public/sites/default/booking.js`. When
  `document.documentElement.hasAttribute('data-embed')`, it sends
  `embed: true` in the checkout and pay-balance POST bodies.
- Test: `tests/Feature/Websites/SiteEmbedTest.php`

**Interfaces:**
- `SiteLinks::to(Website $site, string $path, bool|string $embed): string`
  - When `$embed` is truthy it returns `$site->url('embed/'.$path)`, otherwise
    `$site->url($path)`.
  - The search form's action uses `results` in embed mode and `search`
    otherwise.
- `SiteFrames::headers(Website $site): array<string, string>` returns:
  - `Content-Security-Policy` => `frame-ancestors {origins joined by space}`,
    or `frame-ancestors 'none'` when the site has none;
  - `X-Robots-Tag` => `noindex, nofollow`;
  - `Cache-Control` => `no-store, private`, only for `checkout` and `booking`.
- **Routes**, all under `{site}/embed/` and named `sites.embed.*`:
  - `search`, `homes`, `results` and `book/{listing}`, served by
    `SiteEmbedController`;
  - `stay/{listing}`, `checkout/{quote}` and `booking/{token}`, served by
    `SitePageController` with `embed` set to `true`.
  - Pieces (`search`, `homes`, `book`) render with `$embed = 'piece'`.
    `results`, `stay`, `checkout` and `booking` render with `$embed = 'popup'`.
  - Every one uses `WebsiteCatalogue::live` (404 for a site that isn't live)
    and returns `SiteFrames::headers`.
- **Cards in embed mode:**
  - A home with a store page: `<a href="{store_url}?{dates}" class="card__link" data-habitat-go>`.
  - Any other home: `<a href="{embed stay url}?{dates}" class="card__link" data-habitat-open>`.

- [ ] **Step 1: Write the failing tests**

```php
test_each_piece_and_popup_page_is_framed_only_by_the_store_addresses
  // origins ['https://shop.example.com','https://www.example.com']; for each of
  // embed/search, embed/homes, embed/results, embed/book/{slug}, embed/stay/{slug},
  // embed/checkout/{quote}, embed/booking/{token}:
  // assertHeader('Content-Security-Policy', 'frame-ancestors https://shop.example.com https://www.example.com')
  // assertHeader('X-Robots-Tag', 'noindex, nofollow')
test_a_site_without_store_addresses_cannot_be_framed    // "frame-ancestors 'none'"
test_the_hosted_pages_send_no_frame_policy              // GET /sites/harbour → assertHeaderMissing('Content-Security-Policy')
test_pieces_have_no_site_header_or_footer               // assertDontSee('site-header'), assertSee('data-embed="piece"', false)
test_a_card_opens_the_popup_or_goes_to_the_homes_store_page
  // embed/homes?checkin=…&checkout=… : one home with store_url → data-habitat-go + 'https://shop.example.com/products/loft?checkin=…';
  // the other → data-habitat-open + '/sites/harbour/embed/stay/{slug}?checkin=…'
test_a_piece_for_a_home_not_on_the_site_is_not_found    // embed/book/not-here → 404; draft site embed/homes → 404
test_a_checkout_started_in_a_frame_continues_in_the_frame // POST api/checkouts {…, embed: true} → booking_url contains '/embed/booking/'
test_a_checkout_started_on_the_site_continues_on_the_site // same without embed → booking_url without '/embed/'
```

For the quote, checkout and Stripe fakes, reuse the setup of the existing
checkout feature test. Find it with
`grep -rl "api/checkouts" tests/Feature/Websites`.

- [ ] **Step 2: Run them, and see them fail**

Run: `DB_DATABASE=bnb_side php artisan test tests/Feature/Websites/SiteEmbedTest.php`

Expected: FAIL with 404s, because the routes don't exist.

- [ ] **Step 3: Implement**

- [ ] **Step 4: Run the website and site tests**

Run: `DB_DATABASE=bnb_side php artisan test tests/Feature/Websites tests/Feature/Sites`

The second path is only needed if it exists. Expected: PASS. The existing
page, checkout and booking tests are unchanged.

- [ ] **Step 5: Commit**: "Websites: pages for pieces and the pop-up, framed
  only by the site's store"

### Task 2.3: The loader and the frame script

**Files:**
- Create: `public/sites/embed.js` (the loader). Plain ES5 in an IIFE, like
  `public/sites/editor/overlay.js`.
- Create: `public/sites/embed/frame.js` and `public/sites/embed/frame.css`.
  - The CSS covers transparent pieces, `overflow: hidden` on pieces, and a
    solid theme background on pop-up pages.
- Modify: `frontend/vitest.config.ts`. Add `../public/sites` to `server.fs.allow`.
- Test: `frontend/src/embed/embed.test.ts` and `frontend/src/embed/frame.test.ts`.
  They load the scripts with `?raw`, as `overlay.test.ts` does, and use its
  listener clean-up pattern.

**Interfaces (the message protocol, every message has `source: 'habitat-embed'`):**
- **Frame → store page** (`parent.postMessage(msg, '*')`, carrying no secret):
  - `{type: 'ready', allowed: string[]}`
  - `{type: 'height', height: number}`
  - `{type: 'open', url: string}`
  - `{type: 'go', url: string}`
  - `{type: 'search', checkin: string, checkout: string, guests: string}`
  - `{type: 'close'}`
- **The loader:**
  - Its base is the origin of
    `document.querySelector('script[src$="/sites/embed.js"]').src`.
  - For each `[data-habitat-site][data-habitat-piece]` it creates an
    `<iframe>` with:
    - `src = {base}/sites/{site}/embed/{piece}[/{home}]?{checkin,checkout,guests from location.search}`;
    - `title`: "Search stays" / "Homes" / "Book this home";
    - `allow="payment"`;
    - `style="border:0;width:100%;display:block;overflow:hidden"`.
  - It accepts a message only if `event.origin === base` and `event.source`
    is one of its frames' `contentWindow`s. Pieces and the pop-up are kept
    apart.
  - `height` sets that frame's height in px.
  - `open` is accepted only if `url` starts with `{base}/sites/{site}/embed/`.
    It shows the pop-up:
    - `div.habitat-popup` (fixed, full screen, with a backdrop);
    - a close button (aria-label "Close");
    - an iframe of `url`;
    - `document.body.style.overflow = 'hidden'` while it is open.
  - `close`, the close button, and Esc on the store page remove the pop-up and
    restore the overflow.
  - `go` is followed with `window.location.assign(url)` only if `url` is https
    and its origin is in the `allowed` list that frame sent in `ready`.
  - `search` reloads every homes frame of that site with the new query. If
    there are none, it opens the pop-up at `embed/results?{query}`.
- **The frame script:**
  - It sends `ready` (from `<html data-allowed>`) and its height on load, and
    on every `ResizeObserver` change of `document.documentElement`.
  - Clicks on `a[data-habitat-go]` → `go`.
  - Clicks on `a[data-habitat-open]` → `open`.
  - In a piece (`data-embed="piece"`), a click on any same-origin link whose
    path contains `/embed/` → `open`. This is how **Book** in an inline
    booking box opens checkout in the pop-up.
  - A piece's search form submit → `search` instead of loading.
  - Esc in a pop-up page → `close`.

- [ ] **Step 1: Write the failing tests**

```ts
// embed.test.ts
it("turns each tag into a frame of its piece, with the page's dates")
it('sizes a frame to the height its page reports')
it("keeps each frame's messages to that frame")          // two homes tags: height from frame A changes only A
it('ignores messages from another origin')
it('opens the pop-up, and closes it with its button, with Esc, and when asked')
it('opens only pages of the same site on the platform')   // open to https://evil.test/... → no pop-up
it('follows go only to an allowed https address')         // allowed ['https://shop.example.com']: ok; https://evil.test and http://shop.example.com refused
it('search reloads every grid with the dates, or opens the results when there is no grid')

// frame.test.ts
it('says it is ready, with the allowed addresses, and reports its height')
it('a card asks the store page to open the home in the pop-up')
it("a card for a home with a store page asks to go there")
it('Book in a booking box asks for checkout in the pop-up')
it('the search bar sends its dates instead of loading')
it('Esc on a pop-up page asks to close')
```

For `go`, stub navigation.
- First try `vi.stubGlobal('location', { ...window.location, assign: vi.fn(), search: '…' })`.
- If jsdom refuses to redefine `location`, assert that no navigation was
  attempted through jsdom's "Not implemented: navigation" virtual-console
  error, and ledger the ruling.
- Simulate a frame's message with
  `window.dispatchEvent(new MessageEvent('message', { data, origin, source: iframe.contentWindow }))`.

- [ ] **Step 2: Run them, and see them fail**

Run: `cd frontend && npx vitest run src/embed`

Expected: FAIL, because the scripts don't exist.

- [ ] **Step 3: Implement both scripts and the CSS.** Add both scripts'
  paths to the layout from Task 2.2, with the `?v=filemtime` cache-busting
  that `overlay.js` uses.

- [ ] **Step 4: Run them, then the whole frontend**

Run: `cd frontend && npx vitest run src/embed && npm test && npm run build`

Expected: PASS.

- [ ] **Step 5: Commit**: "Websites: the snippet that places the pieces, and
  their frames"

### Task 2.4: Store addresses and snippets in the editor (platform only)

**Files:**
- Modify: `frontend/src/api/types.ts`. Add to `Website`:
  - `embed_origins?: string[]`;
  - `embed?: { script: string; search: string; homes: string; book: { listing_id: string; title: string; tag: string }[] }`;
  - `listings[].store_url?: string | null`.
- Modify: `frontend/src/pages/site-editor/SiteDetailsPanel.tsx`. When
  `who === 'platform'`, add a section "On other websites" with:
  - a textarea "Store addresses (one per line)", saved by a "Save addresses"
    button as `PATCH {embed_origins}`;
  - each server error shown under the field;
  - each snippet in a read-only `<code>` with a **Copy** button
    (`navigator.clipboard.writeText`) that says "Copied".
- Modify: `frontend/src/pages/site-editor/HomesPanel.tsx`. When
  `who === 'platform'`, each chosen home gets a "Page on the store" URL input,
  saved with the listings `PUT` as `store_url` (empty → null). Errors show
  per row.
- Test: `frontend/src/pages/site-editor/SiteDetailsPanel.test.tsx` (new) and
  `frontend/src/pages/site-editor/HomesPanel.test.tsx`.

- [ ] **Step 1: Write the failing tests**

```ts
it('saves the store addresses one per line')            // PATCH body { embed_origins: ['https://shop.example.com','https://www.example.com'] }
it('shows why a store address was refused')             // 422 errors['embed_origins.1'] → text visible
it('copies a snippet')                                  // clipboard.writeText called with the script tag; button says 'Copied'
it('a client sees no store addresses or snippets')      // who='client' → no 'On other websites'
// HomesPanel.test.tsx
it("saves a home's page on the store")                  // PUT body listings[0].store_url
it("a client is not offered a home's store page")
```

- [ ] **Step 2: Run them, and see them fail.**
  Run: `cd frontend && npx vitest run src/pages/site-editor/SiteDetailsPanel.test.tsx src/pages/site-editor/HomesPanel.test.tsx`.
  Expected: FAIL.
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run** `cd frontend && npm test && npm run build`.
  Expected: PASS.
- [ ] **Step 5: Commit**: "Website editor: store addresses, snippets and each
  home's store page"

### Task 2.5: Browser run, and ship PR 2

- [ ] **The browser run.** Use the local app at `http://127.0.0.1:8124`, with
  the site live and Stripe in test mode as earlier runs. The store page is a
  static `store.html` at `http://localhost:8125` (`python3 -m http.server`),
  with the script line and all three tags.
  - **Setup:** set `embed_origins = ['http://localhost:8125']` directly
    through tinker. Validation allows https only, and this is a local check.
  - **Playwright, in the scratchpad, not committed:**
    1. The three frames load and size themselves.
    2. Search reloads the grid.
    3. A card opens the pop-up, and Esc closes it.
    4. Book in the inline box opens checkout in the pop-up.
    5. The details step reaches the booking page inside the pop-up.
    6. The same store page served from `http://localhost:8126` (not listed)
       shows empty frames: the browser refuses the frame.
  - Take a screenshot of each step and send the key ones to the user.
- [ ] Run the full suite and `npm test && npm run build`. Push. Open the PR
  "Websites: show a site's search, homes and booking box on any website".
  Watch CI, squash-merge when green, and merge the base branch back.

---

## PR 3: Shopify checkout (outline; detail it when PR 2 is merged)

**3.1 The Shopify client and app settings**
- `config/services.php` gains `shopify`:
  - `client_id` (`SHOPIFY_CLIENT_ID`);
  - `client_secret` (`SHOPIFY_CLIENT_SECRET`);
  - `api_version` (`SHOPIFY_API_VERSION`, default `2026-01`);
  - `scopes` (`write_draft_orders,read_orders`).
- `render.yaml` gets both keys with `sync: false`.
- `App\Domain\Integrations\Shopify\ShopifyClient`:
  - `graphql(WebsiteShopifyConnection $c, string $query, array $variables = []): array`
  - `exchangeCode(string $shop, string $code): array{access_token: string, scope: string}`
  - It throws `ShopifyRequestException` (with `status`).
  - Tests fake it with `Http::fake`.

**3.2 Connecting a store**
- New table and model `website_shopify_connections` / `WebsiteShopifyConnection`
  (columns as in the spec; `access_token` cast `encrypted`, hidden).
- `ShopifyInstall::link(Website $site): string`. The `state` is
  `Crypt::encryptString(json {website, nonce, expires})`, and the nonce is
  kept in the cache for one hour, single-use.
- `ShopifyInstall::complete(array $query): WebsiteShopifyConnection` checks:
  - `hmac`: HMAC-SHA256 of the sorted query without `hmac`, keyed with the
    client secret, compared with `hash_equals`;
  - the `state`;
  - the shop regex.

  It then exchanges the code, reads the shop's name, currency and domains,
  and subscribes `ORDERS_PAID`, `REFUNDS_CREATE` and `APP_UNINSTALLED` to
  `url('/webhooks/shopify')`.
- **Routes:**
  - `POST api/v1/platform/websites/{website}/shopify/install-link`
  - `DELETE api/v1/platform/websites/{website}/shopify`
  - `GET /integrations/shopify/callback`, which redirects to the editor with
    `?shopify=connected` or `?shopify=failed`
- `embedOrigins()` reported to frames includes the connection's
  `myshopify` address and domains.

**3.3 Shopify orders for stays**
- New table and model `website_shopify_orders` / `WebsiteShopifyOrder`.
- `website_checkouts.payment_channel` (`stripe` default, `shopify`), fixed in
  `WebsiteCheckoutService::hold`.
- `ShopifyCheckouts::start(WebsiteCheckout $c, string $purpose, Money $amount): WebsiteShopifyOrder`
  calls `draftOrderCreate`. The draft order has:
  - the line `"{home} · {d MMM}–{d MMM yyyy} · {n} guests"`;
  - `originalUnitPrice`, quantity 1, `taxable: false`, `requiresShipping: false`;
  - `email`, `note` "Booking {reference}", `tags: ["Habitat booking"]`;
  - `customAttributes [{key: habitat_checkout, value: id}]`.
- `ShopifyCheckouts::available(Website $site): bool` is true only when the
  connection is `connected` and its `currency` equals the site's homes'
  currency. When it is false, a Shopify site's **Book** shows "Online booking
  is unavailable right now" with the site's phone and email, and no hold is
  made.
- `SiteCheckoutController::store` on a Shopify site:
  - It skips Stripe and returns
    `{checkout_url: invoiceUrl, channel: 'shopify'}`.
  - booking.js then posts `go` when framed, and otherwise calls
    `location.assign`.
- Requests: `WebsitePayments::accept` on a Shopify checkout starts the order
  and emails `RequestAccepted` with the pay link. The payment window is
  `request_hours`.
- Balance: `PayBalance` and `pay-balance` use
  `ShopifyCheckouts::start($c, 'balance', $balance)`, created once.

**3.4 Shopify's webhooks**
- `POST /webhooks/shopify` → `ShopifyWebhookController`.
  - It verifies `X-Shopify-Hmac-Sha256` over the raw body.
  - It deduplicates on `X-Shopify-Webhook-Id`, using the existing webhook
    receipt table if one fits; otherwise the cache for 7 days.
- `orders/paid` → `ShopifyCheckouts::paid(array $order)`. It matches
  `note_attributes.habitat_checkout` and the shop, then checks the amount and
  currency.
  - On a match it calls a channel-neutral confirm, extracted from
    `WebsitePayments::confirmPaid` as
    `WebsiteBookings::confirm(WebsiteCheckout, Payment)`.
  - The Payment comes from `recordExternalPayment` with provider `shopify`,
    `is_collected_by_us = false`.
- The compliance topics answer 200.

**3.5 Lapses**
- `WebsitePayments::lapse` also queues `DeleteShopifyDraftOrder` (tries 5,
  backoff).
- A paid order on a lapsed checkout tries `WebsiteCheckoutService::hold`
  again for the same nights. If that fails, it records the payment and
  raises the alert added in 4.2. Until then, it logs a warning and leaves the
  checkout `expired`.

**3.6 The Shopify section (platform editor)**
- It shows:
  - Connect, Copy install link, Disconnect;
  - the status and currency, with the currency warning;
  - the snippets from Task 2.4.

**3.7 Ship PR 3**
- The full suite, then the PR, then merge when green.

## PR 4: After the booking (outline)

**4.1 Refunds**
- `refunds/create` → `recordExternalPayment` with direction out, against the
  booking (owner-collected).
- On a Shopify booking, the reservation's cancellation shows "Refund on
  Shopify: {amount} due under the cancellation policy", with the
  `calculateCancellationRefund` figure and the order link.

**4.2 The late payment alert**
- `ShopifyPaymentNeedsRefund` notification (platform users and the client's
  users) and mail to `contact_email`, with three reasons: nights taken,
  amount mismatch, checkout missing.

**4.3 Statements**
- `payments.is_collected_by_owner` and `payments.owner_statement_id`.
- `OwnerStatementLine::OWNER_COLLECTED` = `'owner_collected'`, described as
  "Already received through Shopify".
- `OwnerStatementBuilder` takes owner-collected payments (refunds added back)
  for the statement's bookings, or for earlier bookings not yet taken. It
  marks them with the statement on approval, as expenses are marked, and
  takes the total off the net due.
- The PDF and portal labels come with it.

**4.4 "Store only"**
- `websites.store_only`.
- In `SitePageController`, `home`, `page`, `search` and `property` return a
  302 to the store when it is on (property: `store_url` or the primary
  domain).
- The sitemap is empty.
- On the booking page, the brand and menu links point to the store.
- A switch for it goes in the Shopify section.

**4.5 Broken connections**
- `app/uninstalled`, Disconnect, or a 401 or 403 from Shopify sets `broken`.
- **Book** then shows "Online booking is unavailable right now" with the
  site's phone and email.
- The Websites list shows a warning chip.

**4.6 Ship PR 4**
- Run the live check on a Shopify development store once the user has
  created the app. This is the spec's list of five behaviours.
