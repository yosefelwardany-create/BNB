# Testing

461 tests, 1509 assertions, against a real PostgreSQL database.

```bash
php artisan test                      # everything
php artisan test --filter=PricingEngineTest
vendor/bin/pint --test                # formatting
cd frontend && npm run typecheck && npm run build
```

## Setup

The suite needs PostgreSQL. `phpunit.xml` points it at `bnb_testing`, separate
from the development database, and `RefreshDatabase` migrates it per test class.

```bash
createdb bnb_testing -O bnb
```

SQLite is not an option, even though it would be faster. The schema relies on
`jsonb`, partial unique indexes, check constraints and `SELECT ... FOR UPDATE`;
a suite that passed on SQLite would not have exercised a single one of the
guarantees the product actually depends on. A green run against the wrong engine
is worse than no run at all, because it is believed.

`phpunit.xml` also pins the providers (`PAYMENTS_DEFAULT_PROVIDER=mock`,
`AI_DEFAULT_PROVIDER=echo`, `LOCKS_DEFAULT_PROVIDER=mock`), sets the queue to
`sync` so listeners run inline, and drops bcrypt to four rounds.

## How the suite is organised

```
tests/
  Api/          The HTTP contract: status codes, shapes, authentication
  Feature/      One directory per domain — the bulk of the suite
  Security/     Tenant isolation
  Unit/         Pure logic: Money, and the support layer
  TestCase.php  Shared helpers
```

Most tests are feature tests. They go through the container, hit the database
and run the real services, because the interesting failures in this product are
not in a single function — they are in the interaction between pricing, the
ledger and an owner's agreement.

`TestCase` provides `createOrganization()`, `createUser()`, `actingAsUser()`,
`createTenantWithAdmin()` and `withoutTenantScope()`. `tearDown()` clears the
tenant and the permission memo, because tenancy is a singleton for the lifetime
of a request and a leaked tenant makes the *next* test fail.

## What is actually protected

The suite is not organised around coverage. It is organised around the failures
that would cost a customer money or a guest their holiday.

**Availability cannot double-book.** `AvailabilityEngineTest` covers overlapping
stays, multi-unit allocation where a night looks free but no single unit is free
for the whole stay, and stay restrictions.

**Reservations follow their lifecycle.** `ReservationLifecycleTest` covers legal
and illegal transitions, modification re-pricing, cancellation refunds against
the policy snapshotted at booking, and reinstatement.

**Pricing is deterministic.** `PricingEngineTest` covers rate plan derivation,
per-day overrides winning over computed rates, rule priority, floors and
ceilings, fees by every charge basis, and taxes with caps and exemptions.

**The ledger balances.** `JournalPosterTest` covers the posting rules, the
immutability of posted entries, and correction by reversal.

**Owner statements are right.** `OwnerStatementBuilderTest` is the most
load-bearing file in the suite. It covers night-by-night attribution across
period boundaries and changes of ownership, joint owners receiving their share,
the lines summing to the closing balance, the freeze on approval, a deficit
carrying forward, and consumed revenue not being swept into a second statement.

**Tenants cannot see each other.** `TenantIsolationTest` creates two
organizations with overlapping data and walks every list, show, update and
delete path across the boundary.

**Permission changes take effect.** `AccessControlTest` covers role edits,
per-seat grants and revocations, property restriction, and the cache
invalidation bug described in [security.md](security.md#the-permission-cache).

**Nothing claims to be live when it is not.** `DemoSeederTest` builds the whole
demo portfolio and asserts that the ledger balances, the statements add up, no
property is double-booked, every access code is marked simulated, every payment
is either simulated or held by somebody else, no channel adapter reports itself
as live, and running the seeder twice changes nothing.

## Conventions

**Names are sentences.** `test_a_sole_owner_receives_the_whole_revenue_less_the_fee`,
not `test_build_1`. The name should say what breaks if it goes red.

**The docblock says what is protected and why.** A test whose purpose has to be
reverse-engineered from its assertions will be deleted by the next person who
finds it inconvenient.

```php
/**
 * Owner statements.
 *
 * The most scrutinised document the product produces, and the one where being
 * quietly wrong costs a client relationship rather than a support ticket.
 */
```

**Assert the number, not that a number exists.** `assertSame(32000, ...)` beats
`assertGreaterThan(0, ...)`. A test that only checks something happened passes
when the arithmetic is wrong.

**Exercise the real path.** Where a test needs a smart lock, it registers one
with the mock provider's own inventory rather than stubbing the provider, so the
issuing path runs rather than being short-circuited.

**Use the services.** Building fixtures with raw inserts produces states the
application cannot actually reach, and then tests behaviour on them. Where a
test does insert directly — a few expense rows — it is because the state is a
precondition rather than the thing under test.

**Lazy loading is off.** A missing eager load fails the test rather than
quietly issuing a query per row.

## The demo seed as a test

`database/seeders/DemoSeeder.php` builds a full portfolio through the real
services, and `DemoSeederTest` runs it. That is deliberate: the seeder is the
only thing in the repository that exercises a long, realistic sequence of
operations end to end — publish four listings, take fifteen bookings across
three months, charge them, clean them, message the guests, bill the costs, and
close the month with owner statements and a payout.

It has already earned its place. Building it surfaced two genuine bugs — a
service filling a model with an argument it also consumed, and the framework's
placeholder seeder referencing a class this application does not have — and it
refused to publish a listing without a photograph, which is the rule working.

## What is not covered

Stated plainly rather than left to be discovered:

- **No frontend unit tests.** The SPA is typechecked and built in CI; component
  behaviour is not tested. The screens are thin over the API, so the risk is
  concentrated in the API, which is tested — but this is a gap.
- **No browser tests.** Nothing exercises the interface end to end.
- **No load testing.** The locking strategy is argued for, and tested for
  correctness, but not measured under contention.
- **Real provider integrations are untested by definition** — there are none.
  What is tested is that the abstraction is honest about that.

## Continuous integration

`.github/workflows/ci.yml` runs three jobs on every push:

- **backend** — PHP 8.4 against PostgreSQL 16 and Redis; Pint, then the suite.
- **frontend** — `npm ci`, typecheck, build.
- **migrations** — migrate from an empty database, seed the demo through the
  real services, then roll every migration back. A migration that only ever runs
  against a database somebody already migrated is a migration nobody has tested.
