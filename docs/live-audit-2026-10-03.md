# Live audit — 3 October 2026

Scope: authenticated Habitat UI, its read responses, import pipeline, agent provider resolution, calendar visibility, and automated backend/frontend coverage. The operator explicitly prohibited writes to Hostex. No outbound Hostex sync, guest messages, live booking changes, rate changes, payments, or account permissions are part of this audit.

## Evidence and repairs

| Finding | Repair / verification |
| --- | --- |
| All source photos were rejected. The cover is a JSON object; each gallery entry has `id`, `order`, `caption`, `original_url`, `small_url`, `large_url`, `extra_large_url`, `extra_extra_large_url`. These are sizes of one photograph. | Decode the bounded JSON cover and select the verified `original_url`; retain generic unambiguous URL handling for other connections. Synthetic fixtures reproduce the structure without copying live URLs, captions, or identifiers. |
| The property had no local price, but an archived listing retained a USD override and blocked the CAD source import. | Exclude archived listing overrides from the active-property currency guard. Preserve archived amounts/currency and snapshot inherited old fees before changing the property denomination. Active overrides remain protected. |
| The existing local cover image cannot load. | Fall back through the remaining gallery images, preserving the user's stored photo. |
| Alex inherits the configured live Claude provider, but the property card reported no bot. | Use the same provider resolver for the card, agent settings, and answer path; refresh property queries after saving the brief. |
| The agent avatar points to an HTML page instead of an image. | Fall back to the agent's initial when loading fails. |
| A real imported reservation disappeared from the calendar because its listing was archived. | Include archived listings with occupying reservations in the requested window, still labeled archived; do not restore or publish them. |
| Dashboard/calendar used the UTC day while the visible clock used the organization timezone. | Use the organization day and calendar-day arithmetic; dashboard counts stay within its seven visible days. |
| Dashboard claimed every booking was paid when no balance was recorded. | Say no outstanding balance is recorded and distinguish unavailable imported payment information. |
| Import-only connections exposed Push controls. | Disable outbound controls when both outbound switches are off; enforce those switches at the server push boundary. |
| CI tests changed meaning late at night because fixture dates used different timezones or crossed midnight. | Give date-based fixtures the same explicit UTC timezone and fix the board scenario to the morning. |
| A listing form defined Beds twice. | Remove the duplicate field. |

## Live checks

Before deployment, the repaired reservation already had an actual guest name, a phone when supplied, one stay, a populated night count, a channel confirmation reference, CAD accommodation amounts, and source order details. Guest-name presence was verified in the Guests UI without recording its value. No source email was present; it is not fabricated.

Dashboard, Properties, Agents, Guests, Calendar, Channels, Operations, Owners, Reviews, Inbox, Financials, Revenue, Reports, Subscription, and Settings were inspected. Owners, reviews and inbox showed empty states consistent with their responses. Messages are disabled for this Hostex connection. No live CRUD or financial transactions were performed to manufacture test data.

Revenue/occupancy reports currently refuse an unverified aggregate: the existing organization base currency is USD, the imported stay is CAD, and legacy posted revenue may require reconciliation. Individual source amounts remain available. Changing a label or inventing an exchange rate would not repair that accounting discrepancy.

## Limits

Hostex's available property/listing responses do not supply stable description, bedroom, bed, bathroom, amenity, timezone, or house-rule fields for this connection. Local values are retained. The property's local timezone and incomplete capacity/address fields still require verified property information before local publication. A source listing being live does not authorize publishing the local draft.

Recorded order collections are not proof of guest settlement or host payout. Missing email, payout details, and unverified exchange rates remain unavailable. Automated tests use an isolated local database and synthetic HTTP responses; production verification is recorded separately after deployment.

## Automated checks

- Full backend suite before the final push-boundary/caption changes: 898 tests, 3,223 assertions passed.
- Affected channel suites after those final changes: 70 tests, 330 assertions passed, including no outbound HTTP call when a manual push is attempted on an import-only Hostex account.
- Full frontend suite: 288 tests passed. Build/TypeScript, ESLint, Pint and whitespace checks passed.
- Existing nonblocking warning: the frontend application chunk exceeds 500 kB before compression.
