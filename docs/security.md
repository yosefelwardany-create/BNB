# Security

How tenants are kept apart, how access is decided, what is encrypted, and what
is deliberately impossible.

## Tenant isolation

This is the control everything else rests on. A leak here is not a bug, it is a
breach.

**Every tenant-owned table carries `organization_id`**, with a foreign key.

**`TenantContext`** is a request-scoped singleton holding the active
organization. It is set by middleware from the `X-Organization` header after
checking that the authenticated user actually has an active membership there —
never inferred, never defaulted to "the first one".

**`BelongsToOrganization`** adds a global scope that filters every query on the
model and stamps `organization_id` on every insert. A model using the trait has
no unscoped read path except the explicit `withoutGlobalScope('organization')`,
which appears in a handful of audited places: provisioning (the organization
does not exist yet), platform administration, and the tenant resolver itself.

**API keys name their own organization.** For a key-authenticated request the
`X-Organization` header is ignored entirely — letting a key select an
organization by header would turn a credential scoped to one tenant into a
credential for all of them.

**`tests/Security/TenantIsolationTest.php`** exists to keep this true rather
than merely intended. It creates two organizations with overlapping data and
asserts that every list, show, update and delete path returns 404 across the
boundary.

404, not 403: telling somebody that a record exists but is not theirs confirms
its existence, which is itself a disclosure.

## Authentication

Sessions and API tokens are Laravel Sanctum. Passwords are bcrypt.

- **Login throttling** — 5 to 30 attempts per minute depending on the endpoint,
  tightest on password reset and registration.
- **`login_histories`** records every attempt, successful or not, with the
  address and user agent. An account takeover is investigated from this table.
- **Email verification** and **password reset** use signed, expiring URLs.
- **MFA is modelled but not yet enforced.** The user record carries
  `mfa_enabled`, an encrypted secret and recovery codes, and the schema is
  ready; the login challenge itself is not built. Setting the flag today
  changes nothing about how a session is established. It is on the roadmap,
  and until it ships this is a gap rather than a feature.
- **A revoked token fails closed.** The client is told to sign in again rather
  than being left on a screen that has silently stopped working.

## Authorisation

Three layers, and all three run on the server. Hiding a button in the interface
is a courtesy, never a control.

**Permissions** — 107 of them, in a registry synced by `permissions:sync`.
Granular by design: `channels.manage`, `channels.map` and `channels.sync` are
three different powers, and the middle one is the most expensive mistake
available in distribution.

**Roles** — eleven system roles from super-admin to read-only, plus whatever an
organization defines. A system role has `organization_id = null` and is never
edited; an organization customising one gets a copy, so a later change to the
registry cannot silently widen somebody's access.

**Policies** — per record, discovered by convention. Every model that can be
addressed by id has one. An owner may read their own statement and not somebody
else's; that is a policy question, not a permission question, and conflating the
two is how an owner ends up reading a neighbour's finances.

Beyond those, a membership may be **restricted to specific properties**
(`membership_property`), which filters what that seat can see at the query level.

### The permission cache

Effective permissions are cached per membership. The cache key includes an
organization version counter, and `AccessControl::flushOrganization()` bumps it.

That counter was added because of a real bug: the key was originally the
membership's `updated_at`, which a *role* edit does not touch. Revoking a
permission from a role therefore had no effect until something unrelated
happened to touch the membership row. The fix is in
`tests/Feature/Users/AccessControlTest.php`.

## Secrets

Nothing that can be read back is a secret.

| Secret | Storage | Readable? |
|---|---|---|
| Passwords | bcrypt hash | Never |
| API keys | SHA-256 hash, plus a 12-character prefix in clear | Shown once, at creation |
| Webhook signing secrets | Encrypted | Shown once, then only rotatable |
| Channel credentials | Encrypted, `$hidden` on the model | Reported only as present/absent |
| Owner bank details | Encrypted | Only ever returned masked |
| Access codes | Encrypted, with the last four in clear | Revealed through an audited endpoint |

The API key prefix is stored in clear on purpose. It is not the key and cannot
be used as one; it exists so that a human looking at four keys can tell which is
the one on the staging server.

An owner payout stores a **masked snapshot** of where the money went, as it
stood when it went — not a reference to the owner's current details. A payout
that went to an account they have since changed went to the old one, and saying
otherwise makes the record useless for tracing a missing payment.

## Server-side request forgery

The platform fetches URLs a user supplies in two places: webhook endpoints and
iCal imports. Both go through `App\Support\Validation\PublicHttpsUrl`:

- HTTPS only;
- loopback, RFC1918, link-local and CGNAT ranges refused;
- hosts that do not resolve are **allowed**, because they cannot be reached and
  refusing them would couple registration to DNS health.

The rule relaxes only where a test or local environment explicitly asks it to,
via `pms.webhooks.allow_local_endpoints`.

## Injection

**SQL** — Eloquent and the query builder throughout. The few `selectRaw` calls
are aggregate expressions over fixed column names with bound parameters.

**CSV** — `ReportExporter::neutralise()` prefixes values beginning `=`, `+`,
`-`, `@`, tab or carriage return with an apostrophe. A property named
`=cmd|'/c calc'!A0` is a property name in a spreadsheet, not a command. This
matters because the exports are the artefact most likely to be opened by
somebody outside the company.

**Files** — uploads are validated as images that actually decode, with
dimension bounds; they are stored on a private disk under a tenant-prefixed key
with a generated name, never the client's filename.

## Webhook signatures

Deliveries are signed `t=<unix>,v1=<hmac sha256>` over `"<timestamp>.<body>"`.

The timestamp is inside the signed material so that a captured delivery cannot
be replayed later: a receiver rejecting timestamps outside a tolerance window
gets replay protection from the same HMAC that gives it authenticity.
`WebhookSigner::verify()` uses `hash_equals`, because a comparison that returns
early is a timing oracle.

## Guest portal tokens

The portal is unauthenticated by design — the token *is* the credential — so the
token has to carry its own weight:

- 32 characters from a cryptographically secure source;
- an expiry;
- revocable by reissuing;
- rate limited **by token**, not by address.

That last one is the interesting choice. A family sharing one hotel wifi is
several callers from one address and must not throttle each other; somebody
guessing tokens is one address trying many tokens, which an address-keyed
limiter would barely slow. Keying by the token makes each guess cost a fresh
bucket, which is what makes brute-forcing pointless rather than merely slow.

The exact address is withheld from the portal until the booking is paid and
arrival is near. A portal link is forwardable, and a link that hands an address
to anyone who receives it is a security problem for the guest staying there.

## Audit

`audit_logs` records who changed what, with before and after values, for every
model using the `Auditable` trait. Any attribute the model marks as `$hidden`,
plus anything named in `config('audit.redacted')`, is replaced with
`[redacted]` rather than copied into the log — an audit trail that records the
old password is a second copy of the password.

The audit trail is append-only and is never deleted to solve a problem. Several
records elsewhere are immutable for the same reason: posted journal entries,
approved owner statements, sent webhook payloads, reservation status changes.

Anything that would normally be solved by deleting is solved by reversal,
versioning or archiving. Reservations are cancelled, properties archived,
channel accounts disconnected, ledger entries reversed. The history of a
business that handles other people's money has to survive its own mistakes.

## Rate limiting

| Surface | Limit |
|---|---|
| Login | 10/min |
| Registration, password reset | 5/min |
| Email verification resend | 6/min |
| Guest portal, reads | 60/min per token |
| Guest portal, writes | 10/min per token |
| API keys | Per-key, configurable, default 120/min |

## Transport and headers

HTTPS is enforced in production and URLs are generated as HTTPS. CORS is
restricted to the configured frontend origins; SPA cookie authentication is
limited to the domains named in `SANCTUM_STATEFUL_DOMAINS`.

## Reporting a vulnerability

There is no public deployment of this software to attack. If you find something
in the code, open an issue describing the class of problem and the file, without
a working exploit.
