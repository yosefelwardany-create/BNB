# Integrations

The single most important thing in this document: **nothing here pretends a
real integration is active when it is not.**

No commercial partner agreements exist for this platform, and no third-party API
credentials ship with it. Rather than stub out the integration points and leave
buttons that do nothing, every one of them is a proper provider abstraction with
a working local implementation behind it. The whole path — request, response,
error handling, retry, ledger posting, audit trail — runs for real against a
local stand-in.

And every one of those stand-ins says so, in a field the API returns and the
interface displays.

## The pattern

Each integration point is a contract in `app/Domain/Integrations/Contracts/`,
with implementations in `Providers/` and a registry in `Registries/`:

```
ChannelAdapterInterface   → DirectBookingAdapter, IcalChannelAdapter, SimulatedOtaAdapter
PaymentProviderInterface  → MockPaymentProvider
LockProviderInterface     → MockLockProvider
MessageTransportInterface → EmailTransport, LocalTransport
AIProviderInterface       → EchoAIProvider, NullAIProvider
StorageDriverInterface    → Laravel's filesystem disks
```

Every contract declares two methods that exist solely for honesty:

```php
public function isLive(): bool;
public function simulationReason(): ?string;
```

`simulationReason()` is written for a human and says what is missing and how to
fix it — "the mailer is set to `log`, which records messages instead of sending
them; set `MAIL_MAILER` to a real transport" — not "not implemented".

Adding a real provider means writing one class, registering it, and setting an
environment variable. No calling code changes, because no calling code knows
which provider it has.

## Channels

`ChannelAdapterRegistry::KNOWN_CHANNELS` lists what the platform can connect to:
Airbnb, Booking.com, Vrbo, Expedia, Google Vacation Rentals, iCal feeds and
direct bookings.

| Channel | Adapter | Live? |
|---|---|---|
| Direct | `DirectBookingAdapter` | Yes — our own booking engine, no external system |
| iCal | `IcalChannelAdapter` | Yes — a real fetch and parse of a real URL |
| Airbnb, Booking.com, Vrbo, Expedia, Google | `SimulatedOtaAdapter` | **No** |

`GET /api/v1/channels/available` returns this, per channel, and is deliberately
not filtered down to the live ones. An operator choosing a channel is entitled
to know that connecting Airbnb here exercises the whole synchronisation path
against a local simulation rather than against Airbnb. The Channels screen shows
a **Simulated** chip on the connection itself, with the reason underneath.

The simulated adapter is not a no-op. It keeps state, accepts and rejects rate
pushes, produces reservations to import, and fails in the ways a real channel
fails — so the synchronisation logic, the dirty-flag handling, the retry
policy and the error surfaces are all genuinely exercised. Every `sync_jobs` row
it produces carries `is_simulated = true`, and the sync health endpoint reports
simulated counts separately from real ones.

Two commercial facts are modelled per account and matter more than the adapter:

- **`collects_payment`** — whether the channel takes the guest's money. Airbnb
  does and remits later, so a booking from there is a receivable; Booking.com's
  usual model is that the guest pays the property and the channel invoices its
  commission. Getting this wrong misstates the bank balance by every booking
  that channel sends.
- **`commission_basis_points`** — integer basis points, not a percentage, so a
  commission cannot drift by a rounding.

## Payments

`MockPaymentProvider` is the only provider, and `isLive()` is false.

It is a working payment system: authorisations are held and expire, captures are
bounded by what was authorised, refunds are bounded by what was captured, and
declines are produced deterministically so the failure paths can be tested. Its
state lives in `simulated_payment_transactions`.

Every payment it processes is stored with `is_simulated = true`. The API returns
it, and the Financials screen shows a **Simulated** chip. A second, independent
flag is shown beside it:

| Flags | Means |
|---|---|
| `is_simulated: true` | No real money moved anywhere |
| `is_collected_by_us: false` | Real money moved, into somebody else's account |

Both are shown, always, because they are different facts. A simulated payment
moved nothing; a channel-collected one moved real money that never reached this
bank account. The ledger treats them differently too — the second posts a
receivable rather than cash.

`recordExternalPayment()` exists precisely so that money collected elsewhere is
never routed through a "provider". Simulating a capture that never happened
would be a lie, and it would fail outright for a "provider" like Airbnb, which
is a distribution channel and not a processor.

Configure a real provider with `PAYMENTS_DEFAULT_PROVIDER`.

## Messaging

Three transports:

- **`EmailTransport`** — genuinely sends, through Laravel's mail stack.
  `isLive()` is *derived from the configured mailer* rather than hard-coded:
  with `MAIL_MAILER=log` or `array` it reports itself as not live and explains
  why. So the same code is honest in development and in production without
  anybody remembering to change a flag.
- **`ChannelThreadTransport`** — replies into the channel's own inbox, through
  the adapter that owns the connection. A guest who wrote through Airbnb
  expects the answer in Airbnb: most OTAs forward nothing, several relay
  through an alias that expires, and a reply outside the thread is one the
  channel's own support cannot see when the guest disputes what they were told.

  Four things must hold before a message goes into a thread — the conversation
  carries a thread id, a connected account still exists, the channel carries
  messages at all, and the listing is mapped — and a refusal names which one
  failed, because "could not address this recipient" sends somebody looking at
  the guest's email address when the answer is a missing mapping. Whether the
  adapter behind it is real is a separate question, answered per message on the
  delivery record.
- **`LocalTransport`** — records deliveries to `simulated_message_deliveries`
  and always reports `isLive() === false`.

Messages carry their delivery result, and `delivery.simulated` travels to the
inbox, where the bubble shows a **Simulated delivery** chip. An operator reading
a thread can tell what a guest actually received from what was merely recorded.

Configure with `MESSAGING_DEFAULT_TRANSPORT` and `MAIL_MAILER`.

## Smart locks

`MockLockProvider` keeps real state: locks have battery levels that drain as
codes are issued, codes occupy a finite number of slots, and a lock refuses a
code whose window has already passed. That is enough to exercise the scheduling,
revocation and low-battery workflows without hardware.

`AccessCodeManager` never marks a code active unless the provider accepted it.
A code row is written *before* the attempt, so a provider that times out leaves
a record explaining what was tried rather than nothing at all.

Two fields carry the truth to the interface: `is_simulated` on the code and
`opens_a_real_door` where a code is displayed. This is the flag with the highest
consequence in the product — a guest given a code that opens nothing is stranded
outside a building at midnight.

Configure with `LOCKS_DEFAULT_PROVIDER`.

## AI

- **`EchoAIProvider`** — produces a deterministic draft from the prompt and the
  conversation, so the drafting workflow, the review step and the audit trail
  all work. `isLive()` is false.
- **`NullAIProvider`** — refuses, for deployments that want the feature off.

Any message a model drafted is flagged `is_ai_generated`, and the inbox shows an
**AI drafted** chip. Nothing is sent to a guest without a person approving it.

Configure with `AI_DEFAULT_PROVIDER`.

## Outbound webhooks

The one integration that is fully real, because it requires nothing from a
partner: you supply the URL, and this platform signs and delivers to it. See
[api.md](api.md#webhooks) for the signature scheme.

Endpoint URLs are validated by `App\Support\Validation\PublicHttpsUrl`: HTTPS
only, and no address inside the infrastructure — loopback, RFC1918, link-local
and CGNAT ranges are refused. A webhook URL is supplied by a user and fetched by
our server, which is the exact shape of a server-side request forgery.

Hosts that do not resolve are allowed. They cannot be reached, so they are not a
forgery risk, and refusing them would couple endpoint registration to DNS health
— an outage would start rejecting perfectly good configuration.

## Exchange rates

`StoredRateProvider` reads rates from `exchange_rates` by pair and date, and
derives the inverse rather than storing both halves, which would eventually
drift apart. It is genuinely live against the table and genuinely *not*
subscribed to a market feed, and the index endpoint says so.

The behaviour that matters is the refusal. A rate that is not known returns
null, and a conversion against it fails with a message naming the pair and the
date. Falling back to 1.0 would be the worst available answer: plausible,
silently wrong, and wrong by exactly the amount nobody notices until an audit.

## Identity verification

`LocalIdentityVerifier` performs the checks it honestly can — expiry, document
number shape, minimum age, passport format — and **never returns verified**. It
cannot confirm the document exists or that the person presenting it is its
holder, which is the whole of what a real provider is for, so everything that
passes comes back as `manual_review` and only a named person recording a reason
can mark a guest verified.

Four outcomes rather than a boolean, because a system that cannot say "we could
not tell" will be made to say "verified" instead. `unavailable` is kept
distinct from `rejected` so an outage of ours is never recorded as a failure of
the guest's.

## Storage

Files (property photos, task photos, guest documents, report runs) go to a
Laravel disk under a tenant-prefixed key. The disk is private; where it cannot produce signed URLs,
the application streams the file after running the authorisation check. Nothing
is served from a guessable public path.

## Summary

| Integration | Real today | Stand-in | Flag surfaced |
|---|---|---|---|
| Direct booking | Yes | — | — |
| iCal | Yes | — | — |
| OTA channels | No | `SimulatedOtaAdapter` | `is_live`, `simulation_reason`, `sync_jobs.is_simulated` |
| Payments | No | `MockPaymentProvider` | `is_simulated`, `is_collected_by_us` |
| Email | Depends on `MAIL_MAILER` | `LocalTransport` | `delivery.simulated` |
| Smart locks | No | `MockLockProvider` | `is_simulated`, `opens_a_real_door` |
| AI drafting | No | `EchoAIProvider` | `is_ai_generated` |
| Channel replies | No | `SimulatedOtaAdapter` | `delivery.simulated`, `delivery.reason` |
| Exchange rates | Rates you record | No market feed | `is_live`, `simulation_reason` |
| Identity verification | No | `LocalIdentityVerifier` | `is_simulated`, `requires_a_person` |
| Webhooks | Yes | — | — |
| Report webhooks | Yes | — | — |
| File storage | Yes | — | — |

`tests/Feature/Platform/DemoSeederTest.php` asserts that no record in the demo
portfolio violates any row of this table.
