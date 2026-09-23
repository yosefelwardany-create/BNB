# Development roadmap

What is built, what is deliberately not, and what would be next. Written to be
read by somebody deciding whether they can rely on this.

## Built

### Foundation

- Multi-tenancy: `organization_id` everywhere, a global scope that cannot be
  forgotten, tenant selection by explicit header, and a security test that walks
  every path across the boundary.
- Authentication: Sanctum tokens, bcrypt, throttled endpoints, login history,
  signed verification and reset links.
- Authorisation: 107 permissions, 11 system roles, per-record policies,
  per-seat grants and revocations, property restriction, and a cache that
  invalidates when a role changes.
- Audit: who changed what, with before and after values, redacting anything the
  model hides.
- Money: an immutable integer value object with exact allocation.
- Queues, scheduling, and a domain event store that is the integration seam.

### Portfolio

Portfolios, complexes, properties, unit types, units, listings, photos, rooms,
amenities. Activation and publication gated on real readiness checks that return
every blocker at once. Listing versions, append-only.

### Reservations

Availability computed from bookings, blocks, per-day rules, listing
restrictions and per-unit allocation, taken under a row lock so the system
cannot double-book under load. A reservation lifecycle with enforced
transitions, night-by-night storage, modification with re-pricing, cancellation
against a policy snapshotted at booking, and reinstatement.

### Pricing and tax

Rate plans (independent and derived), pricing rules by priority with floors and
ceilings, per-day overrides, promotions, fees by six charge bases, and tax rules
with caps, exemptions and per-channel collection. Quotes that freeze a
breakdown and expire.

### Guests and owners

A deduplicating guest directory with merge, lifetime statistics, and duplicate
clustering. Owners with dated ownership shares, dated management agreements
that state exactly what commission is charged on, and portal access.

### Operations

Tasks with a lifecycle, priorities implying service levels, checklists with
per-item photo requirements, recurrences, teams, staff availability and
vendors. Turnover cleans generated from bookings and kept in step when a
booking moves or cancels.

### Messaging

Conversations per booking party with inbound, outbound and internal messages in
one thread. Templates with a documented vocabulary, saved replies, an automation
engine reading the event stream, and notifications with per-user preferences.

### Money

A double-entry ledger with immutable posted entries and correction by reversal.
Payments with authorise, capture, charge, external recording, refund and void.
Payment schedules. Expenses with markup and approval. Owner statements built
night by night, frozen on approval, with deficits carried forward. Owner payouts,
idempotent by statement.

### Distribution

Channel accounts and listing mappings with separate dirty flags for availability
and rates, a synchronisation log recording every conversation with a channel,
health reporting, and reservation import.

### Revenue management

Occupancy, ADR, RevPAR, pace and lead time, computed from reservation nights at
request time rather than from a rollup that would eventually disagree with the
bookings it summarises.

### Reporting

Seven reports, each declaring its own permission, with typed columns, totals,
caveats returned as data, CSV export with formula injection neutralised, and
saved reports on a delivery schedule.

### Guest experience

A guest portal on a token, online check-in, guest payments, reviews normalised
across rating scales, upsells with lead times and capacity, documents, and smart
locks with access codes tied to the stay window.

### Platform

API keys with abilities and per-key limits, outbound webhooks with HMAC
signatures and a delivery log, and an SSRF-resistant URL rule.

### Interface

A React 19 + TypeScript admin SPA: dashboard, calendar, reservations, inbox,
operations board, properties, guests, owners, reviews, channels, financials,
revenue, reports and developer settings. Every provenance flag the API reports
is displayed.

### Demo and CI

A demo portfolio built entirely through the real services, and a test asserting
its invariants. CI on two PHP versions, plus a job that migrates from empty,
seeds, and rolls back.

## Deliberately not built

These are decisions, not omissions.

**Real OTA integrations.** Airbnb, Booking.com, Vrbo and Expedia all require a
commercial partner agreement before their APIs can be used. Rather than ship
dead buttons, the adapter interface is complete and a simulated adapter stands
in — and says so everywhere. Connecting a real channel is one class and a
credential.

**A real payment provider.** Same reasoning. `MockPaymentProvider` implements
the whole contract; Stripe or Adyen is one class and a key.

**A real smart-lock vendor.** Same again.

**An LLM provider.** `EchoAIProvider` exercises the drafting workflow
deterministically. Nothing is sent to a guest without a person approving it, and
that stays true whichever provider is configured.

**Visual polish.** The interface is deliberately plain. Correctness,
authorisation and data integrity came first, and a separate design pass is the
right place to spend effort on the look.

## Known gaps

Stated plainly, because an undisclosed gap is worse than an open one.

**MFA is modelled but not enforced.** The schema and the user fields exist; the
login challenge does not. Setting `mfa_enabled` today changes nothing. This is
the most significant gap in the product.

**No frontend tests.** The SPA is typechecked and built in CI; no component
behaviour is tested and nothing exercises the interface end to end.

**No load testing.** The locking strategy is argued for and tested for
correctness, not measured under contention.

**Channel message sync is partial.** Messages can be recorded from a channel;
replying into a channel thread requires the thread id, and the simulated adapter
does not model every channel's threading rules.

**Reporting has no scheduled-delivery transport beyond email.** A saved report
can be scheduled, and email is the only way it arrives.

**Occupancy denominators assume active properties.** RevPAR counts nights
available from the active property count for the period, which is right for a
stable portfolio and slightly wrong in the month a property is onboarded.

**No public booking engine.** The API can take a direct booking; there is no
guest-facing website in this repository.

## Next, in the order I would do it

1. **MFA at login.** The largest gap, and the schema is already there.
2. **Frontend tests.** The SPA has grown past the point where typechecking is
   enough.
3. **One real payment provider.** It would validate the abstraction against
   reality, which is the only thing that ever validates an abstraction.
4. **The iCal path end to end.** It is already live, requires no partner
   agreement, and is how most small operators actually connect.
5. **A booking engine.** The pricing, availability and quoting are done; what is
   missing is a guest-facing surface over them.
6. **Owner statement PDFs.** Owners want a document, not a screen.
7. **A second locale.** The schema carries `language` and `locale` throughout
   and nothing has been translated, so the assumption is untested.

## Conventions for anyone continuing this

A feature is not complete until it has: a migration, a model, business logic in
a service, an authorisation policy, an API resource, a controller, routes with
permissions, events where something else needs to react, a scheduled command if
it has recurring work, tests, and a paragraph in these docs.

Do not solve a problem by deleting data. Reservations, financial records,
owners, guests, audit records, statements and payments outlive the code that
produced them. Migrate, transform, version, archive or reverse.

Never let the interface claim more than the system did. If a provider is
simulated, say so in a field, return that field, and show it.
