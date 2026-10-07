# The managed service

How the platform is operated now, what changed to get here, and what is still
a business decision rather than a code decision. The isolated test
environment this was built in is described at the end.

## Two actors

| | Platform owner | Client |
|---|---|---|
| Identity | `users.is_platform_admin` | Membership holding the `owner` role (display name "Client") |
| Scope | Every client account, one at a time, named per request in `X-Organization` | Their own account, read-only |
| Interface | The owner workspace (every operational screen) plus **Accounts** | The client portal: Overview, Properties, Calendar, Money |
| Permissions | All, via the existing platform-admin bypasses | **None**. Reads go through `portal/owner/*`; `EnsureClientReadOnly` refuses every write |
| How they get there | `platform:grant-admin`, or another administrator on Accounts | Created with the account (Accounts → New client) or by self-registration |

No impersonation, no shared credentials: the owner's own token is used, and
the server scopes each request to the account named in the header.

## A client account

Every organization is a client account. Provisioning (`clients:provision`,
run on each boot, idempotent) ensures each one has:

- an **account-holder owner record** (`owners.is_account_holder`, one per
  organization), linked to the client's login;
- a blanket **management agreement**: 10% of revenue, on accommodation only,
  not on fees or taxes, **before** the channel's commission, dated from the
  organization's creation;
- a 100% **ownership row** for every property that has no ownership rows at
  all. Properties somebody already attributed are left exactly as they are.

Properties created later (API, manual adopt, Hostex auto-import) are attached
to the account holder by a listener on `PropertyCreated`, under the same rule.

Nothing converts an existing login's roles. `clients:convert-login
{organization} {email} --dry-run` does that one login at a time, refuses to
run until at least one platform administrator exists, and refuses to convert
a platform administrator.

## The client's money

`GET portal/owner/financials?from=&to=` (`ClientFinancials`):

- **Basis**: `reservation_nights.rate_amount` (gross accommodation per stay
  night, excluding cleaning fees and taxes), stays in a revenue status,
  attributed to the stay night, per property and per currency, scaled by the
  ownership share in force on that night.
- **Commission**: `ManagementAgreement::commissionFor()` on the aggregated
  per-property base with the agreement in force on each night. Money rounds
  half-up on minor units: 1,000.00 → 100.00 → 900.00; 10.05 → 1.01.
- **Revenue after commission** is a revenue figure and is labelled so,
  in the server's own sentence, on every screen.
- **Never summed across currencies.**
- **Flags, never zeros**: `incomplete_rates`, `incomplete_amounts`,
  `accounting_review`, `unresolved_refunds`, `cancellations_present`,
  `before_agreement`, `terms_changed_in_period`, `currency_mismatch`. A row
  with any flag is `is_final: false` and shown as such.

No ledger posting, no automatic collection, transfer or payout follows from
this. Statements and payouts are the existing workflow, unchanged.

## What was removed

Plans, trials, feature gates, usage caps, announcements, read-only support
sessions (impersonation), the subscription screen and the separate platform
console UI. The database tables remain for a later, separate cleanup
migration. There were no external billing subscriptions to cancel.

## Isolation guarantees added

- **Fail-closed tenancy on HTTP**: a scoped query before a tenant is bound
  throws instead of returning every organization's rows. Jobs and commands
  bind explicitly with `runAs`.
- **Echoed scope**: every scoped response carries `X-Organization`; the
  interface refuses a response for a different account than it asked for.
- **One query cache per account** in the interface, and a client that
  discards responses that arrive after the account changed.
- **Outbound kill switch** (`OUTBOUND_INTEGRATIONS_ENABLED=false`): Hostex
  writes, webhook deliveries and the HTTP AI provider are refused; reads
  continue. For test environments.

## Business decisions still open

1. **Which existing organizations are clients, and which logins are the
   owner's.** Nothing is converted until `platform:grant-admin` and
   `clients:convert-login` are run deliberately.
2. **Commission base**: 10% of gross accommodation (implemented) or after
   deducting the Airbnb host service fee (`deduct_channel_commission_first`
   on the agreement, editable on the Owners screen).
3. **Effective date**: the agreement starts at the organization's creation,
   so imported history is commissionable. Change `starts_on` to a go-live
   date to exclude earlier nights (they are then flagged `before_agreement`).
4. **Refunds and cancellations**: cancelled stays earn nothing; partial and
   host-initiated refunds are flagged, not netted, until a verified amount
   exists.
5. **Public sign-up**: `REGISTRATION_OPEN` creates client accounts only.
   Keep it open, or create every client from Accounts?
6. **Platform-administrator MFA**: `require_mfa_for_platform_admins` now
   applies to tenant routes too. Default off; recommended on once the owner
   has enrolled.
7. **Demo seeder in production**: `SEED_DEMO_DATA=false` recommended.

## Testing it locally with Docker

`docker-compose.test.yml` is a complete, isolated environment: PostgreSQL,
Redis and the application in containers, synthetic demo data, email to the
container log, mock payments and locks, the echo AI stub, and the outbound
kill switch on. It needs only Docker Desktop.

```bash
docker compose -f docker-compose.test.yml up --build        # http://localhost:8080/app/
docker compose -f docker-compose.test.yml --profile test run --rm test   # backend tests
docker compose -f docker-compose.test.yml down -v           # remove everything
```

Sign in as `platform@habitat.test` / `password` for the owner workspace.

## The isolated test environment this was built in

Nothing here touches the production platform, its database, or any real
Hostex account.

| | |
|---|---|
| Working repository | `/home/user/bnb-managed-service` (a copy; no git remote, so nothing can be pushed from it by accident) |
| Original repository | `/home/user/BNB`, untouched |
| Database | PostgreSQL `bnb_managed_test` (app), `bnb_managed_phpunit` (tests), user `bnb_test`, on `127.0.0.1:5432` |
| Cache, sessions, queues | Redis on `127.0.0.1:6380`, prefixes `bnb_managed_test` |
| Files | `FILESYSTEM_DISK=local` inside the copy |
| Email | `MAIL_MAILER=log` |
| Payments, locks, AI | `mock`, `mock`, `echo` |
| Hostex | No credentials. `OUTBOUND_INTEGRATIONS_ENABLED=false` refuses writes; `CHANNELS_SYNC_ENABLED=false` stops the scheduled pulls |
| Webhooks, AI bots | Refused by the same switch |
| Registration | `REGISTRATION_OPEN=true` for creating synthetic clients |
| URL | `http://localhost:8080` (not deployed; see below) |

The container this was built in has PHP 8.3 and the application requires PHP
8.4, so the backend test suite, the migrations and the application server
could not be run here. The frontend suite, lint, type-check and production
build ran clean. The PHP files were syntax-checked.
