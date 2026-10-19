# Habitat

A multi-tenant property management platform for short- and medium-stay rentals:
one system of record for properties, bookings, guests, owners, money and the
conversations in between.

This document describes what exists, in the code, today — including what does
not work. For how to run it, see [`README.md`](../README.md).

---

## At a glance

| | |
| --- | --- |
| Domains | 29 |
| Database tables | 121, across 47 migrations |
| Models / services / jobs | 93 / 99 / 7 |
| API endpoints | 398, across 12 route files |
| Console commands | 24 |
| Backend code | 589 PHP files |
| Frontend | 114 TypeScript files, ~22 screens |
| Tests | 1,067 backend (4,430 assertions) + 333 frontend |
| Deployed services | 5 on Render |

## The stack

**Backend** — PHP 8.4, Laravel 13.17, PostgreSQL 16, Redis for cache, queues
and locks.

**Frontend** — React 19, TypeScript 5.7, Vite 6, TanStack Query 5, React
Router 7. A single-page application, not Blade.

**Hosting** — Render for compute, Neon for Postgres.

---

## Architecture

A **modular monolith**. Every feature lives under `app/Domain/{Domain}/` with
its own models, services, jobs, events and exceptions. One deployable, with the
internal boundaries of a set of services — chosen because a two-person team
distributing a system across network calls spends its time on the network
rather than the product.

Three decisions run through everything:

**Identifiers are ULIDs.** Sortable by creation time, safe to generate before
an insert, and they do not leak a count of your customers the way an
auto-increment does.

**Money is an integer in minor units.** There is no float anywhere in the
financial path. A `Money` value object carries the currency with the amount, so
two currencies cannot be added by accident.

**Tenancy is enforced by a global scope, not by discipline.** `BelongsToOrganization`
adds an `organization_id` filter to every query on every tenant-scoped model.
Code that forgets to filter still cannot read another organization's rows.
`TenantContext` is the only way past it: `withoutScope()` for console commands
that legitimately span tenants, `runAs()` for a job that must act as one.

### The tenancy model

Deliberately not the obvious one. **Users have no `organization_id`.**

Access comes from `memberships`: a row per user per organization, carrying their
roles, their `default_portal`, and optionally `restricted_to_properties` with a
`membership_property` pivot. One person can therefore hold a manager seat in one
organization and an owner seat in another, without duplicate accounts.

`AccessControl::restrictedPropertyIds()` returns `null` for somebody
unrestricted and a list for somebody who may only see certain properties. The
distinction matters: an empty list would mean "sees nothing", and conflating the
two is how a property scope silently shows everything.

---

## The domains

**Property and inventory**

| Domain | What it holds |
| --- | --- |
| `Properties` | properties, rooms, photos, documents, amenities, helpers |
| `Listings` | what is advertised, with versions and photos |
| `Organization` | tenants, portfolios, complexes, units, unit types |
| `Availability` | the calendar engine, blocks, and imported availability |

**Demand and money**

| Domain | What it holds |
| --- | --- |
| `Reservations` | bookings, nights, charges, status history, guests on a stay |
| `Pricing` | rate plans, pricing rules, promotions, quotes, revenue analytics |
| `Payments` | payments, refunds, schedules, external money a channel collected |
| `Accounting` | ledger accounts, journal entries and lines |
| `OwnerAccounting` | owner statements, statement lines, payouts |
| `Upsells` | products and orders |

**People**

| Domain | What it holds |
| --- | --- |
| `Guests` | guest records, the guest portal, ID verification |
| `Owners` | owners, ownerships, management agreements, the client portal |
| `Users` | accounts, memberships, roles, permissions, invitations, MFA |

**Operations and communication**

| Domain | What it holds |
| --- | --- |
| `Operations` | tasks, checklists, recurrences, teams, vendors, staff availability |
| `Messaging` | conversations, messages, templates, saved replies |
| `Notifications` | in-product notifications and per-user preferences |
| `Reviews` | reviews and responses |
| `Locks` | smart locks and access codes |

**Distribution and integration**

| Domain | What it holds |
| --- | --- |
| `Channels` | channel accounts, listings, the pull engine, webhook events |
| `Integrations` | every provider abstraction and its implementations |
| `Webhooks` | outbound endpoints and delivery records |
| `Api` | the public API and its keys |

**Platform and plumbing**

| Domain | What it holds |
| --- | --- |
| `Agents` | the per-property guest agent, its gates, knowledge and activity log |
| `Automation` | rules and runs |
| `Documents` | generated statements, invoices, receipts |
| `Reports` | saved reports and scheduled runs |
| `Audit` | what people changed |
| `Events` | the domain event store |
| `Platform` | the operator console: plans, settings, announcements, impersonation |

---

## What an operator can actually do

Twenty-two screens, each with a *New* button in the header and an *Edit* on the
row — not endpoints without forms. The principle is explicit:

> **Every record a channel would import can be typed in.**

Properties, listings and photos. Reservations and calendar blocks. Guests,
owners, ownerships and agreements. Payments, including money a channel
collected. Expenses, charges, invoices. Reviews. Tasks, checklists, vendors,
access codes. Rates, rate plans, pricing rules. **Guest messages in and out.**

That matters on the day an integration is not there: the platform degrades to
manual entry rather than to nothing.

### The portals

One codebase serves three audiences, separated by `default_portal` on the
membership:

- **Management** — the full platform.
- **Owner / client** — their properties, their calendar, their share of
  revenue. Read-only.
- **Guest** — their stay, their arrival details, their messages.

---

## The guest agent

A per-property AI agent that answers a guest's question, drafts a reply, and
sends it unattended **only** when that property's brief allows it — for that
subject, at that confidence.

Four gates sit between an answer and a guest, in `AgentGates`:

1. **The property's escalation list** — a keyword match on the guest's words,
   in code rather than asked of the model. "Send anything about the neighbours
   to a person" must hold even when the model disagrees.
2. **Intent** — only subjects on a fixed constant are ever auto-sendable.
   Nothing a customer types into their own settings can make an unclassifiable
   question answer itself.
3. **Confidence** — below the property's floor, it holds. An agent that is
   unsure and sends anyway is worse than one that waits, because the guest acts
   on the answer either way.
4. **Withheld facts** — if it had to refuse something, a person reads the
   wording first.

Entitlement is not one of the gates, deliberately. It happens earlier, in
`PropertyKnowledge`, by deciding what enters the prompt at all — so a door code
the guest is not entitled to is absent from the context rather than guarded by
an instruction.

Failing a gate **holds** the draft with its reason recorded. It never discards
it. Every attempt writes an `agent_activities` row carrying the intent, the
confidence, the provider, what was withheld, and the draft itself.

Two commands read that back:

```
php artisan agents:replies            # what the agents said, and what they held
php artisan agents:why-no-reply <thread>   # which gate closed, for one thread
```

---

## Integrations, and what is really connected

Every integration is a provider abstraction with at least one working
implementation. Where a real one is impossible, a local implementation stands
in — and **says so**.

**Channels**

| Channel | Status |
| --- | --- |
| Hostex | live adapter against a real API |
| iCal | live — a real, open protocol |
| Direct booking | live — our own engine, no external system |
| Airbnb, Booking.com, Vrbo, Expedia, Google Vacation Rentals | simulated, pending partner agreements |

**AI providers** — `claude` (real), `bot` (an HTTP endpoint per property, for
operators who run their own), `echo` (local simulation), `null`.

**Message transports** — `channel` (the OTA's own thread), `email`, `local`.

### The honesty flags

The rule is: **never pretend a real integration is active when it is not.**
Enforced by columns, not by intention.

| Flag | Says |
| --- | --- |
| `is_simulated` + `simulation_reason` | nothing left the building, and why |
| `is_ai_generated` + `ai_provider` | a model wrote this, and which |
| `was_autonomous` | nobody read it before it went |
| `logged_by_hand` | a person typed this, it did not arrive |
| `delivered_by_hand` | a person carried it out themselves |

A simulated send still stores `status = 'sent'`, because that is what the
transport returned — so anything reporting on delivery must read
`delivery.simulated` as well. Counting the two together is how a demo tells its
most convincing lie.

---

## Deployment

Five services on Render, all auto-deploying from the default branch:

| Service | Type | Does |
| --- | --- | --- |
| `habitat-api` | web | the application and the SPA |
| `habitat-worker` | worker | `queue:work` plus `channels:watch`, under supervisord |
| `habitat-scheduler` | cron | the Laravel schedule |
| `habitat-redis` | redis | cache, queues, locks |
| `habitat-bot-bridge` | web | the per-property bot endpoint |

**The inbound clock is not the schedule file.** `channels:watch` is a
60-second loop in the worker calling `channels:pull --automatic`; `ImportCadence`
then gates each stage — reservations and messages every 5 minutes, availability,
transactions and listings hourly, properties every 6 hours. The hourly bare
`channels:pull` is a slower full sweep. Reading `routes/schedule.php` alone
gives the wrong picture.

Environment variables are **per service**. A key set on `habitat-api` is not
visible to `habitat-worker`, which is how an agent once reported a provider as
configured while every reply failed on the machine that lacked the key.

---

## Tests

```
php artisan test        # 1,067 tests, 4,430 assertions
cd frontend && npm test # 333 tests
./vendor/bin/pint       # formatting
```

Feature tests over unit tests, against a real PostgreSQL database. The suite
asserts behaviour through the HTTP layer — including tenancy isolation, which is
tested by trying to read another organization's rows and being refused.

Run suites **one at a time**. Two concurrent runs deadlock on `bnb_testing`
while dropping tables.

---

## Known gaps

Recorded because a platform that hides its holes gets trusted in the wrong
places.

**Owner statements are accommodation-only.** `OwnerStatementBuilder` never
populates `totals['fees']`, `['taxes']`, `['channel_commission']` or
`['payment_fees']`. The `commission_on_fees` setting is read and does nothing.
At a handful of properties this is reconcilable by hand; at a hundred it is not.

**The maintenance reserve is a flat amount** taken from the owner's balance,
rather than a percentage of the management share.

**`maintenance_approval_threshold` is validated and never read.**

**A dead channel connection still reports as live.** `HostexChannelAdapter::isLive()`
returns `true` unconditionally, contradicting its own docblock, and a failing
pull records the error inside `last_pull_result` without ever marking the
account's `status`. So an expired subscription shows green.

**Stale imported availability blocks new bookings.** `AvailabilityEngine` reads
a snapshot from the last successful pull; nights that snapshot calls unavailable
are refused with "refresh the connection" — advice that cannot be followed once
the connection is gone.

**Every pre-booking enquiry is held.** `reasonsToWithhold()` returns a reason
for any conversation with no reservation attached, and gate 4 holds on any
reason at all. So a prospect asking whether there is a kitchen is held
permanently, and filling in the knowledge base cannot change it.

**Open roadmap items** — the owner portal's client-onboarding phase, and
removing the legacy platform console.

---

## Where to read more

| Document | Covers |
| --- | --- |
| [`architecture.md`](architecture.md) | the shape of the system |
| [`domain-model.md`](domain-model.md) | the entities and their relationships |
| [`database.md`](database.md) | tables and migrations |
| [`api.md`](api.md) | the public API |
| [`agents.md`](agents.md) | the guest agent in full |
| [`integrations.md`](integrations.md) | every provider abstraction |
| [`channel-message-import.md`](channel-message-import.md) | how messages arrive |
| [`hostex-sync.md`](hostex-sync.md) | the live channel integration |
| [`automatic-property-import.md`](automatic-property-import.md) | importing a property |
| [`property-agent-memory.md`](property-agent-memory.md) | what an agent remembers |
| [`managed-service.md`](managed-service.md) | running it for clients |
| [`security.md`](security.md) | authentication, authorization, audit |
| [`deployment.md`](deployment.md) | Render and Neon |
| [`testing.md`](testing.md) | how the suite is organised |
| [`development-roadmap.md`](development-roadmap.md) | what is planned |

## Scope

Guesty and comparable platforms are a functional benchmark only. No code,
algorithm, trademark, asset, interface text or design element is taken from
them. Everything here is built from scratch.
