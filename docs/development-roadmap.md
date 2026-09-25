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
one thread. A reply to a thread that came from a channel goes back into that
channel's own inbox, and says which precondition failed when it cannot.
Templates with a documented vocabulary, saved replies, an automation engine
reading the event stream, and notifications with per-user preferences.

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
bookings it summarises. Nights available count the estate as it was: each
property contributes only the nights between the day it went on the market and
the day it came off, so an onboarding month is not charged with nights nobody
owned and archiving a flat does not retroactively improve last year.

Multi-currency throughout, against stored rates read by date. An unknown rate is
refused rather than guessed, because a conversion at a plausible wrong rate is
wrong by exactly the amount nobody notices until an audit.

### Reporting

Seven reports, each declaring its own permission, with typed columns, totals,
caveats returned as data, CSV export with formula injection neutralised, and
saved reports on a delivery schedule. A schedule can deliver to several places
at once — email, a signed webhook, or a stored file kept against the report so
an earlier run can be opened again. The report is rendered once and every
destination gets the same bytes.

### Guest experience

A guest portal on a token, online check-in, guest payments, reviews normalised
across rating scales, upsells with lead times and capacity, documents, and smart
locks with access codes tied to the stay window.

### Platform

API keys with abilities and per-key limits, outbound webhooks with HMAC
signatures and a delivery log, and an SSRF-resistant URL rule.

### The platform console

A second interface, for whoever runs the platform rather than a portfolio. It
runs outside any organization and governs all of them: plans with feature flags
and caps, per-tenant overrides, suspension and reinstatement, trials, published
announcements, provider health, its own audit trail, and settings that change
without a deploy.

Two decisions in it are load-bearing. There is **no delete-organization**, at
any level — a customer who leaves is suspended and kept, because their
reservations, ledger entries and statements outlive the decision to stop paying.
And support access is a **read-only impersonation session** with a stated
reason, which the customer sees in their own account under "who has looked at
your account". An access log only the operator can read is not a log, it is a
back door with a record attached.

Plan caps refuse the next creation rather than deleting the excess, and answer
402 with the limit and the current usage, so the customer's own subscription
screen can explain a refusal before it happens.

### Interface

A React 19 + TypeScript admin SPA: dashboard, calendar, reservations, inbox,
operations board, properties, guests, owners, reviews, channels, financials,
revenue, reports, subscription and developer settings, plus the platform
console as a separate shell. Every provenance flag the API reports is
displayed, and there are tests holding each of them in place.

### Security

Two-factor authentication enforced at login: TOTP to RFC 6238 implemented from
scratch and checked against the RFC's own test vectors, single-use recovery
codes, and a challenge that authorises nothing until the code is right. An
organization can require it of everybody; the platform can require it of its
own administrators.

Guest identity verification, with a verifier abstraction and a local
implementation that performs the checks it honestly can — expiry, number shape,
minimum age — and never marks anybody verified, because it cannot confirm a
document exists or that the holder is present. Only a named person, recording a
reason, can.

### Documents

Owner statements, invoices and receipts as PDFs, rendered server-side with no
remote content and no PHP execution in the renderer, stored with a SHA-256
checksum so a dispute about which copy was sent can be settled.

### Demo and CI

A demo portfolio built entirely through the real services, and a test asserting
its invariants. CI runs the backend suite against PostgreSQL and Redis, lints,
typechecks, tests and builds the frontend, and separately migrates from an
empty database, seeds, and rolls back.

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

Stated plainly, because an undisclosed gap is worse than an open one. Each of
these was true when it was written and is still true now; the ones that have
been closed have been removed rather than quietly reworded.

**No load testing.** The locking strategy is argued for and tested for
correctness, not measured under contention. `SELECT ... FOR UPDATE` on the
availability path is the right shape, and nobody has measured what it does at a
thousand concurrent quotes.

**Channel threading is modelled on one shape.** A reply now goes into the
channel's own inbox through the adapter, but the simulated adapter models a
single threading convention. Airbnb, Booking.com and Expedia each differ in how
a thread is addressed and how long it stays open, and none of those differences
can be discovered without the real API.

**No public booking engine.** The API can take a direct booking; there is no
guest-facing website in this repository.

**One locale.** The schema carries `language` and `locale` throughout, and
nothing has been translated, so the assumption is untested. It is a gap of the
kind that only shows up as a pile of small wrongnesses — date order, currency
placement, pluralisation — rather than as a failure.

**Retention is configured, not enforced.** `retention_until` is set on
documents and honoured by the report destination that writes them. Nothing
sweeps the rest of the schema for records past their retention, so a deployment
with a legal retention policy needs a job this repository does not have.

**Property retirement is not backfilled.** Occupancy counts each property only
for the nights between activation and archiving. A property archived before
that column existed has no recorded retirement date, and rather than invent a
plausible one it is excluded from availability, exactly as it was before.
Historical occupancy for such a portfolio is therefore still slightly generous.

**Automatic sending is decided but not scheduled.** A property's brief can say
that amenity questions may be answered without review, and the agent computes
`would_auto_send` on every draft — but nothing yet acts on it unprompted. A
person presses a button. Wiring it to the inbound-message job is a small change
and a large decision, so it waits for someone to make that decision with a real
portfolio in front of them, not for the code to be written. Until then the flag
is reported honestly rather than implied: the bench says "would send on its own",
not "sent".

## Next, in the order I would do it

1. **One real payment provider.** It would validate the abstraction against
   reality, which is the only thing that ever validates an abstraction, and
   payments is where being wrong is most expensive.
2. **The iCal path end to end.** It is already live, requires no partner
   agreement, and is how most small operators actually connect.
3. **A booking engine.** The pricing, availability and quoting are done; what is
   missing is a guest-facing surface over them.
4. **A retention sweeper.** The dates are recorded and nothing acts on them,
   which is the worst of both: the appearance of a policy without one.
5. **A second locale.** Until something is translated, every assumption about
   language and formatting is untested.
6. **Load testing the availability lock.** Not because it is suspected, but
   because "argued for" and "measured" are different words.
7. **The agent answering by itself.** The gates are built and scored; what is
   missing is the job that acts on `would_auto_send` when a guest writes in.
   Worth doing after a week of watching what the agent *would* have sent on a
   real portfolio, which the bench and the eval suite already make possible.

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
