# Domain model

What the concepts mean, and the rules that hold them together. This is the
document to read before changing anything financial.

## Organization

The tenant. Owns everything else and defines the base currency and timezone that
every default derives from.

Provisioning an organization (`OrganizationProvisioner`) creates it, its first
administrator, a chart of accounts and the standard cancellation policies — the
minimum to add a property and take a booking.

## Property, unit and listing

Three distinct things, and conflating them is the mistake that limits most
systems to one business model.

- **Property** — the asset. An apartment, a house, a building.
- **Unit** — an individual key inside a multi-unit property. A **unit type** is
  the sellable class ("one-bed garden view"); a unit is a specific one.
- **Listing** — an offer. What is described, priced and sold.

One property can carry several listings: the whole house, two rooms let
separately, the same apartment under two brands. Each is a different offer with
its own content, rates and restrictions, and each publishes to different
channels.

A property is activated only once it has a complete address, an occupancy, a
positive base rate and — if multi-unit — at least one sellable unit. A listing
publishes only once it has a title, a description, a photograph, a positive rate
and a bookable property. `PropertyService::activationBlockers()` and
`ListingService::publicationBlockers()` return those reasons in full rather than
one at a time, because fixing five problems one refusal at a time is miserable.

Every published change to a listing writes a `listing_versions` row. The history
is append-only: restoring an old version records the restore as a new version.

## Availability

Availability is computed, never stored as a flag. The inputs are:

- blocking reservations overlapping the window,
- calendar blocks (owner stays, maintenance, manual holds),
- per-day closures and minimum-stay rules in `calendar_days`,
- the listing's own restrictions (minimum and maximum nights, advance notice,
  booking window),
- for multi-unit properties, whether any individual unit is free for the *whole*
  stay.

The last one is the subtle one. A property with three units and three
overlapping bookings may have a free night on paper and no unit that is free for
all four nights of the stay being requested.

`AvailabilityEngine::reserve()` is the only way to take inventory. It opens a
transaction, locks the overlapping blocking reservations for the property with
`SELECT ... FOR UPDATE`, re-checks, and only then runs the caller's callback.
Checking availability and then writing without that lock is how a system
double-books under load.

Inquiries and quotes skip the gate entirely — they hold nothing, and a guest may
legitimately ask about sold-out dates.

## Reservation

The central record. Its lifecycle is an enum with explicit transitions:

```
inquiry ─┐
quote  ──┼──► tentative ──► confirmed ──► checked_in ──► checked_out
         │                      │              │
         └──────────────────────┴──────────────┴──► cancelled / no_show
```

`ReservationStatus` owns the rules: which statuses block inventory, which count
as revenue, and what each may transition to. The API returns
`allowed_transitions` on every reservation so an interface offers exactly what
the server will accept, rather than guessing and being refused.

A reservation is never deleted. Cancelling sets a status, records who cancelled
and why, and computes what the guest is owed. Reinstating is possible while the
dates are still available.

### Pricing a stay

`PricingEngine` produces a night-by-night rate calendar:

1. the base rate — from the listing, the unit type, or the property;
2. the rate plan, which may be independent or derived from a parent by a
   percentage or a fixed amount;
3. per-day overrides from `calendar_days`, which win over everything computed;
4. pricing rules by priority — seasonal, day-of-week, length-of-stay,
   last-minute — each with an optional floor and ceiling so stacked discounts
   cannot take a night below the cost of servicing it;
5. promotions.

Then `FeeCalculator` adds fees by their charge basis (per stay, per night, per
guest, per guest per night, percentage), and `TaxCalculator` applies tax rules
in priority order, honouring caps, exemptions after N nights, and which channel
collects and remits which tax.

Every night is written to `reservation_nights` with the rate that applied. That
is what makes revenue attributable by night, which is what makes owner
statements correct across period and ownership boundaries.

### Modification

Modifying a reservation re-checks availability for the new window, re-prices it,
rewrites the nights and the system charges, and recalculates the totals. Charges
an operator added by hand survive; system-generated ones are rebuilt.
`ReservationService::removeCharge()` refuses to remove a system charge, because
the next re-price would put it back and the operator would reasonably conclude
the system was broken.

## Guest

A first-class record, not a copy of a name on a booking. Guests are matched on
normalised email and phone, accumulate lifetime statistics, and can be merged —
with the loser kept and pointed at the winner (`merged_into_id`) so that
historical references still resolve.

## Owner, ownership and agreement

Three separate things, because they change independently.

- **Owner** — the person or company.
- **Ownership** — a dated share of a property. Both ends are nullable, meaning
  open-ended. Several owners can hold shares of the same property at once.
- **Management agreement** — the commercial terms, dated, scoped to all of an
  owner's properties or to one.

An agreement says precisely what the commission is charged on — accommodation,
fees, taxes — and whether channel commission and payment fees come off first.
Those are stored as separate booleans rather than implied by the commission
model, because "twenty per cent of *what*, exactly" is the question every
statement dispute turns on.

## Owner statement

The most scrutinised document the product produces.

`OwnerStatementBuilder::build()` takes an owner and a period and:

- gathers the nights in the period for properties that owner held, splitting
  each night by the share in force *on that night*;
- applies the agreement in force for that property;
- adds the expenses billable to the owner, with their markup;
- carries forward any previous deficit rather than writing it off;
- writes signed lines so that adding up the column gives the closing balance,
  with no rules to remember.

Approving a statement freezes it. What it consumed cannot be swept into a
second one, and an approved statement cannot be edited — only voided, which is
itself recorded. A payout is created from an approved statement and is
idempotent by statement, so a second click cannot send the money twice.

## The ledger

Double-entry, in `journal_entries` and `journal_lines`. Every financial event
posts: a capture, a refund, an expense approval, a payout.

Posted entries are immutable. A correction is a reversal plus a new entry, and
`PaymentPostingRules::reverseFor()` exists to do exactly that. The alternative —
editing a posted entry — makes the ledger a record of what somebody currently
believes rather than of what happened.

Two flags decide where money lands:

- `is_collected_by_us` — false for a channel that collects from the guest
  itself. The revenue is recognised either way; the debit is cash in one case
  and a receivable in the other. Getting this wrong misstates the bank balance
  by every booking that channel sends.
- `is_simulated` — no real provider processed this. It never affects the
  posting; it affects what the interface is allowed to claim.

## Payment

`PaymentService` distinguishes three things that look similar and are not:

- `authorize()` / `capture()` — a hold, then taking it. The normal card path.
- `charge()` — take it now, no separate hold.
- `recordExternalPayment()` — money that moved without us. A channel that
  collected from the guest, a bank transfer reconciled off a statement, cash at
  the door. Routing this through a provider would be a lie in both directions:
  it would simulate a capture that never happened, and it would fail outright
  for a "provider" like Airbnb that is a distribution channel, not a processor.

Refunds are bounded by what was captured and by the cancellation policy in force
on the reservation. Over-refunding is refused with a 422, not a 500.

## Task

Work with a lifecycle, a priority that implies a service level, an optional
checklist, and rules about completion. A checklist item may require a
photograph, and completion is refused until it exists — `force` is available and
is audited, because the rule exists to settle a complaint about the state of a
property on arrival.

Turnover cleans are generated by `TurnoverScheduler` from confirmed bookings,
keyed so regeneration is idempotent. A booking that moves moves its clean; a
booking that cancels cancels it. A clean left at the old date is worse than no
clean at all — somebody turns up to an occupied flat and nobody turns up to the
empty one.

## Conversation

One thread per booking party, carrying inbound messages, outbound messages and
internal notes. The inbox sorts by who has been waiting longest rather than by
what arrived last, because the guest who wrote four hours ago and has heard
nothing is the one who matters.

Messages record their provenance: which automation sent them, whether a model
drafted them, which transport carried them, and whether that transport actually
sent anything.

## Review

Reviews arrive on different scales. The raw score is kept with *its own* scale,
alongside a normalised percentage. Nine out of ten and nine out of five are not
the same review, and an average that has forgotten which is which is worthless —
so the API says so in a `meta.note` on the summary endpoint, and the interface
prints it.

Hiding a review hides it from internal lists. It does nothing to the copy the
public can still read on the channel, and the field is named
`is_hidden_internally` so no interface can imply otherwise.

## Channel

A `ChannelAccount` is a connection; a `ChannelListing` maps one of our listings
to one of theirs. Neither is ever deleted — an account is referenced by every
booking that came through it — so they are disconnected and deactivated instead.

What each channel actually reaches is described in
[integrations.md](integrations.md).

## Access code

Issued against a smart lock for a reservation's stay window, padded in the
property's timezone. A code is only marked active if the provider accepted it;
a provider that times out leaves a row explaining what was attempted rather than
nothing at all. A cancelled booking revokes its codes immediately — a guest
whose booking was cancelled and whose code still works can walk into a property
that has been re-let.
