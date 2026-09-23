# API

A JSON API under `/api/v1`, plus a small unauthenticated surface under
`/api/public` for guest portal links. 323 routes in total; `php artisan
route:list` is the authoritative index.

## Conventions

### Authenticating

Two credentials, for two different callers.

**A person** signs in at `POST /api/v1/auth/login` and receives a bearer token:

```
Authorization: Bearer <token>
X-Organization: <organization ulid>
```

The organization header is required, not inferred. A user may work for more than
one company, and a server that guesses which one will eventually guess wrong.
`GET /api/v1/auth/me` returns the user, the active organization, the membership,
the effective permission list and any property restriction.

**A machine** uses an API key, sent the same way or as `X-Api-Key`:

```
Authorization: Bearer pms_<48 random characters>
```

A key names its own organization, so the `X-Organization` header is ignored for
key-authenticated requests — allowing a key to select an organization by header
is exactly the escalation the header would otherwise invite. Keys carry an
explicit ability list, a per-key rate limit and an optional IP allow-list, and
only a SHA-256 hash is stored. The token is returned once, by the call that
created it, and cannot be recovered.

### Authorising

Every route declares the permission it needs:

```php
Route::get('/', [ExpenseController::class, 'index'])
    ->middleware('permission:expenses.manage,financials.view')->name('index');
```

Several names on one route means any of them suffices. Beyond that, model
policies decide per-record access — an owner may read their own statement and
not somebody else's — and are discovered by convention:
`App\Domain\X\Models\Y` → `App\Domain\X\Policies\YPolicy`.

There are 107 permissions. They are granular on purpose: `channels.manage`
changes what a channel *is*, `channels.map` points a channel's calendar at a
particular apartment, and `channels.sync` merely asks the platform to do now
what it does on a schedule. Folding those into one would mean handing a revenue
manager the ability to redirect a calendar.

### Pagination

Collection endpoints return Laravel's standard envelope:

```json
{
  "data": [ ... ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "from": 1, "last_page": 4, "per_page": 25,
            "to": 25, "total": 94 }
}
```

`per_page` is accepted up to 200 and defaults to 25.

### Money

Every monetary field is an object:

```json
{ "amount": 14500, "currency": "EUR", "formatted": "145.00" }
```

`amount` is integer minor units and is what a client should compute with.
Requests that take an amount take minor units — there is no endpoint that
accepts `145.00`.

### Errors

```json
{
  "message": "The stay is not available for those dates.",
  "errors": { "check_in_date": ["..."] }
}
```

| Status | Means |
|---|---|
| 401 | No credential, or one that is expired or revoked |
| 403 | Authenticated, not permitted |
| 404 | Not found, or not yours — the two are deliberately indistinguishable |
| 409 | A conflicting change happened first |
| 422 | Validation failed, **or** a domain rule refused |
| 429 | Rate limited |

422 for a domain refusal is the established convention here: over-refunding a
payment, statementing an unapproved expense and booking unavailable dates all
return 422 with the reason, not a 500. A refusal is an answer, not a fault.

### Rate limits

Authentication endpoints are throttled individually (5–30 per minute, tightest
on password reset and registration). Guest portal routes are throttled *by
token* rather than by address: a family sharing one hotel wifi must not throttle
each other, and somebody guessing tokens is one address trying many tokens,
which an address-keyed limiter would barely slow. API keys carry their own
per-key limit.

## Areas

Routes are split by area under `routes/api/`, and each file opens with a comment
explaining its permission model. The areas:

| File | Covers |
|---|---|
| `organization.php` | Organization, users, roles, teams, invitations, notifications |
| `properties.php` | Portfolios, properties, units, listings, photos, amenities |
| `reservations.php` | Bookings, calendar, quotes, charges, cancellation |
| `people.php` | Guests, owners, ownerships, agreements, owner portal access |
| `operations.php` | Tasks, checklists, recurrences, teams, vendors |
| `messaging.php` | Conversations, messages, templates, automation |
| `finance.php` | Payments, refunds, schedules, expenses, statements, payouts |
| `channels.php` | Channel accounts, mappings, sync log |
| `revenue.php` | Rate plans, pricing rules, fees, taxes, promotions, analytics |
| `reporting.php` | The report catalogue, runs, exports, saved reports |
| `experience.php` | Reviews, upsells, documents, smart locks, access codes |
| `platform.php` | API keys and webhook endpoints |

## A worked example: taking a booking

**Price it first.** Nothing is held.

```http
POST /api/v1/reservations/quote
{ "listing_id": "01H...", "check_in_date": "2026-07-04",
  "check_out_date": "2026-07-09", "adults": 2 }
```

The response carries the night-by-night breakdown, the fees, the taxes and the
grand total, plus whether the dates are actually available and why not if they
are not.

**Then book it.**

```http
POST /api/v1/reservations
{ "listing_id": "01H...", "check_in_date": "2026-07-04",
  "check_out_date": "2026-07-09", "adults": 2,
  "guest": { "first_name": "Marta", "last_name": "Silva",
             "email": "marta@example.com" } }
```

This re-checks availability under a lock and re-prices. The quote is a quote,
not a reservation: between the two calls somebody else may have booked.

**Take money.** Amounts are minor units.

```http
POST /api/v1/payments
{ "reservation_id": "01H...", "amount": 32250, "currency": "EUR" }
```

The response says `is_simulated` if no real provider is configured. See
[integrations.md](integrations.md).

**Follow the lifecycle.** Each reservation carries `allowed_transitions`;
`POST /api/v1/reservations/{id}/{action}` performs one.

## Reports

`GET /api/v1/reports` lists the reports *this caller* may run — filtered on the
server, not listed and refused later. Each declares its own permission, because
an occupancy report and an owner profit-and-loss are not the same disclosure.

`GET /api/v1/reports/{key}?period=last_month` runs one and returns rows, totals,
column definitions with types, and a `notes` array of caveats. The notes are
part of the answer: a report read without them is a report read wrongly.

`GET /api/v1/reports/{key}/export` streams the same as CSV. Values beginning
`=`, `+`, `-`, `@`, tab or carriage return are prefixed with an apostrophe, so
a property named `=cmd|...` is text in a spreadsheet rather than a formula.

## Webhooks

Register an endpoint (`POST /api/v1/webhook-endpoints`) and the signing secret
is returned once. Deliveries are signed:

```
X-Habitat-Signature: t=1751635200,v1=<hex hmac sha256>
```

The signed payload is `"<timestamp>.<raw body>"`. Verify by recomputing the
HMAC over that string and comparing with a constant-time function, and reject
timestamps outside your tolerance — the timestamp is in the signature precisely
so a captured delivery cannot be replayed a week later.

An endpoint with no event list receives everything. 4xx responses other than 408
and 429 are abandoned immediately rather than retried; there is no point
retrying a 400. Failures back off, and repeated failure disables the endpoint
rather than retrying forever.

`webhook_deliveries` keeps the payload byte for byte as it was sent, not
regenerated on demand: regenerating would show the record as it stands now,
which settles no signature dispute and misleads once the subject has changed.

## The guest portal

`/api/public/portal/{token}` is unauthenticated by design — the token is the
credential. A guest can see their booking, complete online check-in, message the
property and pay a balance.

The property's exact address is withheld until the booking is paid and arrival
is near. That is a deliberate product rule, not an oversight: a portal link is
forwardable, and a link that reveals an address to anyone who receives it is a
security problem for the guest staying there.
