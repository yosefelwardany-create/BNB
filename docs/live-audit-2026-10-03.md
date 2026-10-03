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
| Calendar's Today button still used UTC, and its cells advanced through browser-local daylight-saving time. | Use the organization date for Today and UTC calendar dates for cell keys, headings and occupied nights. Verified with the browser timezone set to America/New_York across the March clock change. |
| Dashboard claimed every booking was paid when no balance was recorded. | Say no outstanding balance is recorded and distinguish unavailable imported payment information. |
| Import-only connections exposed Push controls. | Disable outbound controls when both outbound switches are off; enforce those switches at the server push boundary. |
| The health header described historical retryable failure records as waiting work even after a later successful pull. | Label them earlier retryable failures; retain the audit history and show the current pull outcome separately. |
| CI tests changed meaning late at night because fixture dates used different timezones or crossed midnight. | Give date-based fixtures the same explicit UTC timezone and fix the board scenario to the morning. |
| A listing form defined Beds twice. | Remove the duplicate field. |
| The agent's booking selector still used the generated internal code. | Prefer the imported channel reference, consistent with Reservations. |
| A live draft-only Claude check returned HTTP 401: the configured API key is invalid. The UI exposed the SDK's raw response. | Present a short, actionable authentication error without upstream response bodies; cover authentication, permission, model, rate-limit and outage responses. The account owner must replace the key securely in Render. Provider selection is repaired, but successful AI replies are not verified. |

## Live checks

The deployed repair's read-only pull completed at **03:00:30 Cairo** on 3 October: listings 1 updated / 0 failed; properties 77 photos / 366 calendar days / 0 failed; reservations 1 updated / 0 failed; transactions 1 updated / 0 failed. Messages remained disabled. The property card and normal edit field both show **CAD 60.00**. Its image loaded successfully, Alex's card resolves Claude, and the chat box is available. The calendar now shows the occupied archived listing and starts on the organization-local date.

A repeat pull started at **03:13:03 Cairo** and completed with the same successful counts and zero failures. Property, booking, guest and all photo IDs stayed unchanged; the gallery remains 78 records (77 imported photos plus the preserved local image). The calendar's occupied cells contain the actual guest name in their tooltips. Outbound rate, availability, reservation-export and message flags remain off.

Before deployment, the repaired reservation already had an actual guest name, a phone when supplied, one stay, a populated night count, a channel confirmation reference, CAD accommodation amounts, and source order details. Guest-name presence was verified in the Guests UI without recording its value. No source email was present; it is not fabricated.

Dashboard, Properties, Agents, Guests, Calendar, Channels, Operations, Owners, Reviews, Inbox, Financials, Revenue, Reports, Subscription, and Settings were inspected. Owners, reviews and inbox showed empty states consistent with their responses. Messages are disabled for this Hostex connection. No live CRUD or financial transactions were performed to manufacture test data.

Revenue/occupancy reports currently refuse an unverified aggregate: the existing organization base currency is USD, the imported stay is CAD, and legacy posted revenue may require reconciliation. Individual source amounts remain available. Changing a label or inventing an exchange rate would not repair that accounting discrepancy.

## Limits

Hostex's available property/listing responses do not supply stable description, bedroom, bed, bathroom, amenity, timezone, or house-rule fields for this connection. Local values are retained. The property's local timezone and incomplete capacity/address fields still require verified property information before local publication. A source listing being live does not authorize publishing the local draft.

Recorded order collections are not proof of guest settlement or host payout. Missing email, payout details, and unverified exchange rates remain unavailable. Automated tests use an isolated local database and synthetic HTTP responses; production verification is recorded separately after deployment.

## Automatic import follow-up

The `edd6d57` deployment passed 922 backend tests (3,311 assertions), 294 frontend
tests, migration/demo checks, and all three Render service deployments. The
existing Hostex connection now has automatic inbound draft creation enabled;
outbound rate and availability switches remain off.

The live pull completed at **11:40:09 Cairo** with zero failures: 77 source
photos, 366 dated prices, 368 master availability days including 248 unavailable
days, one reservation updated and one transaction updated. The existing property
now has America/Toronto and separate Canadian address fields. CAD 60 is retained.
The visible 30-day calendar shows four occupied cells and 26 source-unavailable
cells, including the archived listing without activating it.

The user replaced the Claude key. A subsequent internal property-chat request
returned HTTP 200 and correctly answered America/Toronto and 60 CAD. This
supersedes the earlier 401 finding; no guest message or Hostex write was sent.

Live metadata inspection revealed additional structured description, room,
capacity and amenity fields. The `c1cacb8` follow-up passed full CI and another
zero-failure live pull; all 49 supplied amenities are checked in the native
editor. The source person capacity is one, which agrees with the imported value.
Source room entries contain nine identical room-number-one records with empty
bed arrays. They cannot establish nine bedrooms or zero beds and are not counted.

The final field-mapping deployment (`2da33ea`) passed 924 backend tests (3,328
assertions) and 294 frontend tests. Its live pull completed successfully after
starting at **11:58:39 Cairo**. Native fields contain the 341-character source
description, two bathrooms and the five supplied house-rule flags. All 49
amenities remain selected; property and photo IDs are unchanged. Only bedroom
and bed counts remain in the import-review list. No local activation or Hostex
write occurred.

The final narrow-screen inspection also exposed an existing gallery layout
problem: 78 images expanded the dialog's implicit grid track and moved the
editor outside the viewport. The record dialog now mounts outside the animated
page, and its grid/body width is constrained while the photo strip scrolls.

## Earlier automated checks

- Full backend suite before the final push-boundary/caption changes: 898 tests, 3,223 assertions passed.
- Affected channel suites after those final changes: 70 tests, 330 assertions passed, including no outbound HTTP call when a manual push is attempted on an import-only Hostex account.
- Full frontend suite: 288 tests passed. Build/TypeScript, ESLint, Pint and whitespace checks passed.
- Existing nonblocking warning: the frontend application chunk exceeds 500 kB before compression.
- GitHub release checks for `606800d`: all jobs passed, including **899 backend tests / 3,231 assertions**. Render API, worker and bot bridge deployments succeeded; `/up` returned 200 and the new frontend bundle was verified.
- Follow-up checks: 41 affected backend tests / 171 assertions and 25 agent UI tests passed; frontend build, lint, PHP formatting and whitespace checks passed. These include safe provider errors and the channel booking reference.
- Calendar follow-up: 10 tests passed under `TZ=America/New_York`, including a DST-crossing stay and Today at the Cairo/UTC date boundary.
