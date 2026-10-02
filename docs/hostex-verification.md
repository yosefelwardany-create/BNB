# Hostex repair verification

Branch: `codex/hostex-sync-repair`. Verification completed: 3 October 2026 (Cairo).

The implementation and repeatable repair procedure are in [hostex-sync.md](hostex-sync.md), including the endpoint-to-field mapping and links to the official Hostex documentation. This report covers automated verification. GitHub and Render track deployment status separately; production Hostex data repair is not established by these tests.

## Environment and results

Checks used PHP 8.4.26, PostgreSQL 16.15 with a dedicated local test database in UTC, and dependencies installed from the repository lockfiles. Test requests use synthetic fixtures; real credentials and guest information were not used.

| Check | Result |
| --- | --- |
| Full backend suite, `php artisan test --compact` | 883 passed, 3,126 assertions, about 5 minutes 35 seconds. |
| Focused Hostex repair, adoption, guest portal and property-agent regression tests | 45 passed, 222 assertions. |
| Deployment compatibility: Hostex repair and Pull tests after replacing session locks with pooled-database leases | 36 passed, 178 assertions. Full-repository Pint also passed. |
| Full frontend suite, `npm run test -- --run` | 31 files, 281 tests passed. |
| Frontend lint, `npm run lint` | Passed. |
| TypeScript and production bundle, `npm run build` | Passed; final artifact rebuilt after the last display changes. |
| PHP formatting, Pint on changed and new PHP files | Passed. |
| `git diff --check` | Passed. |

The migration was exercised against PostgreSQL by the feature suite. Legacy-record repair is tested by seeding an order-keyed reservation and verifying that repair preserves its reservation ID, guest ID and internal reference while assigning the correct stay identity.

## Behaviors covered

- Mapping a discovered Hostex property into the exact existing local property, including API hydration, photos and source prices.
- CAD amounts, independently labeled USD amounts, JPY/BHD minor units, real zero and unknown currency/amounts.
- Separate base/calendar/accommodation/derived nightly/stay/order amounts; no invented guest payments or host payouts.
- Separate internal, Hostex stay/order and Airbnb references; guest-name repair without replacing a known name on partial responses; additional guest details without identity documents and unknown counts without invented defaults.
- Stable property, reservation, guest, photo and transaction identities across repeated pulls; signed image URL renewal; broken-image UI fallback.
- Missing source fields, local overrides, changed source currencies, malformed rows, later-page errors and visible partial results.
- Cancellation/reactivation, account/tenant isolation, concurrent-pull locking and preservation of paused mappings.
- Preservation of posted nightly revenue with review flags for mismatches; mixed-currency revenue reports cannot silently relabel amounts.
- Source financial API isolation; unknown payment does not become “paid” or release private guest-arrival information.
- Property, reservation and source-details frontend rendering, with additional booking-rule labels and documented date coverage.

## Remaining verification limits

**Live synchronization was not verified.** No connected production deployment, accessible Hostex credentials or sanitized real response was available in this workspace. Endpoint schemas were checked against current official documentation, and automated requests were mocked. A deployment with the existing connection must perform the property/source comparison described in steps 3–8 of the repair guide.

Two existing nonblocking frontend warnings remain: the production bundle exceeds Vite's 500 kB chunk recommendation, and listing-editor tests report a duplicate `beds` React key. They did not fail the build or tests.

Hostex listing metadata varies by connection. Undocumented description/capacity/amenity fields, richer gallery objects, authenticated-only photos and verified guest-payment/payout meanings remain unavailable unless supported by a verified connection-specific schema. A successful fixture run does not establish that a particular Hostex account grants every endpoint.
