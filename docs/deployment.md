# Deployment

The platform ships as a single Docker image that runs in three roles — web,
queue worker and scheduler — distinguished only by their start command. What is
tested is therefore what runs in every role.

```
                     ┌──────────────────┐
   HTTPS ───────────►│  habitat-api     │  FrankenPHP: API + admin SPA
                     └────────┬─────────┘
                              │
         ┌────────────────────┼────────────────────┐
         ▼                    ▼                    ▼
  ┌─────────────┐     ┌──────────────┐     ┌───────────────┐
  │ Neon        │     │    Redis     │     │ habitat-worker│  queues
  │ PostgreSQL  │     │  (Render)    │     └───────────────┘
  └─────────────┘     └──────────────┘             ▲
         ▲                    ▲                    │
         └────────────────────┴────────────────────┤
                                          ┌────────┴─────────┐
                                          │ habitat-scheduler│  cron, 1/min
                                          └──────────────────┘
```

The database is Neon rather than a Render database, so the blueprint does not
create one — you supply its connection string.

## Render

`render.yaml` in the repository root is a Blueprint that creates the web
service, the queue worker, the per-minute scheduler and Redis. The database
lives on Neon and is not created by the blueprint.

### 1. Create the Blueprint

In the Render dashboard: **New → Blueprint**, pick this repository and the
branch you want to deploy. Render reads `render.yaml` and shows you the four
resources it is about to create.

### 2. Supply `APP_KEY`

Render will prompt for `APP_KEY` because the blueprint marks it `sync: false`.
This is deliberate, and it is the one value you must not let a platform
generate for you on each deploy.

`APP_KEY` encrypts door codes, Wi-Fi passwords, guest identity documents and
owner bank details. If it changes, every one of those columns becomes
permanently unreadable — the data is still there, but nothing can decrypt it.
Generate one once and keep it:

```bash
php artisan key:generate --show
# base64:TFhA1r...=
```

Paste the whole value, including the `base64:` prefix. Use the **same** value
for all three services; they share a database.

### 3. Supply the Neon connection string

Render will also prompt for `DB_URL`, once per service.

In the Neon console, open your project → **Connect** and copy the connection
string. **Choose the pooled one** — its host contains `-pooler`:

```
postgresql://user:password@ep-something-a1b2c3d4-pooler.region.aws.neon.tech/dbname?sslmode=require
```

Paste it verbatim into all three services. Laravel parses it directly: the
`postgresql://` scheme maps to its `pgsql` driver, and `sslmode=require` is
carried through to the connection, which Neon requires.

Two things about Neon specifically:

**Use the pooled endpoint, not the direct one.** Three services connect to this
database, and each web request and each queue worker opens its own connection.
Neon's direct endpoint has a low connection ceiling that this will exhaust; the
pooler exists for exactly this shape of client. Nothing in the platform depends
on session-level state across statements, so transaction pooling is safe here —
the row locks that prevent double bookings are taken and released inside a
single transaction, which the pooler keeps on one backend.

**A suspended project takes a moment to wake.** Neon suspends an idle project
and resumes it on the next connection, which adds a few seconds. The
container's entrypoint waits up to a minute for the database before giving up,
so a cold start is absorbed rather than failing the deploy.

### 4. Deploy

Render builds the image and starts the services. The first boot runs the
migrations, seeds the permission catalogue and the amenity catalogue, and
builds the configuration caches. Watch `habitat-api`'s logs for:

```
[habitat] running migrations...
[habitat] synchronising reference data...
[habitat] ready.
```

### 5. Create the first organization

The API is live but has no tenants. Register the first one:

```bash
curl -X POST https://<your-service>.onrender.com/api/v1/auth/register \
  -H 'Content-Type: application/json' \
  -d '{
    "organization_name": "Your Company",
    "base_currency": "EUR",
    "timezone": "Europe/Lisbon",
    "first_name": "Your",
    "last_name": "Name",
    "email": "you@example.com",
    "password": "a-long-password-you-choose",
    "password_confirmation": "a-long-password-you-choose"
  }'
```

Then sign in at `https://<your-service>.onrender.com/app/`.

## What the blueprint sets up, and why

| Resource | Role |
|---|---|
| `habitat-api` | HTTP. Serves the API and the admin SPA. The only service that runs migrations. |
| `habitat-worker` | Queue workers: channel synchronisation, guest messaging, automation, webhook delivery, report generation. |
| `habitat-scheduler` | Invoked every minute; Laravel decides what is due. |
| `habitat-redis` | Cache, queues and the locks that prevent double bookings under concurrency. |
| Neon | PostgreSQL. Managed outside Render; supplied as `DB_URL`. |

A few choices worth knowing about:

**Only the web service migrates.** `RUN_MIGRATIONS` is `true` on the API and
`false` everywhere else, so a deploy cannot run migrations from three places at
once. The migration also runs with `--isolated`, which takes a cache lock, so
even two API instances cannot race.

**Redis has `maxmemoryPolicy: noeviction`.** The default policy evicts keys
under memory pressure. With queues in Redis that would silently discard jobs —
a guest's confirmation email, a channel availability push — so eviction is
turned off and the instance is sized to the workload instead.

**Queues are named and prioritised.** The worker drains
`messaging → default → automation → webhooks → channels → ai → reports → imports`
in that order, so a large channel resynchronisation cannot delay a guest's
booking confirmation.

**The scheduler runs every minute rather than nightly.** Anything time-based —
"send arrival instructions three days before check-in at 10am" — is evaluated
against each *property's* timezone inside the job. A nightly run at server
midnight would be the wrong hour for most of a portfolio.

## Before you take real bookings

The blueprint boots a working system, but three settings are deliberately left
on safe defaults that are not production-ready:

### Email

`MAIL_MAILER=log` writes emails to the log instead of sending them. Invitations,
password resets and guest confirmations will not arrive. Set a real transport:

```
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=bookings@yourcompany.com
MAIL_FROM_NAME=Your Company
```

### File storage

`FILESYSTEM_DISK=local` stores property photography and documents on the
container's disk, which Render replaces on every deploy. **Uploads will be lost.**
Point it at object storage:

```
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=...
AWS_BUCKET=...
```

Any S3-compatible service works; set `AWS_ENDPOINT` and
`AWS_USE_PATH_STYLE_ENDPOINT=true` for Cloudflare R2, Backblaze or MinIO.

### Payment, lock and AI providers

`PAYMENTS_DEFAULT_PROVIDER=mock`, `LOCKS_DEFAULT_PROVIDER=mock` and
`AI_DEFAULT_PROVIDER=null` select the bundled implementations.

The mock payment processor is a genuine working implementation — it keeps
state, refuses a capture larger than its authorization and a refund larger than
its capture, and is idempotent — but **no money moves**. It reports itself as
simulated and the interface labels it as such. Do not take real bookings
against it.

## Running it elsewhere

Nothing in the image is Render-specific. Any platform that can run a container
needs:

- `PORT` (or `SERVER_NAME=":<port>"`) for the web role.
- `APP_KEY`, `DB_URL`, `REDIS_URL`.
- The worker role started with `worker`, and `scheduler` invoked once a minute.

For Docker Compose, Fly, ECS or Kubernetes, the same three commands apply:

```bash
# web
docker run -e PORT=8080 -e APP_KEY=... -e DB_URL=... -e REDIS_URL=... habitat

# worker
docker run -e APP_KEY=... -e DB_URL=... -e REDIS_URL=... habitat worker

# scheduler (once a minute)
docker run -e APP_KEY=... -e DB_URL=... -e REDIS_URL=... habitat scheduler
```

## Scaling

- **Web**: stateless; add instances freely. Sessions are in Redis. Once you run
  more than one instance, set `RUN_MIGRATIONS=false` and run migrations as a
  deploy job instead.
- **Workers**: add instances to increase throughput. Jobs are idempotent where
  it matters (channel sync, payments, automation), so a job running twice after
  a worker restart does not produce a duplicate booking or a double charge.
- **Scheduler**: exactly one. The scheduled commands use `onOneServer()`, but
  running a second cron container is still wasted work.
- **Database**: the schema is indexed for the access patterns the product
  actually has — availability by property and date range, reservations by
  status and arrival, ledger lines by owner and statement. See
  [database.md](database.md).

## Troubleshooting a boot

The entrypoint narrates what it is doing, so the last line it printed tells you
how far the container got.

| Last line | Meaning |
|---|---|
| `APP_KEY is not set` | The blueprint prompt was skipped. Set it and redeploy; see §2. |
| `waiting for the database...` then a timeout | `DB_URL` is wrong, or Neon is unreachable from Render. Check the string parses — scheme `postgresql://`, `sslmode=require`, the `-pooler` host. |
| `running migrations...` then an error | A migration failed. The database is untouched past the failing one; fix forward, do not reset. |
| `ready.` then nothing | The server did not start. See below. |

**`exec: frankenphp: Operation not permitted`, exit 126.** The FrankenPHP base
image grants its binary the `cap_net_bind_service` file capability so it can
bind port 80 unprivileged. A host that sets the `no_new_privs` flag — Render
does — refuses to `execve` any file carrying capabilities, so the binary is
rejected before it runs. The image strips the capability at build time with
`setcap -r`; the service binds `$PORT` (10000), so nothing needs it. If you base
a derivative image on this one, keep that step.

The entrypoint checks for this before handing over, so a recurrence reports the
capability by name rather than a bare exit code.

## Health and observability

- `GET /up` is the health check. It returns 200 once the framework has booted.
- Logs go to stderr as JSON, which Render collects.
- Every request carries an `X-Request-Id` header, echoed in the response and
  recorded on every audit entry the request produced. A support ticket quoting
  one header value can be traced through every record it touched.

## A note on verification

The `Dockerfile` and `render.yaml` in this repository have not been built in
this environment: the sandbox blocks Docker Hub's image CDN, so no base image
can be pulled here. The PHP side of the boot sequence *has* been verified —
`config:cache`, `route:cache` and `event:cache` all run cleanly, which is the
part most likely to fail at deploy time. Treat the first Render build as the
real test of the image itself.
